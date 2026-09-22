<?php

namespace Tests\Feature;

use App\Enums\BookingRequestStatus;
use App\Models\BookingRequest;
use App\Models\Inquiry;
use App\Models\Post;
use App\Models\SavedPost;
use App\Models\User;
use App\Models\Watchlist;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PostTest extends TestCase
{
    private function payload(array $post = [], array $detail = []): array
    {
        return [
            'postData' => [
                'title' => 'Quiet room near campus',
                'rent' => 25000,
                'address' => '12 Temple Road',
                'city' => 'Kandy',
                'area' => 'Peradeniya',
                'capacity' => 2,
                'bathroomCount' => 1,
                'boardingType' => 'sharedRoom',
                'preferredTenantGender' => 'female',
                'status' => 'available',
                'latitude' => '7.2552',
                'longitude' => '80.5970',
                'images' => ['https://res.cloudinary.com/demo/a.jpg'],
                ...$post,
            ],
            'postDetail' => [
                'description' => '<p>A bright and comfortable room with study desks, balcony access and a short walk to campus.</p>',
                'wifi' => true,
                'mealsProvided' => false,
                'distanceToCampus' => 650,
                'nearestCampus' => 'University of Peradeniya',
                ...$detail,
            ],
        ];
    }

    #[Test]
    public function the_listing_index_filters_and_reports_demand_and_slots(): void
    {
        $cheap = Post::factory()->create(['title' => 'Cheap Kandy room', 'rent' => 15000, 'capacity' => 3]);
        Post::factory()->create(['title' => 'Pricey Colombo flat', 'rent' => 45000, 'city' => 'Colombo', 'area' => 'Rajagiriya']);
        Post::factory()->create(['title' => 'Girls only', 'preferred_tenant_gender' => 'female', 'rent' => 30000]);

        // 2 saves, 1 inquiry and 2 live booking requests + 1 cancelled one on the cheap room.
        foreach (User::factory()->count(2)->create() as $user) {
            SavedPost::create(['user_id' => $user->id, 'post_id' => $cheap->id]);
        }
        Inquiry::factory()->create(['post_id' => $cheap->id]);
        BookingRequest::factory()->count(2)->create(['post_id' => $cheap->id]);
        BookingRequest::factory()->create(['post_id' => $cheap->id, 'status' => BookingRequestStatus::Cancelled]);

        $this->getJson('/api/posts?city=Kandy&maxPrice=20000')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Cheap Kandy room')
            ->assertJsonPath('0.remainingSlots', 1)         // 3 slots - 2 live requests
            ->assertJsonPath('0.savedCount', 2)
            ->assertJsonPath('0.inquiryCount', 1)
            ->assertJsonPath('0.bookingCount', 3)
            ->assertJsonPath('0.demandScore', 2 * 2 + 1 * 3 + 3 * 4)
            ->assertJsonPath('0.demandLevel', 'high')
            ->assertJsonMissingPath('0.savedPostsCount');

        $this->getJson('/api/posts?preferredTenantGender=female')->assertJsonCount(1);
        $this->getJson('/api/posts?preferredTenantGender=any')->assertJsonCount(3);
        $this->getJson('/api/posts?area=Rajagiriya')->assertJsonCount(1);
        $this->getJson('/api/posts?minPrice=40000')->assertJsonCount(1);
    }

    #[Test]
    public function a_city_search_also_matches_listings_by_neighbourhood(): void
    {
        Post::factory()->create(['city' => 'Kandy', 'area' => 'Peradeniya']);

        $this->getJson('/api/posts?city=Peradeniya')->assertJsonCount(1);
    }

    #[Test]
    public function the_detail_page_is_public_and_personalised_for_signed_in_visitors(): void
    {
        $post = Post::factory()->withDetail(['wifi' => true])->create();

        $this->getJson("/api/posts/{$post->id}")
            ->assertOk()
            ->assertJsonPath('isSaved', false)
            ->assertJsonPath('bookingRequestStatus', null)
            ->assertJsonPath('postDetail.wifi', true)
            ->assertJsonPath('owner.id', $post->owner_id)
            ->assertJsonPath('remainingSlots', 2);

        $student = $this->signIn();
        SavedPost::create(['user_id' => $student->id, 'post_id' => $post->id]);
        $booking = BookingRequest::factory()->create(['post_id' => $post->id, 'student_id' => $student->id]);

        $this->getJson("/api/posts/{$post->id}")
            ->assertJsonPath('isSaved', true)
            ->assertJsonPath('bookingRequestStatus', 'pending')
            ->assertJsonPath('bookingRequestId', $booking->id)
            ->assertJsonPath('remainingSlots', 1);
    }

    #[Test]
    public function an_unknown_listing_is_a_404_with_the_original_message(): void
    {
        $this->getJson('/api/posts/01unknown')->assertNotFound()->assertJson(['message' => 'Listing not found']);
    }

    #[Test]
    public function an_owner_can_publish_a_listing_and_matching_saved_searches_are_notified(): void
    {
        $watcher = User::factory()->create();
        Watchlist::factory()->create(['user_id' => $watcher->id, 'city' => 'Kandy', 'area' => 'Peradeniya', 'max_budget' => 30000, 'wifi_required' => true]);
        Watchlist::factory()->create(['city' => 'Colombo']); // does not match

        $owner = $this->signIn(User::factory()->owner()->create());

        $response = $this->postJson('/api/posts', $this->payload())
            ->assertCreated()
            ->assertJsonPath('ownerId', $owner->id)
            ->assertJsonPath('postDetail.wifi', true)
            ->assertJsonPath('images.0', 'https://res.cloudinary.com/demo/a.jpg');

        $this->assertDatabaseHas('posts', ['id' => $response->json('id'), 'rent' => 25000]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $watcher->id, 'type' => 'searchMatch']);
        $this->assertDatabaseCount('user_notifications', 1);
    }

    #[Test]
    public function students_cannot_publish_listings(): void
    {
        $this->signIn();

        $this->postJson('/api/posts', $this->payload())->assertForbidden();
    }

    #[Test]
    public function publishing_validates_the_payload_with_client_friendly_messages(): void
    {
        $this->signIn(User::factory()->owner()->create());

        $response = $this->postJson('/api/posts', $this->payload(
            ['title' => 'abc', 'rent' => 0, 'capacity' => 0, 'city' => 'ab'],
            ['description' => '<p>Too short.</p>'],
        ))->assertStatus(422);

        $this->assertEqualsCanonicalizing([
            'Title must be between 5 and 60 characters.',
            'Location must be at least 3 characters long.',
            'Monthly price (rent) must be greater than Rs. 0.',
            'Available slots (capacity) must be a valid whole number of at least 1.',
            'Description must be at least 10 words.',
        ], $response->json('errors'));
    }

    #[Test]
    public function a_client_cannot_smuggle_the_owner_or_unknown_columns_through_the_payload(): void
    {
        $owner = $this->signIn(User::factory()->owner()->create());
        $other = User::factory()->owner()->create();

        $id = $this->postJson('/api/posts', $this->payload(['ownerId' => $other->id, 'owner_id' => $other->id, 'id' => 'evil']))
            ->assertCreated()->json('id');

        $this->assertNotSame('evil', $id);
        $this->assertSame($owner->id, Post::findOrFail($id)->owner_id);
    }

    #[Test]
    public function only_the_owner_can_edit_or_delete_and_a_price_drop_notifies_savers(): void
    {
        $post = Post::factory()->withDetail()->create(['rent' => 30000]);
        $saver = User::factory()->create();
        SavedPost::create(['user_id' => $saver->id, 'post_id' => $post->id]);

        $this->signIn(User::factory()->owner()->create());
        $this->putJson("/api/posts/{$post->id}", ['postData' => ['rent' => 20000]])
            ->assertForbidden()->assertJson(['message' => 'Not Authorized!']);
        $this->deleteJson("/api/posts/{$post->id}")->assertForbidden();

        $this->signIn($post->owner);
        $this->putJson("/api/posts/{$post->id}", ['postData' => ['rent' => 20000], 'postDetail' => ['wifi' => false]])
            ->assertOk()
            ->assertJsonPath('rent', 20000)
            ->assertJsonPath('postDetail.wifi', false);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $saver->id,
            'type' => 'priceDrop',
            'message' => "{$post->title} dropped from LKR 30000 to LKR 20000.",
        ]);

        $this->deleteJson("/api/posts/{$post->id}")->assertOk()->assertJson(['message' => 'Post deleted']);
        $this->assertModelMissing($post);
        $this->assertDatabaseCount('saved_posts', 0);
    }

    #[Test]
    public function a_student_can_request_a_booking_once_and_the_owner_is_notified(): void
    {
        $post = Post::factory()->create(['capacity' => 2]);
        $student = $this->signIn();

        $this->postJson("/api/posts/{$post->id}/book", [
            'applicantName' => 'Nadeesha',
            'applicantPhone' => '0771234567',
            'applicantAddress' => 'Galle',
            'applicantNIC' => '200012345678',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Booking request sent')
            ->assertJsonPath('bookingRequest.status', 'pending')
            ->assertJsonPath('bookingRequest.applicantNIC', '200012345678');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $post->owner_id, 'type' => 'bookingRequested', 'title' => 'New booking request received']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $student->id, 'title' => 'Booking request sent']);

        // Idempotent: asking again returns the existing request rather than taking a second slot.
        $this->postJson("/api/posts/{$post->id}/book")
            ->assertOk()->assertJsonPath('message', 'Booking request already sent');
        $this->assertSame(1, BookingRequest::count());
    }

    #[Test]
    public function booking_is_refused_for_owners_unavailable_or_full_listings(): void
    {
        $post = Post::factory()->create(['capacity' => 1]);

        $this->signIn($post->owner);
        $this->postJson("/api/posts/{$post->id}/book")->assertForbidden()->assertJson(['message' => 'Only students can send booking requests']);

        $this->signIn();
        $this->postJson("/api/posts/{$post->id}/book")->assertCreated();

        $this->signIn();
        $this->postJson("/api/posts/{$post->id}/book")->assertStatus(400)->assertJson(['message' => 'No remaining slots available']);

        $post->update(['status' => 'full']);
        $this->postJson("/api/posts/{$post->id}/book")->assertStatus(400)->assertJson(['message' => 'This boarding is not available right now']);
    }

    #[Test]
    public function taking_the_last_slot_alerts_savers_and_reminds_the_owner(): void
    {
        $post = Post::factory()->create(['capacity' => 1]);
        $saver = User::factory()->create();
        SavedPost::create(['user_id' => $saver->id, 'post_id' => $post->id]);

        $this->signIn();
        $this->postJson("/api/posts/{$post->id}/book")->assertCreated();

        $this->assertDatabaseHas('user_notifications', ['user_id' => $saver->id, 'type' => 'lowAvailability', 'title' => 'Tracked listing is now full']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $post->owner_id, 'type' => 'smartReminder', 'title' => 'A listing just filled up']);
    }

    #[Test]
    public function the_demand_overview_ranks_areas_by_weighted_signals(): void
    {
        $hot = Post::factory()->create(['area' => 'Peradeniya']);
        Post::factory()->create(['city' => 'Colombo', 'area' => 'Rajagiriya']);
        Watchlist::factory()->count(2)->create(['city' => 'Kandy', 'area' => 'Peradeniya']);
        Inquiry::factory()->create(['post_id' => $hot->id]);
        BookingRequest::factory()->create(['post_id' => $hot->id]);

        $overview = $this->getJson('/api/posts/demand/overview')->assertOk();

        $overview->assertJsonPath('summary.totalAreasTracked', 2)
            ->assertJsonPath('allAreas.0.area', 'Peradeniya')
            ->assertJsonPath('allAreas.0.demandScore', 2 * 2 + 1 * 3 + 1 * 4)
            ->assertJsonPath('allAreas.0.demandLevel', 'medium')
            ->assertJsonPath('allAreas.0.latitude', 7.2552)
            ->assertJsonPath('allAreas.1.demandLevel', 'low')
            ->assertJsonCount(2, 'lowDemandAreas');
    }
}
