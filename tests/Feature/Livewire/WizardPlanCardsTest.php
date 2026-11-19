<?php
namespace Tests\Feature\Livewire;

use App\Livewire\Traveler\TripPlannerWizard;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 8 shows the generated itineraries as side-by-side plan cards, like a
 * pricing flyer: each card has its total cost and the whole day-by-day
 * itinerary, and clicking one selects it.
 */
class WizardPlanCardsTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $theme, string $title, int $cost): array
    {
        return ['days' => [
            ['day' => 1, 'label' => $theme, 'activities' => [
                ['time' => '09:00 AM', 'title' => $title, 'type' => 'attraction', 'cost' => $cost],
                ['time' => '12:30 PM', 'title' => "Lunch near {$title}", 'type' => 'food', 'cost' => 500],
            ]],
            ['day' => 2, 'label' => 'Departure', 'activities' => []],
        ]];
    }

    private function wizard(array $options)
    {
        $user = User::factory()->create();
        UserProfile::create(['user_id' => $user->id]);

        return Livewire::actingAs($user)->test(TripPlannerWizard::class)
            ->set('planningMode', 'manual')
            ->set('manualFrom', 'Cebu City')
            ->set('manualTo', 'Singapore')
            ->set('startDate', '2026-11-24')
            ->set('endDate', '2026-11-26')
            ->set('selectedHotel', ['name' => 'Harbour Inn', 'stars' => 4, 'nights' => 2, 'total' => 10000])
            ->set('aiItineraryOptions', $options)
            ->set('aiItinerary', $options[0] ?? null)
            ->set('step', 8);
    }

    public function test_each_option_is_a_card_with_its_total_and_its_itinerary(): void
    {
        $html = $this->wizard([
            $this->plan('Island Hopping', 'Pulau Ubin Jeep Tour', 2500),
            $this->plan('Beach Day', 'Surfing at Siloso', 3500),
            $this->plan('City Lights', 'Gardens by the Bay', 1200),
        ])->html();

        $this->assertSame(3, substr_count($html, 'class="itin8-plan '));
        // Cheapest first, each card leading with its cost: no "Option N"
        // band or dates on top any more.
        $this->assertSeeInOrderHtml($html, ['₱11,700', 'Gardens by the Bay', '₱13,000', 'Pulau Ubin Jeep Tour', '₱14,000', 'Surfing at Siloso']);
        $this->assertStringNotContainsString('itin8-plan-head', $html);
        // Days are numbered, not titled with the AI's themes.
        $this->assertStringContainsString('<span class="itin8-plan-day-label">Day 1</span>', $html);
        $this->assertStringContainsString('<span class="itin8-plan-day-label">Day 2</span>', $html);
        $this->assertStringNotContainsString('itin8-plan-day-label">Island Hopping', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*Option [123]\s*</', $html);
        // The whole trip: the hotel (₱10,000) + that option's activities.
        $this->assertStringContainsString('₱11,700', $html);
        $this->assertStringContainsString('₱13,000', $html);
        $this->assertStringContainsString('₱14,000', $html);
        // Days and their activities, not just day themes.
        $this->assertStringContainsString('Lunch near Gardens by the Bay', $html);
        $this->assertStringContainsString('Harbour Inn', $html);
        $this->assertStringContainsString('Free day', $html);
        $this->assertStringNotContainsString('class="itin8-days"', $html);
    }

    public function test_the_selected_card_is_marked_and_matches_the_estimated_cost(): void
    {
        $component = $this->wizard([
            $this->plan('Island Hopping', 'Pulau Ubin Jeep Tour', 2500),
            $this->plan('Beach Day', 'Surfing at Siloso', 3500),
        ])->call('selectItineraryOption', 1);

        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'class="itin8-plan is-active"'));
        $this->assertMatchesRegularExpression('/is-active.*?Total Est\. Cost.*?₱14,000/s', $html);
        // Estimated Cost at the top reads the same option.
        $this->assertMatchesRegularExpression('/itin8-cost-val">₱14,000/', $html);
    }

    public function test_a_single_option_is_still_a_card(): void
    {
        $html = $this->wizard([$this->plan('Island Hopping', 'Pulau Ubin Jeep Tour', 2500)])->html();

        $this->assertSame(1, substr_count($html, 'class="itin8-plan '));
        $this->assertStringContainsString('Add Custom Activity', $html);
    }

    public function test_with_no_ai_option_the_selections_board_remains(): void
    {
        $html = $this->wizard([])->html();

        $this->assertStringNotContainsString('class="itin8-plan ', $html);
        $this->assertStringContainsString('class="itin8-days"', $html);
        $this->assertStringContainsString('Add Custom Activity', $html);
    }

    private function assertSeeInOrderHtml(string $html, array $needles): void
    {
        $pos = 0;
        foreach ($needles as $n) {
            $found = strpos($html, $n, $pos);
            $this->assertNotFalse($found, "'{$n}' out of order");
            $pos = $found + strlen($n);
        }
    }
}
