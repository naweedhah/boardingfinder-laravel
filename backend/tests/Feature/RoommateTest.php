<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Models\RoommateMatch;
use App\Models\RoommatePreference;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoommateTest extends TestCase
{
    private function profile(array $overrides = []): array
    {
        return [
            'age' => 22, 'university' => 'University of Peradeniya', 'faculty' => 'Engineering',
            'yearOfStudy' => '3rd Year', 'budgetMin' => 18000, 'budgetMax' => 30000,
            'preferredCity' => 'Kandy', 'preferredArea' => 'Peradeniya',
            'sleepSchedule' => 'Sleeps before midnight', 'cleanlinessLevel' => 4,
            'studyHabit' => 'Quiet evenings', 'smokingAllowed' => false, 'petsAllowed' => false,
            'foodPreference' => 'Vegetarian-friendly', 'sociabilityLevel' => 3, 'notes' => 'Calm please',
            ...$overrides,
        ];
    }

    private function student(Gender $gender = Gender::Female, array $preference = []): User
    {
        $user = User::factory()->create(['gender' => $gender]);
        RoommatePreference::factory()->create(['user_id' => $user->id, ...$preference]);

        return $user;
    }

    #[Test]
    public function there_is_no_profile_until_one_is_saved(): void
    {
        $this->signIn();

        $this->getJson('/api/users/roommate-profile')->assertOk()->assertJson([]);
        $this->getJson('/api/users/roommate-matches')->assertOk()->assertExactJson([]);
    }

    #[Test]
    public function saving_the_profile_upserts_it_and_accepts_form_style_values(): void
    {
        $me = $this->signIn(User::factory()->create(['gender' => Gender::Female]));

        $this->putJson('/api/users/roommate-profile', $this->profile(['smokingAllowed' => 'false', 'petsAllowed' => 'true', 'age' => '23', 'notes' => '']))
            ->assertOk()
            ->assertJsonPath('age', 23)
            ->assertJsonPath('smokingAllowed', false)
            ->assertJsonPath('petsAllowed', true)
            ->assertJsonPath('notes', null);

        $this->putJson('/api/users/roommate-profile', $this->profile(['faculty' => 'Science']))->assertOk();

        $this->assertSame(1, RoommatePreference::where('user_id', $me->id)->count());
        $this->getJson('/api/users/roommate-profile')->assertJsonPath('faculty', 'Science')->assertJsonPath('userId', $me->id);
    }

    #[Test]
    #[DataProvider('invalidProfiles')]
    public function profile_validation_gives_field_specific_messages(array $override, string $message): void
    {
        $this->signIn();

        $this->putJson('/api/users/roommate-profile', $this->profile($override))
            ->assertStatus(422)->assertJsonPath('message', $message);
    }

    public static function invalidProfiles(): array
    {
        return [
            'too young' => [['age' => 15], 'Age must be between 16 and 60.'],
            'unknown university' => [['university' => 'Hogwarts'], 'Select a valid university.'],
            'short faculty' => [['faculty' => 'X'], 'Faculty must be at least 2 characters long.'],
            'no year' => [['yearOfStudy' => ''], 'Select your year of study.'],
            'budget too low' => [['budgetMin' => 4000], 'Enter a valid budget range with max budget above min budget.'],
            'inverted budget' => [['budgetMin' => 30000, 'budgetMax' => 20000], 'Enter a valid budget range with max budget above min budget.'],
            'unknown city' => [['preferredCity' => 'Gotham'], 'Select a valid preferred city.'],
            'short area' => [['preferredArea' => 'X'], 'Preferred area must be at least 2 characters long.'],
            'bad sleep' => [['sleepSchedule' => 'Never'], 'Select a valid sleep schedule.'],
            'cleanliness 6' => [['cleanlinessLevel' => 6], 'Cleanliness level must be between 1 and 5.'],
            'bad study' => [['studyHabit' => 'Loud'], 'Select a valid study habit.'],
            'no smoking answer' => [['smokingAllowed' => null], 'Select a smoking preference.'],
            'no pets answer' => [['petsAllowed' => ''], 'Select a pets preference.'],
            'bad food' => [['foodPreference' => 'Rocks'], 'Select a valid food preference.'],
            'sociability 0' => [['sociabilityLevel' => 0], 'Sociability level must be between 1 and 5.'],
            'long notes' => [['notes' => str_repeat('a', 301)], 'Notes must stay within 300 characters.'],
        ];
    }

    #[Test]
    public function matches_only_include_active_same_gender_students_ranked_by_score(): void
    {
        $me = $this->student(Gender::Female);
        $twin = $this->student(Gender::Female);
        $decent = $this->student(Gender::Female, ['sleep_schedule' => 'Late sleeper', 'study_habit' => 'Group study', 'smoking_allowed' => true, 'budget_min' => 25000, 'budget_max' => 45000]);
        $this->student(Gender::Male);                                                       // wrong gender
        $suspended = $this->student(Gender::Female);
        $suspended->update(['is_active' => false]);
        $owner = $this->student(Gender::Female);
        $owner->update(['role' => 'boardingOwner']); // not a student
        User::factory()->create(['gender' => Gender::Female]);                              // no profile
        $poor = $this->student(Gender::Female, ['preferred_city' => 'Galle', 'preferred_area' => 'Fort', 'sleep_schedule' => 'Early riser', 'study_habit' => 'Group study', 'smoking_allowed' => true, 'pets_allowed' => true, 'cleanliness_level' => 1, 'budget_min' => 80000, 'budget_max' => 90000, 'food_preference' => 'Halal-friendly', 'sociability_level' => 5]);

        $this->signIn($me);
        $matches = $this->getJson('/api/users/roommate-matches')->assertOk();

        $ids = array_column($matches->json(), 'user');
        $this->assertSame([$twin->id, $decent->id], array_column($ids, 'id'), 'best match first; poor/ineligible matches excluded');
        $this->assertNotContains($poor->id, array_column($ids, 'id'));

        $matches->assertJsonPath('0.score', 100)
            ->assertJsonPath('0.user.university', 'University of Peradeniya')
            ->assertJsonPath('0.user.username', $twin->username)
            ->assertJsonPath('0.reasons', [
                'Budget ranges overlap well', 'Both prefer Peradeniya', 'Similar cleanliness expectations', 'Matching study habits',
            ]);
        $this->assertLessThan(100, $matches->json('1.score'));
    }

    #[Test]
    public function saving_a_profile_stores_top_pairs_once_and_notifies_strong_matches_both_ways(): void
    {
        $twin = $this->student(Gender::Female);
        $me = $this->signIn(User::factory()->create(['gender' => Gender::Female]));

        $this->putJson('/api/users/roommate-profile', $this->profile())->assertOk();
        $this->putJson('/api/users/roommate-profile', $this->profile())->assertOk();

        $this->assertSame(1, RoommateMatch::count());
        $pair = RoommateMatch::first();
        $ids = [$me->id, $twin->id];
        sort($ids, SORT_STRING);
        $this->assertSame($ids, [$pair->user_a_id, $pair->user_b_id]);
        $this->assertSame(100.0, $pair->score);
        $this->assertTrue($pair->is_active);

        $this->assertDatabaseHas('user_notifications', ['user_id' => $me->id, 'type' => 'roommateMatchFound', 'title' => 'High compatibility roommate matches found', 'message' => 'You now have 1 strong roommate match ready to review.']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $twin->id, 'type' => 'roommateMatchFound', 'title' => 'A compatible roommate just appeared']);
    }
}
