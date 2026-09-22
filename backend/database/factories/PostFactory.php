<?php

namespace Database\Factories;

use App\Enums\BoardingStatus;
use App\Enums\BoardingType;
use App\Enums\PreferredTenantGender;
use App\Models\Post;
use App\Models\PostDetail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory()->owner(),
            'title' => fake()->words(4, true).' boarding',
            'rent' => fake()->numberBetween(15000, 40000),
            'images' => ['https://example.com/room.jpg'],
            'address' => fake()->streetAddress(),
            'city' => 'Kandy',
            'area' => 'Peradeniya',
            'latitude' => '7.2552',
            'longitude' => '80.5970',
            'boarding_type' => BoardingType::SharedRoom,
            'status' => BoardingStatus::Available,
            'capacity' => 2,
            'bathroom_count' => 1,
            'preferred_tenant_gender' => PreferredTenantGender::Any,
        ];
    }

    /** Also creates the detail row (every real listing has one). */
    public function withDetail(array $detail = []): static
    {
        return $this->afterCreating(fn (Post $post) => PostDetail::factory()->for($post)->create($detail));
    }
}
