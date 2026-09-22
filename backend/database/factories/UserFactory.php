<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->regexify('[a-z]{6}_[0-9]{3}'),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'full_name' => fake()->name(),
            'phone' => fake()->numerify('07########'),
            'gender' => fake()->randomElement([Gender::Male, Gender::Female]),
            'role' => UserRole::Student,
            'is_active' => true,
            'is_verified' => true,
        ];
    }

    public function owner(): static
    {
        return $this->state(['role' => UserRole::BoardingOwner]);
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::Admin]);
    }

    public function suspended(): static
    {
        return $this->state(['is_active' => false]);
    }
}
