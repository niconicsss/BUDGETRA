<?php
namespace Tests\Feature\Livewire;

use App\Livewire\Traveler\TripPlannerWizard;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\CurrencyConverterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Generate Itinerary shows the budget in the traveller's own currency.
 *
 * The card converted on $tripCurrency alone, which is only set when a trip is
 * handed over from TARA. Plan manually and it was empty, so the range fell
 * through to a peso sign whatever country the account was registered with —
 * while the budget field back on step 1, labelled from budgetCurrency(), had
 * been asking for that same figure in CAD.
 */
class WizardBudgetCurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1 CAD = $rate PHP, fixed, so the arithmetic below is not at the mercy of
     * a live lookup and nothing here reaches the network.
     */
    private function fakeRate(?float $rate): void
    {
        // Called exactly once per test: Http::fake() APPENDS stubs rather than
        // replacing them, so a second call leaves the first one still matching
        // and the rate silently unchanged.
        config(['services.currency_converter.key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake([
            'api.twelvedata.com/*' => $rate === null
                ? Http::response([], 500)
                : Http::response(['rate' => $rate], 200),
        ]);

        // The service caches rates, and misses, for hours — and memoises them
        // in a STATIC that outlives the test. Both have to go or the second
        // test in this file quietly reuses the first one's answer.
        cache()->flush();
        $memo = new \ReflectionProperty(CurrencyConverterService::class, 'memo');
        $memo->setAccessible(true);
        $memo->setValue(null, []);
    }

    private function traveler(?string $country): User
    {
        $user = User::factory()->create(['country' => $country]);
        UserProfile::create(['user_id' => $user->id]);

        return $user;
    }

    public function test_the_helper_follows_the_country_from_registration(): void
    {
        $this->fakeRate(40.0);

        $this->actingAs($this->traveler('Canada'));

        $component = Livewire::test(TripPlannerWizard::class);

        $this->assertSame('CAD', $component->instance()->budgetCurrency());
        $this->assertStringStartsWith('C$', $component->instance()->budgetDisplayAmount(40000));
    }

    public function test_a_philippine_traveler_still_sees_pesos(): void
    {
        $this->actingAs($this->traveler('Philippines'));

        $component = Livewire::test(TripPlannerWizard::class);

        $this->assertSame('PHP', $component->instance()->budgetCurrency());
        $this->assertSame('₱40,000', $component->instance()->budgetDisplayAmount(40000));
    }

    public function test_an_account_with_no_country_falls_back_to_pesos(): void
    {
        // country is nullable at registration, so this is a real state.
        $this->actingAs($this->traveler(null));

        $component = Livewire::test(TripPlannerWizard::class);

        $this->assertSame('₱40,000', $component->instance()->budgetDisplayAmount(40000));
    }

    public function test_the_stored_pesos_are_converted_not_just_relabelled(): void
    {
        $this->fakeRate(40.0);

        // The bug this guards against is subtler than a wrong symbol: showing
        // "C$200,000" against a figure that is really 200,000 pesos overstates
        // the budget by the exchange rate.
        $this->actingAs($this->traveler('Canada'));

        $shown = Livewire::test(TripPlannerWizard::class)->instance()->budgetDisplayAmount(40000);

        $this->assertSame('C$1,000', $shown, '40,000 PHP at 40/CAD is C$1,000');
    }

    public function test_a_trip_carried_over_from_tara_keeps_its_own_currency(): void
    {
        // budgetCurrency() prefers tripCurrency when there is one — a trip
        // planned with TARA in JPY must not be relabelled into the home
        // currency just because this now has a fallback.
        $this->actingAs($this->traveler('Canada'));

        $component = Livewire::test(TripPlannerWizard::class)->set('tripCurrency', 'JPY');

        $this->assertSame('JPY', $component->instance()->budgetCurrency());
    }

    public function test_a_failed_rate_lookup_keeps_the_peso_sign(): void
    {
        // If the figure cannot be converted it is still pesos, so it must
        // still say pesos. Labelling an unconverted amount C$ is the exact
        // mistake this whole area exists to avoid.
        $this->fakeRate(null);

        $this->actingAs($this->traveler('Canada'));

        $shown = Livewire::test(TripPlannerWizard::class)->instance()->budgetDisplayAmount(40000);

        $this->assertStringStartsWith('₱', $shown);
    }

    public function test_the_card_no_longer_rolls_its_own_conversion(): void
    {
        // The template had a copy of this logic that checked only
        // tripCurrency. Two implementations of one rule is how they drifted.
        $template = file_get_contents(
            resource_path('views/livewire/traveler/trip-planner-wizard.blade.php')
        );

        $this->assertStringNotContainsString('$budDivisor', $template);
        $this->assertStringNotContainsString('$budSymbol', $template);
        $this->assertStringContainsString('$this->budgetRangeLabels()', $template);
    }

    /** A step-7 Generate Itinerary card for a traveller from $country. */
    private function card(string $country, array $profile, array $state): string
    {
        $user = User::factory()->create(['country' => $country]);
        UserProfile::create(['user_id' => $user->id] + $profile);
        $this->actingAs($user);

        $component = Livewire::test(TripPlannerWizard::class)
            ->set('planningMode', 'manual')
            ->set('manualFrom', 'Tokyo')
            ->set('manualTo', 'Taipei');
        foreach ($state as $key => $value) {
            $component->set($key, $value);
        }

        return $component->set('step', 7)->html();
    }

    public function test_the_low_end_is_the_profile_budget_exactly_as_typed(): void
    {
        // The reported case. ¥500,000 was banked as ₱199,675 at that day's
        // rate; going back out at a later one read ¥499,387.
        $this->fakeRate(0.39984);

        $html = $this->card('Japan', [
            'daily_budget'          => 199675,
            'daily_budget_currency' => 'JPY',
            'daily_budget_local'    => 500000,
        ], ['manualBudgetMin' => '300000', 'manualBudgetMax' => '300000', 'emergency' => 50000]);

        $this->assertStringContainsString('¥500,000 – ¥350,000', $html);
        $this->assertStringNotContainsString('¥499,387', $html);
    }

    public function test_the_high_end_is_the_typed_trip_budget_plus_the_emergency_fund(): void
    {
        // Both are typed in yen. Treated as pesos and "converted", 350,000
        // came out as ¥875,350.
        $this->fakeRate(0.39984);

        $html = $this->card('Japan', [], [
            'manualBudgetMin' => '300000', 'manualBudgetMax' => '300000', 'emergency' => 50000,
        ]);

        $this->assertStringContainsString('¥300,000 – ¥350,000', $html);
        $this->assertStringNotContainsString('¥875,350', $html);
    }

    public function test_a_converted_emergency_fund_still_shows_as_typed(): void
    {
        // A TARA trip in a foreign currency is the one case where the fund is
        // turned into pesos on confirm. The card must show what was typed.
        $this->fakeRate(41.0);

        $user = User::factory()->create(['country' => 'Philippines']);
        UserProfile::create(['user_id' => $user->id]);
        $this->actingAs($user);

        $component = Livewire::test(TripPlannerWizard::class)
            ->set('planningMode', 'manual')
            ->set('tripCurrency', 'CAD')
            ->set('manualBudgetMin', '3000')
            ->set('manualBudgetMax', '3000')
            ->set('emergency', 1000)
            ->call('confirmEmergencyFund');

        $component->assertSet('emergency', 41000.0)->assertSet('step', 7);
        $this->assertStringContainsString('C$3,000 – C$4,000', $component->html());
    }

    public function test_a_profile_typed_in_another_currency_is_converted_from_its_pesos(): void
    {
        // Profile kept in yen, trip carried over from TARA in CAD: the yen
        // figure means nothing in dollars, so only the pesos can be used.
        $this->fakeRate(40.0);

        $html = $this->card('Japan', [
            'daily_budget'          => 80000,
            'daily_budget_currency' => 'JPY',
            'daily_budget_local'    => 200000,
        ], ['tripCurrency' => 'CAD', 'manualBudgetMin' => '5000', 'manualBudgetMax' => '5000']);

        $this->assertStringContainsString('C$2,000 – C$5,000', $html);
    }

    public function test_a_philippine_profile_reads_the_same_as_before(): void
    {
        $html = $this->card('Philippines', ['daily_budget' => 20000], [
            'manualBudgetMin' => '30000', 'manualBudgetMax' => '30000', 'emergency' => 5000,
        ]);

        $this->assertStringContainsString('₱20,000 – ₱35,000', $html);
    }

    // ── Typed amounts are the traveller's currency, not pesos ─────────────
    //
    // The step-1 budget and the emergency fund are typed in budgetCurrency().
    // Prices, the AI's ₱ budget and every total are pesos. Below, ¥1 = ₱0.40,
    // so a ¥300,000 budget is ₱120,000 — and read as pesos it was 2.5× that.

    /** A Japanese traveller planning by hand, with no TARA currency carried over. */
    private function japaneseWizard(array $profile = [], ?float $rate = 0.4): \Livewire\Features\SupportTesting\Testable
    {
        $this->fakeRate($rate);

        $user = User::factory()->create(['country' => 'Japan']);
        UserProfile::create(['user_id' => $user->id] + $profile);
        $this->actingAs($user);

        return Livewire::test(TripPlannerWizard::class)
            ->set('planningMode', 'manual')
            ->set('manualFrom', 'Tokyo')
            ->set('manualTo', 'Taipei')
            ->set('startDate', now()->addDays(10)->toDateString())
            ->set('endDate', now()->addDays(14)->toDateString())
            ->set('manualBudgetMin', '200000')
            ->set('manualBudgetMax', '300000');
    }

    private function aiBounds(\Livewire\Features\SupportTesting\Testable $component): ?array
    {
        $m = new \ReflectionMethod(TripPlannerWizard::class, 'itineraryBudgetBounds');
        $m->setAccessible(true);

        return $m->invoke($component->instance());
    }

    public function test_a_home_currency_emergency_fund_is_converted_to_pesos(): void
    {
        // Only a TARA trip's fund used to be converted. Typed by hand, ¥50,000
        // went into the totals and the saved trip as ₱50,000.
        $this->japaneseWizard()
            ->set('emergency', 50000)
            ->call('confirmEmergencyFund')
            ->assertSet('emergency', 20000.0)
            ->assertSet('emergencyLocal', 50000.0)
            ->assertSet('step', 7);
    }

    public function test_the_card_shows_that_converted_fund_as_typed(): void
    {
        $html = $this->japaneseWizard()
            ->set('emergency', 50000)
            ->call('confirmEmergencyFund')
            ->html();

        $this->assertStringContainsString('¥200,000 – ¥350,000', $html);
    }

    public function test_the_ai_is_given_the_budget_in_pesos(): void
    {
        // The prompt says ₱. Handing it 300,000 asked for 2.5× the budget.
        $this->assertSame([80000, 120000], $this->aiBounds($this->japaneseWizard()));
    }

    public function test_the_profile_budget_is_already_pesos_and_stays_as_it_is(): void
    {
        $component = $this->japaneseWizard([
            'daily_budget' => 40000, 'daily_budget_currency' => 'JPY', 'daily_budget_local' => 100000,
        ]);

        $this->assertSame([40000, 120000], $this->aiBounds($component));
    }

    public function test_no_live_rate_means_no_budget_rather_than_a_guess(): void
    {
        $this->assertNull($this->aiBounds($this->japaneseWizard([], null)));
    }

    public function test_step_8_measures_the_itinerary_against_the_budget_in_pesos(): void
    {
        // ₱150,000 of activities against ¥300,000 (₱120,000) is over. Read as
        // ₱300,000, the budget made it look comfortably under.
        $plan = ['days' => [['day' => 1, 'activities' => [
            ['time' => '09:00', 'title' => 'Day tour', 'type' => 'attraction', 'cost' => 150000],
        ]]]];

        $html = $this->japaneseWizard()
            ->set('aiItinerary', $plan)
            ->set('aiItineraryOptions', [$plan])
            ->set('step', 8)
            ->html();

        $this->assertStringContainsString('Over Budget</div>', $html);
    }

    public function test_step_9_states_the_budget_in_the_travellers_own_terms(): void
    {
        // ₱120,000 shown back in yen is ¥300,000 — what was typed. Treated as
        // pesos and converted, it claimed a ¥750,000 budget.
        $html = $this->japaneseWizard()
            ->set('emergency', 50000)
            ->call('confirmEmergencyFund')
            ->set('step', 9)
            ->html();

        $this->assertStringContainsString('Within ¥300,000 budget', $html);
        $this->assertStringNotContainsString('¥750,000', $html);
    }

    public function test_the_pdf_is_given_the_budget_in_pesos(): void
    {
        // The PDF prints every figure with a peso sign, so the budget has to be
        // pesos too or it sits beside the totals 2.5× too large.
        $component = $this->japaneseWizard()
            ->set('emergency', 50000)
            ->call('confirmEmergencyFund');

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('output')->andReturn('%PDF');
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) {
                $this->assertEquals(120000, $data['budget']);
                $this->assertEquals(20000, $data['emergency']);

                return true;
            })
            ->andReturn($pdf);

        $component->call('downloadPdf');
    }

    public function test_the_budget_range_is_not_the_destination_conversion(): void
    {
        $this->fakeRate(40.0);

        // The card prints the destination figure on its own line underneath.
        // If the range used tripDisplayAmount() it would switch to the
        // destination currency once a conversion was accepted, and the
        // traveller's own currency would be nowhere on screen.
        $this->actingAs($this->traveler('Canada'));

        $component = Livewire::test(TripPlannerWizard::class)
            ->set('convertedBudget', 5000)
            ->set('destinationCurrencyCode', 'JPY');

        $instance = $component->instance();

        $this->assertSame('JPY', $instance->displayCurrency(), 'setup no longer exercises this');
        $this->assertSame('CAD', $instance->budgetCurrency());
        $this->assertStringStartsWith('C$', $instance->budgetDisplayAmount(40000));
    }
}
