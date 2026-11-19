<?php
namespace Tests\Feature\UI;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

/**
 * Buttons answer the click at once: a Livewire button dims while its request
 * is out (Livewire 4's data-loading), a plain form's submit button dims once
 * the form really submits (js/press-feedback.js). And the database settings
 * that cut each click's server time stay in place.
 */
class ButtonFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return file_get_contents(public_path('css/style.css'));
    }

    private function script(): string
    {
        return file_get_contents(public_path('js/press-feedback.js'));
    }

    public function test_busy_buttons_dim_and_stop_taking_clicks(): void
    {
        $this->assertMatchesRegularExpression(
            '/button\[data-loading\], a\[data-loading\], \.btn\[data-loading\], \[data-submitting\] \{\s*cursor: progress; opacity: \.7; pointer-events: none;/',
            $this->css()
        );
    }

    public function test_a_modal_backdrop_is_not_dimmed_by_a_bare_data_loading_rule(): void
    {
        // wire:click.self backdrops get data-loading too; the rule must be
        // limited to buttons and links.
        $this->assertDoesNotMatchRegularExpression('/(^|[\s,}])\[data-loading\]\s*[,{]/m', $this->css());
    }

    public function test_every_layout_loads_the_script(): void
    {
        foreach (['app', 'admin', 'guest'] as $layout) {
            $this->assertStringContainsString(
                "asset('js/press-feedback.js')",
                file_get_contents(resource_path("views/layouts/{$layout}.blade.php")),
                $layout
            );
        }
    }

    public function test_the_traveler_pages_carry_it(): void
    {
        $user = User::factory()->create();
        UserProfile::create(['user_id' => $user->id]);

        $this->actingAs($user)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('js/press-feedback.js', false)
            ->assertSee('data-navigate-once', false);
    }

    public function test_it_leaves_cancelled_and_non_navigating_submits_alone(): void
    {
        $js = $this->script();

        // Checked a tick later, after listeners registered after this one.
        $this->assertMatchesRegularExpression('/setTimeout\(function \(\) \{\s*if \(e\.defaultPrevented\) return;/', $js);
        $this->assertStringContainsString("hasAttribute('data-no-busy')", $js);
        $this->assertStringContainsString("!== '_self'", $js);
        // Back/forward cache restores, and a response that never replaces the page.
        $this->assertStringContainsString("addEventListener('pageshow'", $js);
        $this->assertStringContainsString('GIVE_BACK_AFTER_MS', $js);
        // wire:navigate re-runs body scripts.
        $this->assertStringContainsString('if (window.budgetraPressFeedback) return;', $js);
    }

    public function test_the_backup_download_form_opts_out(): void
    {
        // Its response is a file, so the page never changes.
        $this->assertMatchesRegularExpression(
            "/<form method=\"POST\" action=\"\{\{ route\('admin\.backup\.download'\) \}\}\" data-no-busy>/",
            file_get_contents(resource_path('views/admin/backup/index.blade.php'))
        );
    }

    public function test_postgres_queries_take_one_round_trip_and_no_search_path_is_set(): void
    {
        $pgsql = config('database.connections.pgsql');

        $this->assertArrayNotHasKey('search_path', $pgsql);
        if (defined('PDO::PGSQL_ATTR_DISABLE_PREPARES')) {
            // Integer PDO keys, so the options survived being merged.
            $this->assertTrue($pgsql['options'][PDO::PGSQL_ATTR_DISABLE_PREPARES] ?? false);
        }
    }

    public function test_persistent_connections_are_opt_in(): void
    {
        $this->assertStringContainsString(
            "env('DB_PERSISTENT', false) ? [PDO::ATTR_PERSISTENT => true] : []",
            file_get_contents(config_path('database.php'))
        );
    }
}
