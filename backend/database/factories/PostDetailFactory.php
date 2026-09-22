<?php

namespace Database\Factories;

use App\Models\PostDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostDetail>
 */
class PostDetailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'description' => '<p>'.fake()->sentence(14).'</p>',
            'wifi' => true,
            'meals_provided' => false,
            'kitchen_access' => true,
            'nearest_campus' => 'University of Peradeniya',
            'distance_to_campus' => 650,
        ];
    }
}
