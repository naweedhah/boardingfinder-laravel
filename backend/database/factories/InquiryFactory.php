<?php

namespace Database\Factories;

use App\Enums\InquiryStatus;
use App\Enums\InquiryType;
use App\Models\Inquiry;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inquiry>
 */
class InquiryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => User::factory(),
            'post_id' => Post::factory(),
            'owner_id' => fn (array $attributes) => Post::find($attributes['post_id'])->owner_id,
            'type' => InquiryType::View,
            'status' => InquiryStatus::Pending,
        ];
    }
}
