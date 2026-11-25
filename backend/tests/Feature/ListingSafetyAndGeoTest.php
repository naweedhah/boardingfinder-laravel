<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListingSafetyAndGeoTest extends TestCase
{
    /** SLIIT Malabe campus. */
    private const CAMPUS = ['lat' => 6.9147, 'lng' => 79.9729];

    private function payload(array $post = [], array $detail = []): array
    {
        return [
            'postData' => [
                'title' => 'Room near SLIIT',
                'rent' => 22000,
                'address' => '5 New Kandy Road',
                'city' => 'Malabe',
                'capacity' => 2,
                'boardingType' => 'singleRoom',
                'latitude' => 6.9150,
                'longitude' => 79.9700,
                'images' => ['https://res.cloudinary.com/demo/room.jpg'],
                ...$post,
            ],
            'postDetail' => [
                'description' => '<p>Clean, quiet single room with a desk, fan and fast wifi, ten minutes walk to campus.</p>',
                ...$detail,
            ],
        ];
    }

    #[Test]
    public function script_and_event_handler_html_is_stripped_from_descriptions_before_saving(): void
    {
        $this->signIn(User::factory()->owner()->create());

        $id = $this->postJson('/api/posts', $this->payload(detail: [
            'description' => '<p onclick="steal()">Clean quiet room with desk, fan and fast wifi near the campus gate.</p>'
                .'<script>alert(1)</script><img src=x onerror="alert(2)"><a href="javascript:alert(3)">x</a>'
                .'<strong>Bills included</strong>',
        ]))->assertCreated()->json('id');

        $html = Post::with('postDetail')->findOrFail($id)->postDetail->description;

        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $html);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        // Legitimate formatting survives.
        $this->assertStringContainsString('<strong>Bills included</strong>', $html);
    }

    public static function invalidListingFields(): array
    {
        return [
            'pin in the Indian Ocean' => [['latitude' => 2.5], 'inside Sri Lanka'],
            'lat/lng swapped' => [['latitude' => 79.97, 'longitude' => 6.91], 'inside Sri Lanka'],
            'pin in India' => [['longitude' => 77.59], 'inside Sri Lanka'],
            'non-https photo' => [['images' => ['http://example.com/a.jpg']], 'https:// image URL'],
            'not a url' => [['images' => ['javascript:alert(1)']], 'https:// image URL'],
            'absurd rent' => [['rent' => 50_000_000], 'rent looks too high'],
            'absurd capacity' => [['capacity' => 500], 'at most 50 slots'],
        ];
    }

    #[Test]
    #[DataProvider('invalidListingFields')]
    public function implausible_listing_data_is_rejected(array $override, string $expectedMessage): void
    {
        $this->signIn(User::factory()->owner()->create());

        // The API keeps the Node client's error shape: a flat list of human-readable messages.
        $message = $this->postJson('/api/posts', $this->payload($override))
            ->assertUnprocessable()
            ->json('message');

        $this->assertStringContainsString($expectedMessage, $message);
    }

    #[Test]
    public function validation_errors_are_also_returned_per_field_for_inline_display(): void
    {
        $this->signIn(User::factory()->owner()->create());

        $this->postJson('/api/posts', $this->payload(['latitude' => 2.5]))
            ->assertUnprocessable()
            ->assertJsonPath('fields', fn (array $fields) => isset($fields['postData.latitude']))
            ->assertJsonPath('errors.0', 'The map pin must be inside Sri Lanka.');
    }

    #[Test]
    public function coordinates_are_stored_and_returned_as_numbers(): void
    {
        $this->signIn(User::factory()->owner()->create());

        $this->postJson('/api/posts', $this->payload(['latitude' => '6.9150000', 'longitude' => '79.9700000']))
            ->assertCreated()
            ->assertJsonPath('latitude', 6.915)
            ->assertJsonPath('longitude', 79.97);
    }

    #[Test]
    public function near_search_returns_only_listings_within_the_radius_nearest_first(): void
    {
        // ~0.3 km, ~2 km and ~15 km (Colombo Fort) from the campus; one listing has no pin.
        $close = Post::factory()->create(['title' => 'Close', 'latitude' => 6.9160, 'longitude' => 79.9705]);
        $walk = Post::factory()->create(['title' => 'Walkable', 'latitude' => 6.9300, 'longitude' => 79.9640]);
        Post::factory()->create(['title' => 'Far', 'latitude' => 6.9344, 'longitude' => 79.8428]);
        Post::factory()->create(['title' => 'No pin', 'latitude' => null, 'longitude' => null]);

        $response = $this->getJson('/api/posts?'.http_build_query([...self::CAMPUS, 'radiusKm' => 5]))->assertOk();

        $this->assertSame([$close->id, $walk->id], array_column($response->json(), 'id'));
        $this->assertLessThan(1, $response->json('0.distanceKm'));
        $this->assertEqualsWithDelta(2.0, $response->json('1.distanceKm'), 0.5);
    }

    #[Test]
    public function near_search_combines_with_the_other_filters(): void
    {
        Post::factory()->create(['title' => 'Cheap', 'rent' => 15000, 'latitude' => 6.9160, 'longitude' => 79.9705]);
        Post::factory()->create(['title' => 'Pricey', 'rent' => 60000, 'latitude' => 6.9161, 'longitude' => 79.9706]);

        $titles = array_column(
            $this->getJson('/api/posts?'.http_build_query([...self::CAMPUS, 'radiusKm' => 3, 'maxPrice' => 30000]))->json(),
            'title',
        );

        $this->assertSame(['Cheap'], $titles);
    }

    #[Test]
    public function near_search_parameters_are_validated(): void
    {
        $this->assertStringContainsString('lng', $this->getJson('/api/posts?lat=6.9')->assertUnprocessable()->json('message'));
        $this->assertStringContainsString('radius', $this->getJson('/api/posts?lat=6.9&lng=79.9&radiusKm=500')->assertUnprocessable()->json('message'));
        $this->assertStringContainsString('lat', $this->getJson('/api/posts?lat=abc&lng=79.9')->assertUnprocessable()->json('message'));
    }

    #[Test]
    public function the_plain_listing_index_is_unchanged_without_near_parameters(): void
    {
        Post::factory()->count(3)->create();

        $response = $this->getJson('/api/posts')->assertOk();

        $this->assertCount(3, $response->json());
        $this->assertArrayNotHasKey('distanceKm', $response->json('0'));
    }
}
