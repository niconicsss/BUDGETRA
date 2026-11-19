<?php
namespace Tests\Feature\Livewire;

use App\Livewire\Traveler\TripPlannerWizard;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Trip Details won't take a budget under ₱10,000, worked out in the
 * traveller's own currency: ₱10,000 for the Philippines, about ¥26,000 for
 * Japan, and so on.
 */
class MinimumBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Rates: ¥1 = ₱0.387. Everything else (flight search on Next, other
        // currencies) answers empty, so no test reaches a real API.
        Http::fake([
            'api.twelvedata.com/*JPY*' => Http::response(['symbol' => 'JPY/PHP', 'rate' => 0.387], 200),
            '*' => Http::response([], 500),
        ]);
    }

    private function wizard(string $country, string $budget)
    {
        $user = User::factory()->create(['country' => $country]);
        UserProfile::create(['user_id' => $user->id]);

        return Livewire::actingAs($user)->test(TripPlannerWizard::class)
            ->set('planningMode', 'manual')->set('step', 1)
            ->set('manualFrom', $country === 'Japan' ? 'Tokyo' : 'Manila')
            ->set('manualTo', 'Cebu')
            ->set('startDate', '2027-02-01')->set('endDate', '2027-02-05')
            ->set('manualBudgetMin', $budget);
    }

    public function test_the_minimum_only_appears_as_the_error(): void
    {
        // No standing "Minimum budget" line; the amount is only in the error,
        // which stays hidden until Next is pressed with less.
        $this->wizard('Philippines', '')
            ->assertDontSee('Minimum budget:')
            ->assertSeeHtml('<div class="pyt-budget-note is-bad" x-show="budgetTooLow" x-cloak>')
            ->assertSeeHtml('Your budget must be at least <strong>₱10,000</strong>.');
    }

    public function test_a_budget_under_the_minimum_is_refused(): void
    {
        $this->wizard('Philippines', '5,000')
            ->call('proceedFromTripDetails')
            ->assertDispatched('budget-too-low')
            ->assertDispatched('trip-details-missing', fields: ['budget'])
            ->assertSet('step', 1)
            // Left as typed, so it can be fixed.
            ->assertSet('manualBudgetMin', '5,000');
    }

    public function test_the_bottom_of_a_range_counts_too(): void
    {
        $this->wizard('Philippines', '5,000 - 20,000')
            ->call('proceedFromTripDetails')
            ->assertDispatched('budget-too-low')
            ->assertSet('step', 1);
    }

    public function test_the_minimum_itself_is_accepted(): void
    {
        $this->wizard('Philippines', '10,000')
            ->call('proceedFromTripDetails')
            ->assertNotDispatched('budget-too-low')
            ->assertSet('step', 2)
            ->assertSet('manualBudgetMin', '10000');
    }

    public function test_in_another_country_the_minimum_is_in_its_currency(): void
    {
        // ₱10,000 ÷ 0.387 = ¥25,840, rounded up to ¥26,000.
        $this->wizard('Japan', '')
            ->assertSet('step', 1)
            ->assertSee('Preferred Budget Range in JPY')
            ->assertSeeHtml('<strong>¥26,000</strong>');

        $this->wizard('Japan', '20,000')->call('proceedFromTripDetails')
            ->assertDispatched('budget-too-low')->assertSet('step', 1);

        $this->wizard('Japan', '30,000')->call('proceedFromTripDetails')
            ->assertNotDispatched('budget-too-low')->assertSet('step', 2);
    }

    public function test_the_page_checks_before_asking_the_server(): void
    {
        $html = $this->wizard('Philippines', '')->html();

        // @js() hands the seed over as JSON.parse('…') with escaped quotes.
        $this->assertStringContainsString('\u0022minBudget\u0022:10000', $html);
        $this->assertStringContainsString('@budget-too-low.window="budgetTooLow = true"', $html);
    }

    public function test_the_rounding_stays_clean_at_every_size(): void
    {
        $round = function (float $pesosPerUnit) {
            $local = TripPlannerWizard::MIN_TRIP_BUDGET_PESOS / $pesosPerUnit;
            $step  = $local < 100 ? 1 : ($local < 1000 ? 10 : ($local < 10000 ? 100 : 1000));
            return (int) (ceil($local / $step) * $step);
        };

        $this->assertSame(180, $round(58.0));        // USD: $172.41 → $180
        $this->assertSame(26000, $round(0.387));     // JPY
        $this->assertSame(4348000, $round(0.0023));  // VND
    }
}
