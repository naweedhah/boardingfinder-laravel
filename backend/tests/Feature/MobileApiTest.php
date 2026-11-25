<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Jobs\SendPushNotification;
use App\Models\Chat;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Services\ExpoPushClient;
use App\Services\NotificationService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    private const PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    private function bearer(User $user): string
    {
        Auth::forgetGuards();

        return $user->createToken('mobile:test')->plainTextToken;
    }

    #[Test]
    public function mobile_login_returns_a_bearer_token_instead_of_a_cookie(): void
    {
        User::factory()->create(['username' => 'kasun', 'password' => 'password123']);

        $response = $this->postJson('/api/auth/mobile/login', [
            'username' => 'kasun', 'password' => 'password123', 'deviceName' => 'Pixel 8',
        ])->assertOk()
            ->assertJsonStructure(['token', 'expiresAt', 'user' => ['id', 'username']])
            ->assertJsonMissingPath('user.password');

        $this->assertNull($response->getCookie('token', false));
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'mobile:Pixel 8']);

        Auth::forgetGuards();
        $this->withToken($response->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('username', 'kasun');
    }

    #[Test]
    public function mobile_login_validates_and_rejects_bad_credentials(): void
    {
        User::factory()->create(['username' => 'kasun', 'password' => 'password123']);

        $this->postJson('/api/auth/mobile/login', ['username' => 'kasun', 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Device name is required (max 60 characters).');

        $this->postJson('/api/auth/mobile/login', ['username' => 'kasun', 'password' => 'wrong-password', 'deviceName' => 'x'])
            ->assertUnauthorized();
    }

    #[Test]
    public function suspended_accounts_cannot_get_a_mobile_token(): void
    {
        User::factory()->create(['username' => 'banned', 'password' => 'password123', 'is_active' => false]);

        $this->postJson('/api/auth/mobile/login', ['username' => 'banned', 'password' => 'password123', 'deviceName' => 'x'])
            ->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function bearer_tokens_work_on_existing_protected_routes(): void
    {
        $this->getJson('/api/test/should-be-logged-in')->assertUnauthorized();

        $this->withToken($this->bearer(User::factory()->create()))
            ->getJson('/api/test/should-be-logged-in')
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken('1|not-a-real-token')
            ->getJson('/api/test/should-be-logged-in')
            ->assertForbidden();
    }

    #[Test]
    public function expired_bearer_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile:old', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();
    }

    #[Test]
    public function mobile_logout_revokes_only_this_devices_token_and_push_token(): void
    {
        $user = User::factory()->create();
        $phone = $this->bearer($user);
        $tablet = $user->createToken('mobile:tablet')->plainTextToken;
        DevicePushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[phone]', 'platform' => 'android']);
        DevicePushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[tablet]', 'platform' => 'ios']);

        $this->withToken($phone)
            ->postJson('/api/auth/mobile/logout', ['pushToken' => 'ExponentPushToken[phone]'])
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($phone)->getJson('/api/auth/me')->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($tablet)->getJson('/api/auth/me')->assertOk();
        $this->assertDatabaseMissing('device_push_tokens', ['token' => 'ExponentPushToken[phone]']);
        $this->assertDatabaseHas('device_push_tokens', ['token' => 'ExponentPushToken[tablet]']);
    }

    #[Test]
    public function a_device_registers_its_push_token_once_and_it_follows_the_signed_in_user(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $payload = ['token' => 'ExponentPushToken[abc-123]', 'platform' => 'android'];

        $this->withToken($this->bearer($first))->postJson('/api/users/push-tokens', $payload)->assertCreated();
        $this->withToken($this->bearer($first))->postJson('/api/users/push-tokens', $payload)->assertOk();
        $this->withToken($this->bearer($second))->postJson('/api/users/push-tokens', $payload)->assertOk();

        $this->assertDatabaseCount('device_push_tokens', 1);
        $this->assertDatabaseHas('device_push_tokens', ['token' => 'ExponentPushToken[abc-123]', 'user_id' => $second->id]);
    }

    #[Test]
    public function push_tokens_are_validated(): void
    {
        $this->withToken($this->bearer(User::factory()->create()))
            ->postJson('/api/users/push-tokens', ['token' => 'not-a-token', 'platform' => 'windows'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['A valid Expo push token is required.', 'Platform must be ios or android.']);
    }

    #[Test]
    public function notifications_are_pushed_to_every_device_with_deep_link_data(): void
    {
        Http::fake([self::PUSH_URL => Http::response(['data' => [['status' => 'ok', 'id' => 't1'], ['status' => 'ok', 'id' => 't2']]])]);
        $user = User::factory()->create();
        DevicePushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[a]', 'platform' => 'android']);
        DevicePushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[b]', 'platform' => 'ios']);

        app(NotificationService::class)->create(
            userId: $user->id, type: NotificationType::BookingApproved, title: 'Booking approved',
            message: 'Your booking was approved', metadata: ['postId' => 'p1'],
        );

        Http::assertSent(function (HttpRequest $request) {
            $messages = json_decode($request->body(), true);

            return $request->url() === self::PUSH_URL
                && count($messages) === 2
                && $messages[0]['title'] === 'Booking approved'
                && $messages[0]['data']['type'] === 'bookingApproved'
                && $messages[0]['data']['postId'] === 'p1';
        });
    }

    #[Test]
    public function uninstalled_devices_are_forgotten(): void
    {
        Http::fake([self::PUSH_URL => Http::response(['data' => [
            ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'ok', 'id' => 't2'],
        ]])]);
        $user = User::factory()->create();
        DevicePushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[gone]', 'platform' => 'android']);
        DevicePushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[live]', 'platform' => 'ios']);

        (new SendPushNotification($user->id, 'Hi', 'There'))->handle(app(ExpoPushClient::class));

        $this->assertDatabaseMissing('device_push_tokens', ['token' => 'ExponentPushToken[gone]']);
        $this->assertDatabaseHas('device_push_tokens', ['token' => 'ExponentPushToken[live]']);
    }

    #[Test]
    public function no_push_is_queued_without_a_device_or_when_push_is_turned_off(): void
    {
        Queue::fake([SendPushNotification::class]);
        $user = User::factory()->create();
        $service = app(NotificationService::class);

        $service->create(userId: $user->id, type: NotificationType::BookingApproved, title: 'A', message: 'B');
        Queue::assertNotPushed(SendPushNotification::class);

        DevicePushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[a]', 'platform' => 'android']);
        $service->preferencesFor($user->id)->update(['push_enabled' => false]);
        $service->create(userId: $user->id, type: NotificationType::BookingApproved, title: 'A', message: 'B');
        Queue::assertNotPushed(SendPushNotification::class);

        $service->preferencesFor($user->id)->update(['push_enabled' => true]);
        $service->create(userId: $user->id, type: NotificationType::BookingApproved, title: 'A', message: 'B');
        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->userId === $user->id);
    }

    #[Test]
    public function a_failing_push_service_never_breaks_the_request(): void
    {
        Http::fake([self::PUSH_URL => Http::response('down', 503)]);
        $user = User::factory()->create();
        DevicePushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[a]', 'platform' => 'android']);

        $notification = app(NotificationService::class)->create(
            userId: $user->id, type: NotificationType::BookingApproved, title: 'A', message: 'B',
        );

        $this->assertNotNull($notification);
    }

    #[Test]
    public function chat_messages_are_pushed_to_the_other_participant(): void
    {
        Queue::fake([SendPushNotification::class]);
        [$alice, $bob] = User::factory()->count(2)->create();
        DevicePushToken::create(['user_id' => $bob->id, 'token' => 'ExponentPushToken[bob]', 'platform' => 'ios']);
        $chat = Chat::openBetween($alice->id, $bob->id);

        $this->withToken($this->bearer($alice))
            ->postJson("/api/messages/{$chat->id}", ['text' => 'Is the room still free?'])
            ->assertOk();

        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->userId === $bob->id
            && $job->data === ['type' => 'chatMessage', 'chatId' => $chat->id]
            && str_starts_with($job->title, 'New message from'));
    }
}
