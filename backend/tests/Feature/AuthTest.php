<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return [
            'fullName' => 'Nadeesha Perera',
            'username' => 'nadeesha_p',
            'email' => 'Nadeesha@Example.com',
            'phone' => '0771234567',
            'password' => 'password123',
            'gender' => 'female',
            'role' => 'student',
            ...$overrides,
        ];
    }

    #[Test]
    public function it_registers_a_user_and_sends_a_welcome_notification(): void
    {
        $this->postJson('/api/auth/register', $this->payload())
            ->assertCreated()
            ->assertJson(['message' => 'User created successfully']);

        $user = User::where('username', 'nadeesha_p')->firstOrFail();
        $this->assertSame('nadeesha@example.com', $user->email);
        $this->assertNotSame('password123', $user->password);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'type' => 'accountCreated',
            'title' => 'Welcome to BoardingFinder',
        ]);
    }

    #[Test]
    public function it_returns_the_first_validation_message_and_a_flat_error_list(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['phone' => '123', 'password' => 'short']))
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Phone number must contain exactly 10 digits.',
                'errors' => ['Phone number must contain exactly 10 digits.', 'Password must be at least 8 characters long.'],
            ]);
    }

    #[Test]
    #[DataProvider('invalidRegistrations')]
    public function it_rejects_invalid_registrations(array $override, string $message): void
    {
        $this->postJson('/api/auth/register', $this->payload($override))
            ->assertStatus(422)
            ->assertJsonPath('message', $message);
    }

    public static function invalidRegistrations(): array
    {
        return [
            'short name' => [['fullName' => 'A'], 'Full name must be at least 2 characters long.'],
            'digits in name' => [['fullName' => 'Bob 3'], 'Full name can only contain letters and common name symbols.'],
            'bad username' => [['username' => 'a b'], 'Username must be 3-20 characters and use only letters, numbers, or underscores.'],
            'bad email' => [['email' => 'nope'], 'Enter a valid email address.'],
            'bad gender' => [['gender' => 'robot'], 'Select a valid gender.'],
            'cannot self-register as admin' => [['role' => 'admin'], 'Select a valid user type.'],
        ];
    }

    #[Test]
    public function it_rejects_duplicate_usernames_and_emails(): void
    {
        User::factory()->create(['username' => 'taken_name', 'email' => 'used@example.com']);

        $this->postJson('/api/auth/register', $this->payload(['username' => 'taken_name']))
            ->assertStatus(409)->assertJson(['message' => 'That username is already taken.']);

        $this->postJson('/api/auth/register', $this->payload(['email' => 'used@example.com']))
            ->assertStatus(409)->assertJson(['message' => 'That email is already registered.']);
    }

    #[Test]
    public function login_sets_an_httponly_cookie_and_never_leaks_the_password(): void
    {
        $user = User::factory()->create(['username' => 'kasun_s']);

        $response = $this->postJson('/api/auth/login', ['username' => 'kasun_s', 'password' => 'password123'])
            ->assertOk()
            ->assertJson(['id' => $user->id, 'username' => 'kasun_s', 'role' => 'student'])
            ->assertJsonMissingPath('password')
            ->assertCookie('token');

        $cookie = $response->getCookie('token', decrypt: false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertGreaterThan(now()->addDays(6)->timestamp, $cookie->getExpiresTime());
    }

    #[Test]
    public function login_fails_with_bad_credentials_or_a_suspended_account(): void
    {
        User::factory()->create(['username' => 'kasun_s']);
        User::factory()->suspended()->create(['username' => 'banned_one']);

        $this->postJson('/api/auth/login', ['username' => 'kasun_s', 'password' => 'wrongpass1'])
            ->assertStatus(401)->assertJson(['message' => 'Invalid Credentials!']);

        $this->postJson('/api/auth/login', ['username' => 'nobody_x', 'password' => 'password123'])
            ->assertStatus(401)->assertJson(['message' => 'Invalid Credentials!']);

        $this->postJson('/api/auth/login', ['username' => 'banned_one', 'password' => 'password123'])
            ->assertStatus(403)->assertJson(['message' => 'Your account has been suspended. Please contact support.']);

        $this->postJson('/api/auth/login', ['username' => 'ab', 'password' => 'password123'])
            ->assertStatus(422)->assertJson(['message' => 'Enter a valid username to continue.']);
    }

    #[Test]
    public function the_cookie_authenticates_requests_until_logout_revokes_it(): void
    {
        User::factory()->create(['username' => 'kasun_s']);

        $login = $this->postJson('/api/auth/login', ['username' => 'kasun_s', 'password' => 'password123']);
        $token = urldecode($login->getCookie('token', decrypt: false)->getValue());

        $this->withCredentials()->withUnencryptedCookie('token', $token)
            ->getJson('/api/test/should-be-logged-in')->assertOk();

        $this->withCredentials()->withUnencryptedCookie('token', $token)
            ->postJson('/api/auth/logout')->assertOk()->assertJson(['message' => 'Logout Successful']);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        Auth::forgetGuards();
        $this->withCredentials()->withUnencryptedCookie('token', $token)
            ->getJson('/api/test/should-be-logged-in')
            ->assertStatus(403)->assertJson(['message' => 'Token is not Valid!']);
    }

    #[Test]
    public function protected_routes_require_a_cookie(): void
    {
        $this->getJson('/api/bookings')->assertStatus(401)->assertJson(['message' => 'Not Authenticated!']);
        $this->withCredentials()->withUnencryptedCookie('token', 'garbage')->getJson('/api/bookings')->assertStatus(403);
    }

    #[Test]
    public function a_suspended_account_is_locked_out_even_with_a_live_token(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $user->update(['is_active' => false]);

        $this->getJson('/api/bookings')
            ->assertStatus(403)
            ->assertJson(['message' => 'Account access has been suspended.']);
    }

    #[Test]
    public function admin_only_routes_reject_other_roles(): void
    {
        $this->signIn();
        $this->getJson('/api/test/should-be-admin')->assertStatus(403);

        $this->signIn(User::factory()->admin()->create());
        $this->getJson('/api/test/should-be-admin')->assertOk();
    }

    #[Test]
    public function cors_allows_the_client_origin_with_credentials(): void
    {
        $this->call('OPTIONS', '/api/auth/login', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
