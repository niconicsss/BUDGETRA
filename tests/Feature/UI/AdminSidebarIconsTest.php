<?php
namespace Tests\Feature\UI;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin sidebar draws the same SVG icons as the traveler sidebar.
 *
 * It used to be <img> PNGs whitened with a CSS filter, and a Font Awesome
 * angle for the collapse button. Now every icon is an .app-icon mask over
 * systemicons/{name}.svg, filled with the link's own colour — so Logout's
 * icon is red like its label — and the chevron is chosen by CSS.
 */
class AdminSidebarIconsTest extends TestCase
{
    use RefreshDatabase;

    /** Admin sidebar icons, in order. */
    private const ICONS = [
        'dashboard', 'user-accounts', 'destinations', 'attractions',
        'travel-costs', 'user-reviews', 'settings', 'logout',
        'left-chevron', 'right-chevron',
    ];

    private function page(): string
    {
        return $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/users')->assertOk()->getContent();
    }

    public static function icons(): array
    {
        return array_map(fn ($n) => [$n], array_combine(self::ICONS, self::ICONS));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('icons')]
    public function test_each_icon_is_an_svg_in_both_folders(string $name): void
    {
        // public/ is what the browser can reach; the root copy must match it.
        $this->assertFileExists(public_path("systemicons/{$name}.svg"));
        $this->assertFileEquals(public_path("systemicons/{$name}.svg"), base_path("systemicons/{$name}.svg"));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('icons')]
    public function test_the_sidebar_serves_each_icon_as_svg(string $name): void
    {
        $this->assertStringContainsString("systemicons/{$name}.svg", $this->page());
    }

    public function test_no_png_icons_or_font_awesome_chevron_remain(): void
    {
        $sidebar = file_get_contents(resource_path('views/components/admin-sidebar.blade.php'));

        // The brand logo is the one PNG the sidebar still uses.
        $this->assertSame(1, preg_match_all('/\.png/', $sidebar));
        $this->assertStringContainsString('budgetraicon.png', $sidebar);

        $this->assertStringNotContainsString('fa-angle', $sidebar);
        $this->assertStringNotContainsString('admin-sidebar-icon', $sidebar);
        $this->assertDoesNotMatchRegularExpression('/\bicon\.className\s*=/', $sidebar);
    }

    public function test_icons_are_masks_coloured_by_their_link(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression(
            '/<i class="app-icon app-icon-svg" style="--app-icon:url\([^)]*systemicons\/logout\.svg/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<i class="app-icon" id="adminSidebarToggleIcon"\s+style="--chevron-left:url\([^)]*left-chevron\.svg[^)]*\);--chevron-right:url\([^)]*right-chevron\.svg/',
            $html
        );
    }

    public function test_the_chevron_turns_and_icons_keep_their_size(): void
    {
        $css = file_get_contents(public_path('css/style.css'));

        $this->assertStringContainsString('#adminSidebarToggleIcon { --app-icon: var(--chevron-left); }', $css);
        $this->assertStringContainsString('.admin-sidebar-collapsed #adminSidebarToggleIcon { --app-icon: var(--chevron-right); }', $css);
        $this->assertStringContainsString('.admin-sidebar-collapsed .admin-sidebar-link i.app-icon { font-size: 20px; width: 1em;', $css);
    }

    public function test_the_header_and_brand_sit_where_the_traveler_ones_do(): void
    {
        // Measured in a browser against the traveler sidebar: chevron 10px in
        // from the right and 22px down, 38px mark 16px in, "Budgetra" 28px
        // after it, brand divider 24px in. The -16px margins cancel the admin
        // sidebar's side padding so those offsets are from its own edge.
        $css = file_get_contents(public_path('css/style.css'));

        $this->assertStringContainsString('.admin-sidebar-header { display: flex; justify-content: flex-end; padding: 10px 10px 0; margin: 0 -16px;', $css);
        $this->assertStringContainsString('.admin-sidebar-brand { display: flex; align-items: center; gap: 28px; padding: 4px 16px 14px; margin: 0 -16px 6px;', $css);
        $this->assertStringContainsString('.admin-sidebar-divider-brand { margin: 6px 8px; }', $css);
        $this->assertMatchesRegularExpression('/\.admin-sidebar-logo \{\s*width: 38px; height: 38px;/', $css);

        // Collapsed, the negative margins must go or the rail would overflow.
        $this->assertStringContainsString('.admin-sidebar-collapsed .admin-sidebar-header { justify-content: center; padding: 0 0 4px; margin: 0; }', $css);
        $this->assertStringContainsString('.admin-sidebar-collapsed .admin-sidebar-brand { justify-content: center; padding: 8px 0 10px; margin: 0 0 6px; gap: 0; }', $css);

        // The component no longer shrinks the mark to 30px.
        $sidebar = file_get_contents(resource_path('views/components/admin-sidebar.blade.php'));
        $this->assertStringNotContainsString('width: 30px', $sidebar);
    }
}
