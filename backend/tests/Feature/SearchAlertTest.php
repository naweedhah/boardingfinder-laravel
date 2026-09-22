<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Models\Watchlist;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchAlertTest extends TestCase
{
    #[Test]
    public function a_user_saves_lists_and_deletes_search_alerts(): void
    {
        $me = $this->signIn();

        $id = $this->postJson('/api/users/watchlists/searches', [
            'city' => 'Kandy', 'area' => '', 'minBudget' => '15000', 'maxBudget' => '30000',
            'preferredTenantGender' => 'female', 'boardingType' => '', 'minCapacity' => '',
            'wifiRequired' => true, 'mealsRequired' => false, 'attachedBathroomNeeded' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('city', 'Kandy')
            ->assertJsonPath('area', null)
            ->assertJsonPath('minBudget', 15000)
            ->assertJsonPath('wifiRequired', true)
            ->assertJsonPath('isActive', true)
            ->assertJsonPath('boardingType', null)
            ->json('id');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $me->id, 'title' => 'Search alert saved', 'message' => "We'll notify you about matching boardings in Kandy."]);

        $this->getJson('/api/users/watchlists/searches')->assertJsonCount(1)->assertJsonPath('0.id', $id);

        // Someone else's alert (and per-listing waitlist entries) are invisible and untouchable.
        $foreign = Watchlist::factory()->create();
        $this->deleteJson("/api/users/watchlists/searches/{$foreign->id}")->assertNotFound()->assertJson(['message' => 'Saved alert not found.']);
        $waitlist = Watchlist::factory()->create(['user_id' => $me->id, 'post_id' => Post::factory()]);
        $this->deleteJson("/api/users/watchlists/searches/{$waitlist->id}")->assertNotFound();

        $this->deleteJson("/api/users/watchlists/searches/{$id}")->assertOk()->assertJson(['message' => 'Saved alert deleted.']);
        $this->assertDatabaseMissing('watchlists', ['id' => $id]);
    }

    #[Test]
    public function alert_validation_matches_the_form_rules(): void
    {
        $this->signIn();

        $this->postJson('/api/users/watchlists/searches', ['city' => ''])->assertStatus(422)->assertJson(['message' => 'Preferred city is required.']);
        $this->postJson('/api/users/watchlists/searches', ['city' => 'Kandy', 'minBudget' => 30000, 'maxBudget' => 20000])
            ->assertStatus(422)->assertJson(['message' => 'Max budget must be greater than min budget.']);
        $this->postJson('/api/users/watchlists/searches', ['city' => 'Kandy', 'preferredTenantGender' => 'robot'])
            ->assertStatus(422)->assertJson(['message' => 'Invalid preferred tenant gender.']);
        $this->postJson('/api/users/watchlists/searches', ['city' => 'Kandy', 'boardingType' => 'castle'])
            ->assertStatus(422)->assertJson(['message' => 'Invalid boarding type.']);

        // A max budget on its own is fine.
        $this->postJson('/api/users/watchlists/searches', ['city' => 'Kandy', 'maxBudget' => 20000])->assertCreated();
    }

    #[Test]
    public function saved_search_matching_covers_every_criterion(): void
    {
        $post = Post::factory()->withDetail(['wifi' => true, 'meals_provided' => false, 'attached_bathroom' => false])->create([
            'rent' => 25000, 'capacity' => 2, 'preferred_tenant_gender' => 'female', 'boarding_type' => 'annex',
        ])->load('postDetail');

        $matches = fn (array $criteria) => (new Watchlist(['is_active' => true, ...$criteria]))->matches($post);

        $this->assertTrue($matches([]));
        $this->assertTrue($matches(['city' => 'Kandy', 'area' => 'Peradeniya', 'min_budget' => 20000, 'max_budget' => 25000, 'min_capacity' => 2, 'wifi_required' => true, 'boarding_type' => 'annex', 'preferred_tenant_gender' => 'female']));
        $this->assertTrue($matches(['preferred_tenant_gender' => 'any']));

        $this->assertFalse($matches(['city' => 'Colombo']));
        $this->assertFalse($matches(['area' => 'Hantana']));
        $this->assertFalse($matches(['min_budget' => 26000]));
        $this->assertFalse($matches(['max_budget' => 24000]));
        $this->assertFalse($matches(['min_capacity' => 3]));
        $this->assertFalse($matches(['boarding_type' => 'hostel']));
        $this->assertFalse($matches(['preferred_tenant_gender' => 'male']));
        $this->assertFalse($matches(['meals_required' => true]));
        $this->assertFalse($matches(['attached_bathroom_needed' => true]));
        $this->assertFalse($matches(['is_active' => false]));
        $this->assertFalse($matches(['post_id' => 'specific-listing']));
    }

    #[Test]
    public function three_watchers_in_one_area_trigger_a_demand_spike_alert_when_a_listing_appears(): void
    {
        $watchers = User::factory()->count(3)->create();
        foreach ($watchers as $watcher) {
            Watchlist::factory()->create(['user_id' => $watcher->id, 'city' => 'Kandy', 'area' => 'Peradeniya']);
        }
        $owner = $this->signIn(User::factory()->owner()->create());

        $this->postJson('/api/posts', [
            'postData' => ['title' => 'Fresh listing', 'rent' => 90000, 'address' => 'x', 'city' => 'Kandy', 'area' => 'Peradeniya', 'capacity' => 1, 'boardingType' => 'annex', 'latitude' => '7.2', 'longitude' => '80.6'],
            'postDetail' => ['description' => 'A spacious annex with plenty of natural light and a garden view for students.'],
        ])->assertCreated();

        foreach ($watchers as $watcher) {
            $this->assertDatabaseHas('user_notifications', ['user_id' => $watcher->id, 'type' => 'demandSpike', 'title' => 'Demand is rising in Peradeniya']);
        }
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $owner->id]);
    }
}
