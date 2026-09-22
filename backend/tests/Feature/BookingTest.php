<?php

namespace Tests\Feature;

use App\Enums\BookingRequestStatus;
use App\Models\BookingRequest;
use App\Models\Chat;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingTest extends TestCase
{
    #[Test]
    public function owners_see_requests_for_their_listings_and_students_see_their_own(): void
    {
        $booking = BookingRequest::factory()->create();
        BookingRequest::factory()->create(); // someone else's

        $this->signIn($booking->owner);
        $this->getJson('/api/bookings')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $booking->id)
            ->assertJsonPath('0.student.id', $booking->student_id)
            ->assertJsonPath('0.post.title', $booking->post->title);

        $this->signIn($booking->student);
        $this->getJson('/api/bookings')->assertJsonCount(1)->assertJsonPath('0.id', $booking->id);
    }

    #[Test]
    public function approving_opens_a_chat_and_notifies_the_student(): void
    {
        $booking = BookingRequest::factory()->create();
        $this->signIn($booking->owner);

        $response = $this->putJson("/api/bookings/{$booking->id}/approve")
            ->assertOk()
            ->assertJsonPath('booking.status', 'approved');

        $chat = Chat::findOrFail($response->json('chatId'));
        $this->assertEqualsCanonicalizing([$booking->student_id, $booking->owner_id], $chat->users->pluck('id')->all());
        $this->assertNotNull($booking->fresh()->approved_at);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $booking->student_id, 'type' => 'bookingApproved']);

        // The chat is reused, not duplicated, and cannot be approved twice.
        $this->postJson("/api/bookings/{$booking->id}/chat")->assertOk()->assertJsonPath('chatId', $chat->id);
        $this->putJson("/api/bookings/{$booking->id}/approve")->assertStatus(400)->assertJson(['message' => 'Only pending bookings can be approved']);
        $this->assertSame(1, Chat::count());
    }

    #[Test]
    public function only_the_listing_owner_can_approve_or_reject(): void
    {
        $booking = BookingRequest::factory()->create();

        $this->signIn($booking->student);
        $this->putJson("/api/bookings/{$booking->id}/approve")->assertForbidden()->assertJson(['message' => 'Not authorized']);
        $this->putJson("/api/bookings/{$booking->id}/reject")->assertForbidden();

        $this->signIn(User::factory()->owner()->create());
        $this->putJson("/api/bookings/{$booking->id}/reject")->assertForbidden();

        $this->signIn($booking->owner);
        $this->putJson("/api/bookings/{$booking->id}/reject")->assertOk()->assertJsonPath('booking.status', 'rejected');
        $this->assertDatabaseHas('user_notifications', ['user_id' => $booking->student_id, 'type' => 'bookingRejected', 'title' => 'Booking request rejected']);
        $this->putJson("/api/bookings/{$booking->id}/reject")->assertStatus(400);
    }

    #[Test]
    public function students_and_owners_can_cancel_at_different_stages(): void
    {
        $confirmed = BookingRequest::factory()->create(['status' => BookingRequestStatus::Confirmed]);

        $this->signIn($confirmed->student);
        $this->putJson("/api/bookings/{$confirmed->id}/cancel")->assertStatus(400)->assertJson(['message' => 'Cannot cancel at this stage']);

        $this->signIn($confirmed->owner);
        $this->putJson("/api/bookings/{$confirmed->id}/cancel")->assertOk()->assertJsonPath('booking.status', 'cancelled');
        $this->assertDatabaseHas('user_notifications', ['user_id' => $confirmed->student_id, 'title' => 'Booking cancelled']);

        $pending = BookingRequest::factory()->create();
        $this->signIn($pending->student);
        $this->putJson("/api/bookings/{$pending->id}/cancel")->assertOk();
        $this->assertDatabaseHas('user_notifications', ['user_id' => $pending->owner_id, 'title' => 'Booking cancelled']);

        $this->signIn();
        $this->putJson("/api/bookings/{$pending->id}/cancel")->assertForbidden();
    }

    #[Test]
    public function an_unknown_booking_is_a_404(): void
    {
        $this->signIn();
        $this->putJson('/api/bookings/nope/approve')->assertNotFound()->assertJson(['message' => 'Booking not found']);
    }
}
