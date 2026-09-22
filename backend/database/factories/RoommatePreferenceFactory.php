<?php

namespace Database\Factories;

use App\Models\RoommatePreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoommatePreference>
 */
class RoommatePreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'age' => 22,
            'university' => 'University of Peradeniya',
            'faculty' => 'Engineering',
            'year_of_study' => '3rd Year',
            'budget_min' => 18000,
            'budget_max' => 30000,
            'preferred_city' => 'Kandy',
            'preferred_area' => 'Peradeniya',
            'sleep_schedule' => 'Sleeps before midnight',
            'cleanliness_level' => 4,
            'study_habit' => 'Quiet evenings',
            'smoking_allowed' => false,
            'pets_allowed' => false,
            'food_preference' => 'Vegetarian-friendly',
            'sociability_level' => 3,
        ];
    }
}
