<?php
namespace Tests\Feature\Livewire;

use App\Livewire\Traveler\SavedTrips;
use App\Models\Expense;
use App\Models\GroupMember;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SavedTripsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_trips_page_loads(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/saved-trips')->assertStatus(200);
    }

    public function test_trip_card_shows_zero_spend_with_no_expenses(): void
    {
        $user = User::factory()->create();
        Trip::factory()->create(['user_id' => $user->id, 'budget_limit' => 10000]);

        Livewire::actingAs($user)->test(SavedTrips::class)
            ->assertSee('0%');
    }

    public function test_trip_card_computes_actual_spent_and_percentage(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $user->id, 'budget_limit' => 10000]);
        Expense::create(['trip_id' => $trip->id, 'user_id' => $user->id, 'amount' => 3000, 'category' => 'Food', 'expense_date' => '2026-08-01']);
        Expense::create(['trip_id' => $trip->id, 'user_id' => $user->id, 'amount' => 2000, 'category' => 'Transportation', 'expense_date' => '2026-08-02']);

        // 5000 / 10000 = 50%
        $component = Livewire::actingAs($user)->test(SavedTrips::class);
        $trips = collect($component->viewData('trips'))->keyBy('id');
        $this->assertSame(5000.0, (float) $trips[$trip->id]->actual_spent);
        $this->assertSame(50, (int) $trips[$trip->id]->spend_pct);
    }

    public function test_spend_percentage_caps_at_100_when_over_budget(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->create(['user_id' => $user->id, 'budget_limit' => 1000]);
        Expense::create(['trip_id' => $trip->id, 'user_id' => $user->id, 'amount' => 5000, 'category' => 'Food', 'expense_date' => '2026-08-01']);

        Livewire::actingAs($user)->test(SavedTrips::class)
            ->assertSee('100%');
    }

    public function test_actual_spent_only_counts_the_matching_trip(): void
    {
        $user  = User::factory()->create();
        $trip1 = Trip::factory()->create(['user_id' => $user->id, 'budget_limit' => 10000]);
        $trip2 = Trip::factory()->create(['user_id' => $user->id, 'budget_limit' => 10000]);
        Expense::create(['trip_id' => $trip1->id, 'user_id' => $user->id, 'amount' => 4000, 'category' => 'Food', 'expense_date' => '2026-08-01']);
        Expense::create(['trip_id' => $trip2->id, 'user_id' => $user->id, 'amount' => 1000, 'category' => 'Food', 'expense_date' => '2026-08-01']);

        $component = Livewire::actingAs($user)->test(SavedTrips::class);
        $trips     = collect($component->viewData('trips'))->keyBy('id');

        $this->assertSame(4000.0, $trips[$trip1->id]->actual_spent);
        $this->assertSame(1000.0, $trips[$trip2->id]->actual_spent);
    }

    // Ongoing trip, foreign destination_currency on file, live rate reachable
    // — the card should show yen, not pesos.
    public function test_ongoing_trip_shows_the_destination_currency_when_the_live_rate_is_reachable(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'api.twelvedata.com/*' => Http::response(['symbol' => 'JPY/PHP', 'rate' => 0.387], 200),
        ]);
        Trip::factory()->create([
            'user_id'              => $user->id,
            'status'                => 'active',
            'budget_limit'          => 60000,
            'destination_currency'  => 'JPY',
        ]);

        $html = Livewire::actingAs($user)->test(SavedTrips::class)->html();

        $this->assertStringContainsString('JPY', $html);
        $this->assertStringContainsString(number_format(round(60000 / 0.387)), $html);
    }

    // Same Ongoing + foreign-currency trip, but the live rate lookup fails —
    // the card must fall back to pesos instead of showing nothing or erroring.
    public function test_ongoing_trip_falls_back_to_pesos_when_the_live_rate_is_unavailable(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'api.twelvedata.com/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);
        Trip::factory()->create([
            'user_id'              => $user->id,
            'status'                => 'active',
            'budget_limit'          => 60000,
            'destination_currency'  => 'JPY',
        ]);

        $html = Livewire::actingAs($user)->test(SavedTrips::class)->html();

        $this->assertStringContainsString('60,000', $html);
        $this->assertStringNotContainsString('JPY', $html);
    }

    // An Upcoming trip with a destination_currency on file now converts too
    // — not just Ongoing ones. Only Draft and Past stay peso-only.
    public function test_upcoming_trip_shows_the_destination_currency_when_the_live_rate_is_reachable(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'api.twelvedata.com/*' => Http::response(['symbol' => 'JPY/PHP', 'rate' => 0.387], 200),
        ]);
        Trip::factory()->create([
            'user_id'              => $user->id,
            'status'                => 'upcoming',
            'budget_limit'          => 60000,
            'destination_currency'  => 'JPY',
        ]);

        $html = Livewire::actingAs($user)->test(SavedTrips::class)->html();

        $this->assertStringContainsString('JPY', $html);
        $this->assertStringContainsString(number_format(round(60000 / 0.387)), $html);
    }

    public function test_upcoming_trip_falls_back_to_pesos_when_the_live_rate_is_unavailable(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'api.twelvedata.com/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);
        Trip::factory()->create([
            'user_id'              => $user->id,
            'status'                => 'upcoming',
            'budget_limit'          => 60000,
            'destination_currency'  => 'JPY',
        ]);

        $html = Livewire::actingAs($user)->test(SavedTrips::class)->html();

        $this->assertStringContainsString('60,000', $html);
        $this->assertStringNotContainsString('JPY', $html);
    }

    // An Ongoing trip with no destination_currency on file (e.g. a trip
    // saved before that data started being captured) must still show pesos.
    public function test_ongoing_trip_without_a_destination_currency_shows_pesos(): void
    {
        $user = User::factory()->create();
        Trip::factory()->create([
            'user_id'      => $user->id,
            'status'        => 'active',
            'budget_limit'  => 60000,
        ]);

        $html = Livewire::actingAs($user)->test(SavedTrips::class)->html();

        $this->assertStringContainsString('60,000', $html);
    }

    // Draft and Past trips are unaffected by any of this — pesos always.
    public function test_draft_trip_with_a_destination_currency_still_shows_pesos(): void
    {
        $user = User::factory()->create();
        Trip::factory()->create([
            'user_id'              => $user->id,
            'status'                => 'draft',
            'destination'           => 'Draft',
            'budget_limit'          => 60000,
            'destination_currency'  => 'JPY',
        ]);

        $html = Livewire::actingAs($user)->test(SavedTrips::class)->html();

        $this->assertStringNotContainsString('JPY', $html);
    }

    public function test_past_trip_with_a_destination_currency_still_shows_pesos(): void
    {
        $user = User::factory()->create();
        Trip::factory()->create([
            'user_id'              => $user->id,
            'status'                => 'past',
            'budget_limit'          => 60000,
            'destination_currency'  => 'JPY',
        ]);

        $html = Livewire::actingAs($user)->test(SavedTrips::class)->html();

        $this->assertStringContainsString('60,000', $html);
        $this->assertStringNotContainsString('JPY', $html);
    }

    // ── A member (not the owner) of a group trip ─────────────────────────

    /** @return array{0: User, 1: User, 2: User, 3: Trip} owner, member, other member, trip */
    private function groupTrip(): array
    {
        $owner  = User::factory()->create();
        $member = User::factory()->create();
        $other  = User::factory()->create();
        $trip   = Trip::factory()->create([
            'user_id' => $owner->id, 'trip_name' => 'Seoul Trip', 'travel_type' => 'Group', 'status' => 'upcoming',
        ]);
        GroupMember::create(['trip_id' => $trip->id, 'user_id' => $member->id]);
        GroupMember::create(['trip_id' => $trip->id, 'user_id' => $other->id]);

        return [$owner, $member, $other, $trip];
    }

    public function test_member_sees_delete_trip_in_the_card_menu(): void
    {
        [, $member] = $this->groupTrip();

        Livewire::actingAs($member)->test(SavedTrips::class)
            ->assertSee('Delete Trip');
    }

    public function test_member_edit_modal_hides_type_remove_and_add_controls(): void
    {
        [, $member, , $trip] = $this->groupTrip();

        Livewire::actingAs($member)->test(SavedTrips::class)
            ->call('openEditName', $trip->id)
            ->assertSet('editIsOwner', false)
            ->assertSee('Group Members')
            ->assertDontSee('title="Remove member"', false)
            ->assertDontSee('wire:click="lookupMember"', false)
            ->assertDontSee("\$set('editType'", false);
    }

    public function test_owner_edit_modal_keeps_type_remove_and_add_controls(): void
    {
        [$owner, , , $trip] = $this->groupTrip();

        Livewire::actingAs($owner)->test(SavedTrips::class)
            ->call('openEditName', $trip->id)
            ->assertSet('editIsOwner', true)
            ->assertSee('title="Remove member"', false)
            ->assertSee('wire:click="lookupMember"', false)
            ->assertSee("\$set('editType'", false);
    }

    public function test_member_save_changes_only_name_and_status(): void
    {
        [, $member, $other, $trip] = $this->groupTrip();

        Livewire::actingAs($member)->test(SavedTrips::class)
            ->call('openEditName', $trip->id)
            ->set('editNameValue', 'Renamed by member')
            ->set('editStatus', 'active')
            ->set('editType', 'Solo')          // would drop everyone if honoured
            ->call('saveEditName');

        $trip->refresh();
        $this->assertSame('Renamed by member', $trip->trip_name);
        $this->assertSame('active', $trip->getRawOriginal('status'));
        $this->assertSame('Group', $trip->travel_type);
        $this->assertSame(2, GroupMember::where('trip_id', $trip->id)->count());
    }

    public function test_member_cannot_remove_or_add_members(): void
    {
        [, $member, $other, $trip] = $this->groupTrip();
        $stranger = User::factory()->create();

        Livewire::actingAs($member)->test(SavedTrips::class)
            ->call('openEditName', $trip->id)
            ->call('removeSavedMember', $other->id)
            ->set('memberEmail', $stranger->email)
            ->call('lookupMember')
            ->assertSet('pendingMembers', []);

        $this->assertTrue(GroupMember::where('trip_id', $trip->id)->where('user_id', $other->id)->exists());
    }

    public function test_member_delete_removes_only_them_and_keeps_the_trip(): void
    {
        [, $member, $other, $trip] = $this->groupTrip();

        Livewire::actingAs($member)->test(SavedTrips::class)
            ->call('confirmDelete', $trip->id)
            ->assertSet('deleteIsLeave', true)
            ->assertSee('and also the trip will be removed from Saved Trips.')
            ->call('deleteTrip')
            ->assertDontSee('Seoul Trip');

        $this->assertNotNull(Trip::find($trip->id));
        $this->assertFalse(GroupMember::where('trip_id', $trip->id)->where('user_id', $member->id)->exists());
        $this->assertTrue(GroupMember::where('trip_id', $trip->id)->where('user_id', $other->id)->exists());
    }

    public function test_last_member_leaving_turns_the_trip_back_to_solo(): void
    {
        $owner  = User::factory()->create();
        $member = User::factory()->create();
        $trip   = Trip::factory()->create(['user_id' => $owner->id, 'travel_type' => 'Group', 'num_travelers' => 2]);
        GroupMember::create(['trip_id' => $trip->id, 'user_id' => $member->id]);

        Livewire::actingAs($member)->test(SavedTrips::class)
            ->call('confirmDelete', $trip->id)
            ->call('deleteTrip');

        $trip->refresh();
        $this->assertSame('Solo', $trip->travel_type);
        $this->assertSame(1, (int) $trip->num_travelers);

        // And the owner's card says so.
        $html = Livewire::actingAs($owner)->test(SavedTrips::class)->html();
        $this->assertStringContainsString('SOLO', $html);
        $this->assertStringNotContainsString('>GROUP<', $html);
    }

    public function test_a_member_leaving_while_others_remain_keeps_it_group(): void
    {
        [, $member, , $trip] = $this->groupTrip();

        Livewire::actingAs($member)->test(SavedTrips::class)
            ->call('confirmDelete', $trip->id)
            ->call('deleteTrip');

        $this->assertSame('Group', $trip->fresh()->travel_type);
    }

    public function test_owner_removing_the_last_member_turns_the_trip_back_to_solo(): void
    {
        $owner  = User::factory()->create();
        $member = User::factory()->create();
        $trip   = Trip::factory()->create(['user_id' => $owner->id, 'travel_type' => 'Group', 'num_travelers' => 2]);
        GroupMember::create(['trip_id' => $trip->id, 'user_id' => $member->id]);

        Livewire::actingAs($owner)->test(SavedTrips::class)
            ->call('openEditName', $trip->id)
            ->assertSet('editType', 'Group')
            ->call('removeSavedMember', $member->id)
            ->assertSet('editType', 'Solo')
            ->call('saveEditName');

        $trip->refresh();
        $this->assertSame('Solo', $trip->travel_type);
        $this->assertSame(1, (int) $trip->num_travelers);
    }

    public function test_planner_group_trip_without_app_members_is_left_alone(): void
    {
        // A Group trip straight from the planner: four travellers, none of
        // them app accounts. Opening and saving Edit Trip must not touch it.
        $owner = User::factory()->create();
        $trip  = Trip::factory()->create(['user_id' => $owner->id, 'travel_type' => 'Group', 'num_travelers' => 4]);

        Livewire::actingAs($owner)->test(SavedTrips::class)
            ->call('openEditName', $trip->id)
            ->call('saveEditName');

        $trip->refresh();
        $this->assertSame('Group', $trip->travel_type);
        $this->assertSame(4, (int) $trip->num_travelers);
    }

    public function test_owner_delete_still_deletes_the_trip(): void
    {
        [$owner, , , $trip] = $this->groupTrip();

        Livewire::actingAs($owner)->test(SavedTrips::class)
            ->call('confirmDelete', $trip->id)
            ->assertSet('deleteIsLeave', false)
            ->assertSee('will be permanently deleted')
            ->call('deleteTrip');

        $this->assertNull(Trip::find($trip->id));
    }

    public function test_outsider_cannot_open_delete_for_someone_elses_trip(): void
    {
        [, , , $trip] = $this->groupTrip();
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)->test(SavedTrips::class)
            ->call('confirmDelete', $trip->id)
            ->assertSet('deleteTripId', null);

        $this->assertNotNull(Trip::find($trip->id));
    }
}
