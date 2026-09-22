<?php

namespace App\Models;

use App\Models\Concerns\SerializesCamelCase;
use Database\Factories\RoommatePreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'age', 'university', 'faculty', 'year_of_study', 'budget_min', 'budget_max',
    'preferred_city', 'preferred_area', 'sleep_schedule', 'cleanliness_level', 'study_habit',
    'smoking_allowed', 'pets_allowed', 'food_preference', 'sociability_level', 'notes',
])]
class RoommatePreference extends Model
{
    /** @use HasFactory<RoommatePreferenceFactory> */
    use HasFactory, HasUlids, SerializesCamelCase;

    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'budget_min' => 'integer',
            'budget_max' => 'integer',
            'cleanliness_level' => 'integer',
            'sociability_level' => 'integer',
            'smoking_allowed' => 'boolean',
            'pets_allowed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
