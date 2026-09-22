<?php

namespace Tests\Unit;

use App\Models\RoommatePreference;
use App\Models\User;
use App\Services\RoommateMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RoommateMatcherTest extends TestCase
{
    private function pref(array $attributes = []): RoommatePreference
    {
        return new RoommatePreference([
            'budget_min' => 20000, 'budget_max' => 30000, 'preferred_city' => 'Kandy', 'preferred_area' => 'Peradeniya',
            'sleep_schedule' => 'Early riser', 'cleanliness_level' => 3, 'study_habit' => 'Flexible',
            'smoking_allowed' => false, 'pets_allowed' => false, 'food_preference' => 'Halal-friendly', 'sociability_level' => 3,
            ...$attributes,
        ]);
    }

    private function score(RoommatePreference $a, RoommatePreference $b): int
    {
        return (new RoommateMatcher)->score($a, new User(['username' => 'x']), $b)['score'];
    }

    #[Test]
    public function identical_profiles_score_one_hundred(): void
    {
        $this->assertSame(100, $this->score($this->pref(), $this->pref()));
    }

    #[Test]
    public function the_weights_sum_to_one_hundred(): void
    {
        $this->assertSame(100, array_sum(RoommateMatcher::WEIGHTS));
    }

    #[Test]
    public function each_factor_contributes_its_weight(): void
    {
        $base = $this->pref();

        $this->assertSame(100 - 25, $this->score($base, $this->pref(['budget_min' => 50000, 'budget_max' => 60000])));
        $this->assertSame(100 - 20, $this->score($base, $this->pref(['preferred_city' => 'Galle', 'preferred_area' => 'Fort'])));
        $this->assertSame(100 - 10, $this->score($base, $this->pref(['sleep_schedule' => 'Late sleeper'])));
        $this->assertSame(100 - 10, $this->score($base, $this->pref(['study_habit' => 'Group study'])));
        $this->assertSame(100 - 10, $this->score($base, $this->pref(['smoking_allowed' => true])));
        $this->assertSame(100 - 5, $this->score($base, $this->pref(['food_preference' => 'Vegan-friendly'])));
        $this->assertSame(100 - 5, $this->score($base, $this->pref(['pets_allowed' => true])));
    }

    #[Test]
    public function scales_lose_points_in_proportion_to_the_gap(): void
    {
        // cleanliness 3 vs 5: gap 2 of max 4 => half of 10 points lost.
        $this->assertSame(95, $this->score($this->pref(), $this->pref(['cleanliness_level' => 5])));
        $this->assertSame(99, $this->score($this->pref(), $this->pref(['sociability_level' => 4]))); // 5 * 0.25 = 1.25 lost => 98.75, rounded
    }

    #[Test]
    public function partially_overlapping_budgets_score_by_overlap_over_span(): void
    {
        // 20-30k vs 25-35k: overlap 5k of a 15k span => 1/3 of 25 points.
        $this->assertSame(100 - 25 + 8, $this->score($this->pref(), $this->pref(['budget_min' => 25000, 'budget_max' => 35000])));
    }

    #[Test]
    public function missing_answers_are_scored_neutrally_rather_than_as_mismatches(): void
    {
        $sparse = $this->pref(['sleep_schedule' => null, 'smoking_allowed' => null, 'cleanliness_level' => null, 'budget_min' => null]);

        $score = $this->score($this->pref(), $sparse);

        // budget .35*25 + location 20 + sleep .4*10 + clean .4*10 + study 10 + smoking .4*10 + food 5 + social 5 + pets 5
        $this->assertSame((int) round(8.75 + 20 + 4 + 4 + 10 + 4 + 5 + 5 + 5), $score);
    }

    #[Test]
    public function locations_match_by_area_then_city_then_cross_naming(): void
    {
        $base = $this->pref(['preferred_city' => 'Kandy', 'preferred_area' => 'Peradeniya']);

        $this->assertSame(100 - 6, $this->score($base, $this->pref(['preferred_area' => 'Hantana'])));                                  // same city: 0.7
        $this->assertSame(100 - 12, $this->score($base, $this->pref(['preferred_city' => 'Galle', 'preferred_area' => 'Kandy'])));       // city == other's area: 0.4
        $this->assertSame(100, $this->score($base, $this->pref(['preferred_city' => ' kandy ', 'preferred_area' => 'PERADENIYA'])));     // trims + ignores case
    }

    #[Test]
    public function reasons_explain_the_strongest_signals_and_are_capped_at_four(): void
    {
        $result = (new RoommateMatcher)->score($this->pref(), new User(['username' => 'x']), $this->pref());

        $this->assertCount(4, $result['reasons']);
        $this->assertSame('Budget ranges overlap well', $result['reasons'][0]);
        $this->assertSame('Both prefer Peradeniya', $result['reasons'][1]);
    }
}
