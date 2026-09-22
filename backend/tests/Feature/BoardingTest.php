<?php

namespace Tests\Feature;

use App\Enums\BoardingStatus;
use App\Models\Post;
use App\Models\SavedPost;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardingTest extends TestCase
{
    private function form(array $overrides = []): array
    {
        return [
            'title' => 'Sunny single room',
            'location' => '12 Temple Road, Kandy',
            'price' => '22000',
            'capacity' => '2',
            'genderAllowed' => 'Female',
            'facilities' => 'Wi-Fi, desk, wardrobe',
            'description' => 'A bright single room with a study desk, reliable Wi-Fi and a short walk to campus.',
            'images' => [UploadedFile::fake()->image('room.jpg'), UploadedFile::fake()->image('desk.png')],
            ...$overrides,
        ];
    }

    #[Test]
    public function an_owner_publishes_a_listing_with_photos_stored_on_the_public_disk(): void
    {
        Storage::fake('public');
        $owner = $this->signIn(User::factory()->owner()->create());

        $response = $this->post('/api/boardings/add', $this->form(['ownerId' => 'someone-else']), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('message', 'Boarding added successfully!')
            ->assertJsonPath('boarding.ownerId', $owner->id)   // never trusts the client-supplied owner
            ->assertJsonPath('boarding.rent', 22000)
            ->assertJsonPath('boarding.address', '12 Temple Road, Kandy')
            ->assertJsonPath('boarding.preferredTenantGender', 'female')
            ->assertJsonPath('boarding.status', 'available')
            ->assertJsonPath('boarding.city', 'Colombo')        // Node API defaults
            ->assertJsonPath('boarding.postDetail.rules', 'Wi-Fi, desk, wardrobe');

        $images = $response->json('boarding.images');
        $this->assertCount(2, $images);
        $this->assertStringStartsWith(url('/storage/uploads/'), $images[0]);

        $stored = Post::findOrFail($response->json('boarding.id'))->getRawOriginal('images');
        foreach (json_decode($stored) as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    #[Test]
    public function publishing_validates_like_the_dashboard_form_and_requires_photos(): void
    {
        Storage::fake('public');
        $this->signIn(User::factory()->owner()->create());

        $response = $this->post('/api/boardings/add', $this->form([
            'title' => 'abc', 'price' => '0', 'capacity' => '0', 'description' => 'too short', 'images' => [],
        ]), ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertContains('Title must be between 5 and 60 characters.', $response->json('errors'));
        $this->assertContains('Description must be at least 10 words.', $response->json('errors'));
        $this->assertDatabaseCount('posts', 0);

        $this->post('/api/boardings/add', $this->form(['images' => array_fill(0, 6, UploadedFile::fake()->image('x.jpg'))]), ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->post('/api/boardings/add', $this->form(['images' => [UploadedFile::fake()->create('evil.php', 10, 'text/x-php')]]), ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    #[Test]
    public function students_cannot_use_the_owner_endpoints(): void
    {
        $this->signIn();

        $this->post('/api/boardings/add', $this->form(), ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson('/api/boardings/generate-description', ['features' => 'wifi'])->assertForbidden();
        $this->deleteJson('/api/boardings/'.Post::factory()->create()->id)->assertForbidden();
    }

    #[Test]
    public function every_boarding_endpoint_needs_a_login(): void
    {
        $post = Post::factory()->create();

        $this->getJson('/api/boardings')->assertUnauthorized();
        $this->deleteJson("/api/boardings/{$post->id}")->assertUnauthorized();
        $this->patchJson("/api/boardings/{$post->id}/status", ['status' => 'Full'])->assertUnauthorized();
        $this->postJson("/api/boardings/{$post->id}/waitlist")->assertUnauthorized();
        $this->assertModelExists($post);
    }

    #[Test]
    public function the_dashboard_lists_only_the_owners_listings_in_the_flat_shape_it_expects(): void
    {
        $owner = User::factory()->owner()->create();
        $mine = Post::factory()->withDetail(['description' => '<p>Nice place</p>'])->create(['owner_id' => $owner->id, 'title' => 'Mine', 'rent' => 20000, 'preferred_tenant_gender' => 'male', 'capacity' => 3]);
        Post::factory()->create(['status' => BoardingStatus::Reserved, 'owner_id' => $owner->id, 'title' => 'Reserved one']);
        Post::factory()->create(['title' => 'Not mine']);
        SavedPost::create(['user_id' => User::factory()->create()->id, 'post_id' => $mine->id]);

        $this->signIn($owner);
        $response = $this->getJson('/api/boardings')->assertOk()->assertJsonCount(2);

        $rows = collect($response->json())->keyBy('title');
        $this->assertSame([
            '_id' => $mine->id, 'title' => 'Mine', 'description' => '<p>Nice place</p>', 'price' => 20000,
            'location' => $mine->address, 'genderAllowed' => 'male', 'status' => 'Available', 'capacity' => 3,
            'images' => ['https://example.com/room.jpg'], 'savedCount' => 1, 'inquiryCount' => 0, 'bookingCount' => 0, 'remainingSlots' => 3,
        ], $rows['Mine']);
        $this->assertSame('Full', $rows['Reserved one']['status']);
        $this->assertSame('', $rows['Reserved one']['description']);

        $this->signIn(User::factory()->admin()->create());
        $this->getJson('/api/boardings')->assertJsonCount(3);
    }

    #[Test]
    public function an_owner_edits_text_fields_and_optionally_replaces_photos(): void
    {
        Storage::fake('public');
        $post = Post::factory()->withDetail(['rules' => 'Original facilities'])->create();
        $this->signIn($post->owner);

        $this->putJson("/api/boardings/{$post->id}", [
            'title' => 'Renamed room', 'price' => '31000', 'location' => 'New address', 'capacity' => '4',
            'genderAllowed' => 'Any', 'description' => 'An updated description that easily has more than ten words in it.', 'features' => '',
        ])
            ->assertOk()->assertJsonPath('message', 'Updated successfully')->assertJsonPath('post.rent', 31000);

        $post->refresh()->load('postDetail');
        $this->assertSame('Renamed room', $post->title);
        $this->assertSame('New address', $post->address);
        $this->assertSame(4, $post->capacity);
        $this->assertSame('any', $post->preferred_tenant_gender->value);
        $this->assertStringContainsString('updated description', $post->postDetail->description);
        $this->assertSame('Original facilities', $post->postDetail->rules, 'a blank features field must not wipe the facilities');
        $this->assertSame(['https://example.com/room.jpg'], $post->images, 'no new photos keeps the old ones');

        $this->post("/api/boardings/{$post->id}", ['_method' => 'PUT', 'images' => [UploadedFile::fake()->image('new.jpg')]], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertCount(1, $post->fresh()->images);
        $this->assertStringContainsString('/storage/uploads/', $post->fresh()->images[0]);
    }

    #[Test]
    public function only_the_owner_can_edit_delete_or_flip_status(): void
    {
        $post = Post::factory()->create();
        $this->signIn(User::factory()->owner()->create());

        $this->putJson("/api/boardings/{$post->id}", ['title' => 'Hijacked title'])->assertForbidden();
        $this->patchJson("/api/boardings/{$post->id}/status", ['status' => 'Full'])->assertForbidden();
        $this->getJson("/api/boardings/{$post->id}/waitlist")->assertForbidden();
        $this->deleteJson("/api/boardings/{$post->id}")->assertForbidden();

        $this->signIn($post->owner);
        $this->deleteJson("/api/boardings/{$post->id}")->assertOk()->assertJson(['message' => 'Boarding deleted successfully!']);
        $this->assertModelMissing($post);
    }

    #[Test]
    public function marking_a_listing_available_notifies_and_clears_its_waitlist(): void
    {
        $post = Post::factory()->create(['status' => BoardingStatus::Full]);
        [$a, $b, $c] = User::factory()->count(3)->create();
        $entries = [
            Watchlist::factory()->create(['user_id' => $a->id, 'post_id' => $post->id]),
            Watchlist::factory()->create(['user_id' => $b->id, 'post_id' => $post->id]),
            Watchlist::factory()->create(['user_id' => $c->id, 'post_id' => $post->id, 'is_active' => false]),
        ];
        Watchlist::factory()->create(['user_id' => $a->id, 'post_id' => null]); // a saved search, not the waitlist

        $this->signIn($post->owner);

        $this->patchJson("/api/boardings/{$post->id}/status", ['status' => 'Full'])->assertOk()->assertJson(['notifiedCount' => 0]);
        $this->patchJson("/api/boardings/{$post->id}/status", ['status' => 'Available'])
            ->assertOk()->assertJson(['message' => 'Status updated', 'notifiedCount' => 2]);

        $this->assertSame(BoardingStatus::Available, $post->fresh()->status);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $a->id, 'type' => 'boardingAvailable']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $b->id, 'type' => 'boardingAvailable']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $c->id]);
        $this->assertFalse($entries[0]->fresh()->is_active);

        // Flipping again does not re-notify people who were already cleared.
        $this->patchJson("/api/boardings/{$post->id}/status", ['status' => 'Available'])->assertJson(['notifiedCount' => 0]);
        $this->patchJson("/api/boardings/{$post->id}/status", ['status' => 'Maybe'])->assertStatus(422);
    }

    #[Test]
    public function students_join_the_waitlist_once_and_owners_read_it_in_order(): void
    {
        $post = Post::factory()->create();
        $first = $this->signIn(User::factory()->create(['full_name' => 'First Student']));
        $this->postJson("/api/boardings/{$post->id}/waitlist", ['studentName' => 'Ignored', 'studentEmail' => 'ignored@example.com'])
            ->assertCreated()->assertJson(['message' => 'Successfully added to the waitlist!']);
        $this->postJson("/api/boardings/{$post->id}/waitlist")->assertStatus(400)->assertJson(['message' => 'You are already on the waitlist!']);

        $second = $this->signIn(User::factory()->create(['full_name' => 'Second Student']));
        $this->postJson("/api/boardings/{$post->id}/waitlist")->assertCreated();
        $this->assertSame(3, User::count(), 'joining must never create accounts from client-supplied names/emails');

        $this->signIn($post->owner);
        $this->postJson("/api/boardings/{$post->id}/waitlist")->assertStatus(400);
        $response = $this->getJson("/api/boardings/{$post->id}/waitlist")->assertOk()->assertJsonCount(2);
        $this->assertSame(['First Student', 'Second Student'], array_column($response->json(), 'studentName'));
        $this->assertSame($first->email, $response->json('0.studentEmail'));
        $this->assertArrayHasKey('_id', $response->json('0'));
    }

    #[Test]
    public function the_ai_description_generator_calls_gemini(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'A lovely room close to campus.']]]]],
        ])]);

        $this->signIn(User::factory()->owner()->create());

        $this->postJson('/api/boardings/generate-description', ['features' => 'Wi-Fi, desk'])
            ->assertOk()->assertExactJson(['description' => 'A lovely room close to campus.']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-2.5-flash:generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Wi-Fi, desk'));

        $this->postJson('/api/boardings/generate-description', [])->assertStatus(422);
    }

    #[Test]
    public function image_verification_returns_legitimacy_and_tags_even_when_fenced_in_markdown(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => "```json\n{ \"isLegitimate\": true, \"tags\": [\"Bed\", \"Desk\"] }\n```"]]]]],
        ])]);

        $this->signIn(User::factory()->owner()->create());

        $this->post('/api/boardings/verify-image', ['image' => UploadedFile::fake()->image('room.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertExactJson(['isLegitimate' => true, 'tags' => ['Bed', 'Desk']]);

        Http::assertSent(fn ($request) => $request['contents'][0]['parts'][1]['inline_data']['mime_type'] === 'image/jpeg');

        $this->post('/api/boardings/verify-image', [], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJson(['message' => 'No image uploaded for verification']);
    }

    #[Test]
    public function ai_failures_surface_as_a_bad_gateway_with_the_original_messages(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429)]);
        $this->signIn(User::factory()->owner()->create());

        $this->postJson('/api/boardings/generate-description', ['features' => 'x'])
            ->assertStatus(502)->assertJson(['message' => 'Failed to generate description']);
        $this->post('/api/boardings/verify-image', ['image' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(502)->assertJson(['message' => 'Failed to scan image']);

        config(['services.gemini.key' => null]);
        $this->postJson('/api/boardings/generate-description', ['features' => 'x'])->assertStatus(502);
    }
}
