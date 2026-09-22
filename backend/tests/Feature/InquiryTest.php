<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Models\Chat;
use App\Models\Inquiry;
use App\Models\Post;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InquiryTest extends TestCase
{
    #[Test]
    public function a_student_creates_one_active_inquiry_per_listing(): void
    {
        $post = Post::factory()->create();
        $student = $this->signIn();

        $id = $this->postJson('/api/inquiry', ['postId' => $post->id, 'type' => 'book'])
            ->assertCreated()
            ->assertJsonPath('message', 'Inquiry created successfully')
            ->assertJsonPath('inquiry.type', 'book')
            ->assertJsonPath('inquiry.status', 'pending')
            ->assertJsonPath('inquiry.post.ownerId', $post->owner_id)
            ->assertJsonPath('inquiry.student.id', $student->id)
            ->json('inquiry.id');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $post->owner_id, 'title' => 'New inquiry received']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $student->id, 'title' => 'Inquiry sent successfully']);

        $this->postJson('/api/inquiry', ['postId' => $post->id])
            ->assertOk()->assertJsonPath('inquiry.id', $id)
            ->assertJsonPath('message', 'You already have an active inquiry for this listing');
        $this->assertSame(1, Inquiry::count());
    }

    #[Test]
    public function only_students_can_inquire_and_not_about_their_own_or_missing_listings(): void
    {
        $post = Post::factory()->create();

        $this->signIn($post->owner);
        $this->postJson('/api/inquiry', ['postId' => $post->id])->assertForbidden();

        $this->signIn();
        $this->postJson('/api/inquiry', ['postId' => 'missing'])->assertNotFound();
        $this->postJson('/api/inquiry', [])->assertStatus(422)->assertJson(['message' => 'Post id is required']);
        $this->postJson('/api/inquiry', ['postId' => $post->id, 'type' => 'stare'])->assertStatus(422)->assertJson(['message' => 'Invalid inquiry type']);
    }

    #[Test]
    public function each_role_sees_the_right_inquiries(): void
    {
        $inquiry = Inquiry::factory()->create();
        Inquiry::factory()->create();

        $this->signIn($inquiry->student);
        $this->getJson('/api/inquiry')->assertJsonCount(1)->assertJsonPath('0.id', $inquiry->id);

        $this->signIn($inquiry->owner);
        $this->getJson('/api/inquiry')->assertJsonCount(1)->assertJsonPath('0.id', $inquiry->id);

        $this->signIn(User::factory()->admin()->create());
        $this->getJson('/api/inquiry')->assertJsonCount(2);
    }

    #[Test]
    public function owners_get_a_reminder_about_inquiries_unanswered_for_a_day(): void
    {
        $inquiry = Inquiry::factory()->create();
        $inquiry->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->signIn($inquiry->owner);
        $this->getJson('/api/inquiry')->assertOk();
        $this->getJson('/api/inquiry')->assertOk(); // second load must not send another

        $this->assertSame(1, $inquiry->owner->userNotifications()->where('title', 'You have unanswered inquiries')->count());
    }

    #[Test]
    public function accepting_opens_a_chat_and_two_inquiries_can_share_it(): void
    {
        $owner = User::factory()->owner()->create();
        $student = User::factory()->create();
        $first = Inquiry::factory()->create(['owner_id' => $owner->id, 'student_id' => $student->id, 'post_id' => Post::factory()->create(['owner_id' => $owner->id])]);
        $second = Inquiry::factory()->create(['owner_id' => $owner->id, 'student_id' => $student->id, 'post_id' => Post::factory()->create(['owner_id' => $owner->id])]);

        $this->signIn($owner);
        $chatId = $this->patchJson("/api/inquiry/{$first->id}/accept")
            ->assertOk()
            ->assertJsonPath('inquiry.status', 'accepted')
            ->assertJsonPath('chat.userIDs', fn ($ids) => count($ids) === 2)
            ->json('chat.id');

        // Same student + owner: the second acceptance reuses the chat instead of failing.
        $this->patchJson("/api/inquiry/{$second->id}/accept")->assertOk()->assertJsonPath('chat.id', $chatId);
        $this->assertSame(1, Chat::count());
        $this->assertSame($chatId, $second->fresh()->chat_id);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $student->id, 'title' => 'Inquiry accepted']);
    }

    #[Test]
    public function only_the_owner_or_an_admin_may_accept(): void
    {
        $inquiry = Inquiry::factory()->create();

        $this->signIn($inquiry->student);
        $this->patchJson("/api/inquiry/{$inquiry->id}/accept")->assertForbidden()->assertJson(['message' => 'Only the owner can accept this inquiry']);

        $this->signIn(User::factory()->admin()->create());
        $this->patchJson("/api/inquiry/{$inquiry->id}/accept")->assertOk();
    }

    #[Test]
    public function owners_reject_and_students_cancel(): void
    {
        $inquiry = Inquiry::factory()->create();

        $this->signIn($inquiry->student);
        $this->patchJson("/api/inquiry/{$inquiry->id}", ['status' => 'rejected'])
            ->assertForbidden()->assertJson(['message' => 'Only the owner can reject inquiries']);
        $this->patchJson("/api/inquiry/{$inquiry->id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('inquiry.status', 'cancelled');
        $this->assertDatabaseHas('user_notifications', ['user_id' => $inquiry->owner_id, 'title' => 'An inquiry was cancelled']);

        $other = Inquiry::factory()->create();
        $this->signIn($other->owner);
        $this->patchJson("/api/inquiry/{$other->id}", ['status' => 'cancelled'])
            ->assertForbidden()->assertJson(['message' => 'Only the student can cancel inquiries']);
        $this->patchJson("/api/inquiry/{$other->id}", ['status' => 'rejected'])->assertOk();
        $this->assertDatabaseHas('user_notifications', ['user_id' => $other->student_id, 'title' => 'Your inquiry was updated']);

        // Accepting goes through /accept only, and strangers are locked out.
        $this->patchJson("/api/inquiry/{$other->id}", ['status' => 'accepted'])->assertStatus(422)->assertJson(['message' => 'Invalid inquiry status']);
        $this->signIn();
        $this->patchJson("/api/inquiry/{$other->id}", ['status' => 'rejected'])->assertForbidden();
        $this->assertSame(InquiryStatus::Rejected, $other->fresh()->status);
    }
}
