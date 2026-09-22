<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\SavedPost;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserTest extends TestCase
{
    #[Test]
    public function the_user_directory_is_admin_only_and_never_exposes_password_hashes(): void
    {
        User::factory()->count(2)->create();

        $this->signIn();
        $this->getJson('/api/users')->assertForbidden();

        $this->signIn(User::factory()->admin()->create());
        $this->getJson('/api/users')->assertOk()->assertJsonCount(4)->assertJsonMissingPath('0.password');
    }

    #[Test]
    public function users_can_update_their_profile_and_password(): void
    {
        $me = $this->signIn(User::factory()->create(['username' => 'old_name']));

        $this->putJson("/api/users/{$me->id}", [
            'username' => 'new_name', 'email' => 'new@example.com', 'fullName' => 'New Name',
            'phone' => '0712345678', 'gender' => 'other', 'password' => 'brandnew99', 'avatar' => 'https://cdn.example.com/a.png',
        ])
            ->assertOk()
            ->assertJsonPath('username', 'new_name')
            ->assertJsonPath('fullName', 'New Name')
            ->assertJsonPath('gender', 'other')
            ->assertJsonPath('avatar', 'https://cdn.example.com/a.png')
            ->assertJsonMissingPath('password');

        $this->assertTrue(Hash::check('brandnew99', $me->fresh()->password));
    }

    #[Test]
    public function blank_fields_from_the_profile_form_leave_values_unchanged(): void
    {
        $me = $this->signIn(User::factory()->create(['username' => 'keep_me', 'full_name' => 'Keep Me']));
        $oldHash = $me->password;

        $this->putJson("/api/users/{$me->id}", [
            'username' => 'keep_me', 'email' => $me->email, 'password' => '', 'fullName' => 'Keep Me', 'phone' => '', 'gender' => '', 'avatar' => null,
        ])->assertOk();

        $me->refresh();
        $this->assertSame($oldHash, $me->password);
        $this->assertNotNull($me->phone);
        $this->assertNotNull($me->gender);
    }

    #[Test]
    public function role_and_account_flags_cannot_be_self_edited(): void
    {
        $me = $this->signIn();

        $this->putJson("/api/users/{$me->id}", ['role' => 'admin', 'isActive' => false, 'isVerified' => false, 'id' => 'x'])->assertOk();

        $me->refresh();
        $this->assertTrue($me->isStudent());
        $this->assertTrue($me->is_active);
        $this->assertTrue($me->is_verified);
    }

    #[Test]
    public function profile_updates_reject_duplicates_but_allow_keeping_your_own_values(): void
    {
        User::factory()->create(['username' => 'taken_one', 'email' => 'taken@example.com']);
        $me = $this->signIn(User::factory()->create(['username' => 'mine_here']));

        $this->putJson("/api/users/{$me->id}", ['username' => 'taken_one'])->assertStatus(422)->assertJson(['message' => 'That username is already taken.']);
        $this->putJson("/api/users/{$me->id}", ['email' => 'taken@example.com'])->assertStatus(422)->assertJson(['message' => 'That email is already registered.']);
        $this->putJson("/api/users/{$me->id}", ['username' => 'mine_here'])->assertOk();
    }

    #[Test]
    public function you_cannot_edit_or_delete_another_user(): void
    {
        $other = User::factory()->create();
        $this->signIn();

        $this->putJson("/api/users/{$other->id}", ['fullName' => 'Hacked'])->assertForbidden()->assertJson(['message' => 'Not Authorized!']);
        $this->deleteJson("/api/users/{$other->id}")->assertForbidden();
        $this->putJson('/api/users/missing', [])->assertNotFound();
    }

    #[Test]
    public function deleting_your_account_removes_your_data_and_signs_you_out(): void
    {
        $me = $this->signIn();
        $post = Post::factory()->create();
        SavedPost::create(['user_id' => $me->id, 'post_id' => $post->id]);

        $this->deleteJson("/api/users/{$me->id}")->assertOk()->assertJson(['message' => 'User deleted']);

        $this->assertModelMissing($me);
        $this->assertDatabaseCount('saved_posts', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function saving_a_listing_toggles_it(): void
    {
        $me = $this->signIn();
        $post = Post::factory()->create();

        $this->postJson('/api/users/save', ['postId' => $post->id])->assertOk()->assertJson(['message' => 'Listing saved']);
        $this->getJson('/api/users/profilePosts')->assertJsonCount(1, 'savedPosts')->assertJsonPath('savedPosts.0.id', $post->id);

        $this->postJson('/api/users/save', ['postId' => $post->id])->assertOk()->assertJson(['message' => 'Listing removed from saved list']);
        $this->assertSame(0, SavedPost::where('user_id', $me->id)->count());

        $this->postJson('/api/users/save', ['postId' => 'ghost'])->assertStatus(422);
    }

    #[Test]
    public function the_dashboard_lists_own_listings_saved_listings_and_pending_bookings(): void
    {
        $owner = $this->signIn(User::factory()->owner()->create());
        Post::factory()->count(2)->create(['owner_id' => $owner->id]);
        Post::factory()->create();

        $this->getJson('/api/users/profilePosts')
            ->assertOk()
            ->assertJsonCount(2, 'userPosts')
            ->assertJsonCount(0, 'savedPosts')
            ->assertJsonCount(0, 'pendingBookings');
    }
}
