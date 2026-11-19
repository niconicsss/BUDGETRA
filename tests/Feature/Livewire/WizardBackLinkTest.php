<?php
namespace Tests\Feature\Livewire;

use Tests\TestCase;

/**
 * Every manual-planning step puts its back link in the same top-left spot as
 * "Back to Flights".
 */
class WizardBackLinkTest extends TestCase
{
    private function template(): string
    {
        return file_get_contents(resource_path('views/livewire/traveler/trip-planner-wizard.blade.php'));
    }

    public function test_every_back_link_sits_at_the_top_left_like_back_to_flights(): void
    {
        $t = $this->template();

        // Emergency Fund and Generate Itinerary used 20px 24px of padding,
        // the itinerary options and summary 20px of top padding.
        $this->assertStringNotContainsString('<div style="padding:20px 24px 0;">', $t);
        $this->assertStringNotContainsString('.itin8-wrap{padding:20px 0;', $t);
        // Steps 6, 7 and 9 now pad below the link instead.
        $this->assertSame(3, substr_count($t, '<div style="padding:0 0 20px;">'));
        // The itinerary options' back link is no longer centred against the
        // Estimated Cost card beside it.
        $this->assertStringContainsString('.itin8-topbar{background:transparent;border:none;padding:0;margin-bottom:20px;display:flex;align-items:flex-start;', $t);
        // The summary's back link is no longer centred against Confirm Trip.
        $this->assertMatchesRegularExpression(
            '/align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">\s*<button wire:click="\$set\(\'step\', 8\)"/',
            $t
        );
    }
}
