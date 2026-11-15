<?php
namespace Tests\Feature\UI;

use Tests\TestCase;

/**
 * The exchange-rate badge on a saving-goal card ("1 TWD ≈ ¥4.85 JPY") takes
 * its colours from the theme's own --primary.
 *
 * It was --bg with a dashed grey --border: a cool grey outline on a cream
 * panel on Original, and a panel darker than its card on Nightflight.
 */
class SavingsRateBadgeThemingTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(public_path('css/style.css'));
    }

    private function rule(string $selector): string
    {
        preg_match('/^' . preg_quote($selector, '/') . ' \{([^}]*)\}/m', $this->css(), $m);
        $this->assertNotEmpty($m, "{$selector} is missing from style.css");

        return $m[1];
    }

    public function test_the_card_uses_the_themed_classes_not_inline_colours(): void
    {
        $view = file_get_contents(resource_path('views/livewire/traveler/savings-goal-manager.blade.php'));

        $this->assertStringContainsString('class="sg-rate"', $view);
        $this->assertStringContainsString('class="sg-rate-code"', $view);
        $this->assertStringNotContainsString('1px dashed var(--border)', $view);
    }

    public function test_the_panel_is_tinted_from_the_primary_over_the_card(): void
    {
        $panel = $this->rule('.sg-rate');

        $this->assertStringContainsString('color-mix(in srgb, var(--primary) 7%, var(--bg-white))', $panel);
        $this->assertStringContainsString('border: 1px solid color-mix(in srgb, var(--primary)', $panel);
        $this->assertStringNotContainsString('dashed', $panel);
    }

    public function test_the_code_pill_uses_the_contrast_safe_ink(): void
    {
        // Plain --primary on the Nightflight pill is 4.3:1, under AA for
        // 10px text. The ink pulled toward --dark clears it on both themes.
        $this->assertStringContainsString(
            '--sg-rate-ink: color-mix(in srgb, var(--primary) 80%, var(--dark));',
            $this->rule('.sg-rate')
        );
        $this->assertStringContainsString('color: var(--sg-rate-ink);', $this->rule('.sg-rate-code'));
    }

    public function test_every_token_it_uses_exists_in_both_themes(): void
    {
        $css = $this->css();

        foreach (['original', 'nightflight'] as $theme) {
            preg_match('/\[data-theme="' . $theme . '"\] \{(.*?)\n\}/s', $css, $m);
            $this->assertNotEmpty($m, "the {$theme} block moved");

            foreach (['--primary', '--bg-white', '--dark'] as $token) {
                $this->assertStringContainsString($token . ':', $m[1], "{$theme} is missing {$token}");
            }
        }
    }
}
