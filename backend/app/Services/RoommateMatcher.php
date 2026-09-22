<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\RoommatePreference;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Scores how compatible two students are as roommates. Each factor yields 0–1 and is weighted
 * so the weights sum to 100. Missing data scores a neutral ~0.4 rather than penalising a
 * half-filled profile.
 */
class RoommateMatcher
{
    public const WEIGHTS = [
        'budget' => 25,
        'location' => 20,
        'sleepSchedule' => 10,
        'cleanlinessLevel' => 10,
        'studyHabit' => 10,
        'smokingAllowed' => 10,
        'foodPreference' => 5,
        'sociabilityLevel' => 5,
        'petsAllowed' => 5,
    ];

    private const MIN_SCORE = 35;

    private const MAX_RESULTS = 8;

    /**
     * Best matches for `$user` among other active students of the same gender.
     *
     * @return Collection<int, array{user: array<string, mixed>, score: int, reasons: list<string>}>
     */
    public function matchesFor(User $user, RoommatePreference $preference): Collection
    {
        return User::query()
            ->whereKeyNot($user->getKey())
            ->where('role', UserRole::Student)
            ->where('is_active', true)
            ->when($user->gender, fn ($query, $gender) => $query->where('gender', $gender))
            ->whereHas('roommatePreference')
            ->with('roommatePreference')
            ->get()
            ->map(fn (User $candidate) => $this->score($preference, $candidate, $candidate->roommatePreference))
            ->filter(fn (array $match) => $match['score'] > self::MIN_SCORE)
            ->sortByDesc('score')
            ->take(self::MAX_RESULTS)
            ->values();
    }

    /**
     * @return array{user: array<string, mixed>, score: int, reasons: list<string>}
     */
    public function score(RoommatePreference $source, User $candidateUser, RoommatePreference $candidate): array
    {
        $budget = $this->budgetOverlap($source, $candidate);
        $location = $this->location($source, $candidate);
        $sleep = $this->exactOrUnknown($source->sleep_schedule, $candidate->sleep_schedule);
        $cleanliness = $this->scaleCloseness($source->cleanliness_level, $candidate->cleanliness_level);
        $study = $this->exactOrUnknown($source->study_habit, $candidate->study_habit);
        $smoking = $this->booleanMatch($source->smoking_allowed, $candidate->smoking_allowed);
        $food = $this->exactOrUnknown($source->food_preference, $candidate->food_preference);
        $sociability = $this->scaleCloseness($source->sociability_level, $candidate->sociability_level);
        $pets = $this->booleanMatch($source->pets_allowed, $candidate->pets_allowed);

        $weighted = $budget * self::WEIGHTS['budget']
            + $location * self::WEIGHTS['location']
            + $sleep * self::WEIGHTS['sleepSchedule']
            + $cleanliness * self::WEIGHTS['cleanlinessLevel']
            + $study * self::WEIGHTS['studyHabit']
            + $smoking * self::WEIGHTS['smokingAllowed']
            + $food * self::WEIGHTS['foodPreference']
            + $sociability * self::WEIGHTS['sociabilityLevel']
            + $pets * self::WEIGHTS['petsAllowed'];

        $reasons = array_values(array_slice(array_filter([
            $budget >= 0.55 ? 'Budget ranges overlap well' : null,
            match (true) {
                $location >= 0.7 => 'Both prefer '.($source->preferred_area ?: $source->preferred_city),
                $location >= 0.4 => 'Location preferences are reasonably close',
                default => null,
            },
            $cleanliness >= 0.75 ? 'Similar cleanliness expectations' : null,
            $study === 1.0 ? 'Matching study habits' : null,
            $sleep === 1.0 ? 'Compatible sleep schedules' : null,
            $smoking === 1.0 ? 'Same smoking preference' : null,
            $pets === 1.0 ? 'Same pet preference' : null,
        ]), 0, 4));

        return [
            'user' => [
                'id' => $candidateUser->id,
                'username' => $candidateUser->username,
                'fullName' => $candidateUser->full_name,
                'avatar' => $candidateUser->avatar,
                'gender' => $candidateUser->gender,
                'university' => $candidate->university,
                'faculty' => $candidate->faculty,
                'yearOfStudy' => $candidate->year_of_study,
                'preferredCity' => $candidate->preferred_city,
                'preferredArea' => $candidate->preferred_area,
                'budgetMin' => $candidate->budget_min,
                'budgetMax' => $candidate->budget_max,
            ],
            'score' => (int) round($weighted),
            'reasons' => $reasons,
        ];
    }

    private function scaleCloseness(?int $a, ?int $b, int $maxDifference = 4): float
    {
        if ($a === null || $b === null) {
            return 0.4;
        }

        return max(0.0, 1 - abs($a - $b) / $maxDifference);
    }

    private function exactOrUnknown(?string $a, ?string $b): float
    {
        if (! $a || ! $b) {
            return 0.4;
        }

        return $a === $b ? 1.0 : 0.0;
    }

    private function booleanMatch(?bool $a, ?bool $b): float
    {
        if ($a === null || $b === null) {
            return 0.4;
        }

        return $a === $b ? 1.0 : 0.0;
    }

    /** Overlap of the two budget ranges relative to the span they cover together. */
    private function budgetOverlap(RoommatePreference $a, RoommatePreference $b): float
    {
        if (in_array(null, [$a->budget_min, $a->budget_max, $b->budget_min, $b->budget_max], true)) {
            return 0.35;
        }

        $overlap = max(0, min($a->budget_max, $b->budget_max) - max($a->budget_min, $b->budget_min));
        $span = max($a->budget_max, $b->budget_max) - min($a->budget_min, $b->budget_min);

        return $span <= 0 ? 0.0 : $overlap / $span;
    }

    private function location(RoommatePreference $a, RoommatePreference $b): float
    {
        $normalise = fn (?string $value) => $value !== null ? mb_strtolower(trim($value)) : null;

        [$areaA, $areaB] = [$normalise($a->preferred_area), $normalise($b->preferred_area)];
        [$cityA, $cityB] = [$normalise($a->preferred_city), $normalise($b->preferred_city)];

        return match (true) {
            $areaA && $areaB && $areaA === $areaB => 1.0,
            $cityA && $cityB && $cityA === $cityB => 0.7,
            ($cityA && $areaB && $cityA === $areaB) || ($cityB && $areaA && $cityB === $areaA) => 0.4,
            default => 0.0,
        };
    }
}
