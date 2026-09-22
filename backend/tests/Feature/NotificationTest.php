<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Events\NotificationCreated;
use App\Events\NotificationRead;
use App\Mail\NotificationMail;
use App\Models\BookingRequest;
use App\Models\NotificationPreference;
use App\Models\Post;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    private function notify(User $user, string $title = 'Hello', NotificationType $type = NotificationType::BookingRequested): ?UserNotification
    {
        return app(NotificationService::class)->create(userId: $user->id, type: $type, title: $title, message: 'Body', metadata: ['postId' => 'p1']);
    }

    #[Test]
    public function the_navbar_data_is_served_newest_first_with_preferences(): void
    {
        $me = $this->signIn();
        foreach (range(1, 22) as $n) {
            $this->notify($me, "n{$n}");
        }
        UserNotification::first()->update(['is_read' => true]);

        $response = $this->getJson('/api/users/notifications')->assertOk();

        $response->assertJsonPath('unseenCount', 21)
            ->assertJsonCount(20, 'notifications')
            ->assertJsonPath('notifications.0.title', 'n22')
            ->assertJsonPath('preferences.emailEnabled', true)
            ->assertJsonPath('preferences.smsEnabled', false);

        $this->getJson('/api/users/notification')->assertOk()->assertExactJson([21]);
    }

    #[Test]
    public function creating_a_notification_broadcasts_it_on_the_users_private_channel(): void
    {
        Event::fake([NotificationCreated::class]);
        $user = User::factory()->create();

        $notification = $this->notify($user);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($user, $notification) {
            return $event->broadcastAs() === 'notification.new'
                && $event->broadcastOn()[0]->name === "private-users.{$user->id}"
                && $event->broadcastWith()['id'] === $notification->id
                && $event->broadcastWith()['isRead'] === false;
        });
    }

    #[Test]
    public function marking_read_is_scoped_to_the_owner_and_broadcasts(): void
    {
        Event::fake([NotificationRead::class]);
        $me = $this->signIn();
        $mine = $this->notify($me);
        $theirs = $this->notify(User::factory()->create());

        $this->putJson("/api/users/notifications/{$mine->id}/read")
            ->assertOk()->assertJsonPath('isRead', true);
        $this->assertNotNull($mine->fresh()->read_at);
        Event::assertDispatched(NotificationRead::class, fn ($e) => $e->userId === $me->id && $e->notificationId === $mine->id);

        $this->putJson("/api/users/notifications/{$theirs->id}/read")
            ->assertNotFound()->assertJson(['message' => 'Notification not found!']);
        $this->assertFalse($theirs->fresh()->is_read);
    }

    #[Test]
    public function mark_all_read_only_touches_the_callers_notifications(): void
    {
        $me = $this->signIn();
        $this->notify($me);
        $this->notify($me);
        $other = $this->notify(User::factory()->create());

        $this->putJson('/api/users/notifications/read-all')->assertOk();

        $this->assertSame(0, $me->userNotifications()->where('is_read', false)->count());
        $this->assertFalse($other->fresh()->is_read);
    }

    #[Test]
    public function preferences_can_be_toggled_and_unknown_keys_are_ignored(): void
    {
        $me = $this->signIn();

        $this->putJson('/api/users/notifications/preferences', [
            'bookingUpdates' => false, 'smsEnabled' => true, 'userId' => 'hijack', 'id' => 'x',
        ])
            ->assertOk()
            ->assertJsonPath('bookingUpdates', false)
            ->assertJsonPath('smsEnabled', true)
            ->assertJsonPath('emailEnabled', true);

        $this->getJson('/api/users/notifications/preferences')->assertOk()->assertJsonPath('bookingUpdates', false);
        $this->assertSame($me->id, NotificationPreference::firstOrFail()->user_id);
    }

    #[Test]
    public function opted_out_categories_are_not_delivered_unless_the_notice_is_critical(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationService::class);
        $service->preferencesFor($user->id)->update(['booking_updates' => false, 'safety_alerts' => false]);

        $this->assertNull($this->notify($user, type: NotificationType::BookingApproved));
        $this->assertNull($this->notify($user, type: NotificationType::SafetyAlert));

        $critical = $service->create($user->id, NotificationType::SafetyAlert, 'Account suspended', 'x', bypassPreference: true);
        $this->assertNotNull($critical);

        // Other categories are unaffected.
        $this->assertNotNull($this->notify($user, type: NotificationType::PriceDrop));
        $this->assertSame(2, $user->userNotifications()->count());
    }

    #[Test]
    public function turning_off_in_app_alerts_stops_stored_notifications(): void
    {
        $user = User::factory()->create();
        app(NotificationService::class)->preferencesFor($user->id)->update(['in_app_enabled' => false]);

        $this->assertNull($this->notify($user));
        $this->assertSame(0, UserNotification::count());
    }

    #[Test]
    public function emails_are_sent_only_when_enabled_and_a_mail_transport_exists(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'student@example.com']);

        $this->notify($user, 'Booking update');
        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('student@example.com')
            && $mail->title === 'Booking update' && $mail->details === ['postId' => 'p1']);

        Mail::fake();
        app(NotificationService::class)->preferencesFor($user->id)->update(['email_enabled' => false]);
        $this->notify($user);
        Mail::assertNothingOutgoing();

        // With only the log mailer configured there is nothing real to deliver, so skip mail entirely.
        Mail::fake();
        config(['mail.default' => 'log']);
        app(NotificationService::class)->preferencesFor($user->id)->update(['email_enabled' => true]);
        $this->notify($user);
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function a_failing_mail_transport_never_breaks_the_request(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $user = User::factory()->create();

        $this->assertNotNull($this->notify($user), 'the in-app notification must still be stored');
    }

    #[Test]
    public function the_test_email_endpoint_reports_configuration_problems(): void
    {
        Mail::fake();
        $me = $this->signIn();

        $this->postJson('/api/users/notifications/test-email')
            ->assertOk()->assertJson(['message' => "Test email sent to {$me->email}"]);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo($me->email) && $m->title === 'Test email from BoardingFinder');

        config(['mail.default' => 'log']);
        $this->postJson('/api/users/notifications/test-email')->assertStatus(400);
    }

    #[Test]
    public function the_test_email_helper_is_disabled_in_production(): void
    {
        $this->signIn();
        $this->app['env'] = 'production';

        $this->postJson('/api/users/notifications/test-email')->assertForbidden();
    }

    #[Test]
    public function stale_bookings_trigger_a_single_smart_reminder(): void
    {
        $me = $this->signIn();
        $post = Post::factory()->create();
        $booking = BookingRequest::factory()->create(['post_id' => $post->id, 'student_id' => $me->id]);
        $booking->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->getJson('/api/users/profilePosts')->assertOk()->assertJsonCount(1, 'pendingBookings');
        $this->getJson('/api/users/profilePosts')->assertOk();

        $this->assertSame(1, $me->userNotifications()->where('title', 'Booking still pending')->count());
    }
}
