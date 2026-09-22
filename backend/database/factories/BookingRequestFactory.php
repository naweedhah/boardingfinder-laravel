<?php

namespace Database\Factories;

use App\Enums\BookingRequestStatus;
use App\Models\BookingRequest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingRequest>
 */
class BookingRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => User::factory(),
            'post_id' => Post::factory(),
            // Keep the denormalised owner in step with the listing.
            'owner_id' => fn (array $attributes) => Post::find($attributes['post_id'])->owner_id,
            'status' => BookingRequestStatus::Pending,
        ];
    }
}
