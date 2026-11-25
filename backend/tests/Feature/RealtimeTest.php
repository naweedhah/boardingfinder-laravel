<?php

namespace Tests\Feature;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // Channels were registered on the test driver at boot; register them on Reverb too.
        require base_path('routes/channels.php');
    }

    #[Test]
    public function a_user_can_subscribe_to_their_own_private_channel_only(): void
    {
        $me = $this->signIn();
        $other = User::factory()->create();

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-users.{$me->id}"])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-users.{$other->id}"])
            ->assertForbidden();
    }

    #[Test]
    public function channel_auth_requires_the_login_cookie(): void
    {
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-users.abc'])
            ->assertUnauthorized();
    }

    #[Test]
    public function the_mobile_app_authorizes_channels_with_a_bearer_token(): void
    {
        $me = User::factory()->create();
        $token = $me->createToken('mobile:test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-users.{$me->id}"])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }
}
