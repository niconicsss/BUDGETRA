<?php
namespace Tests\Feature\UI;

use App\Livewire\Traveler\TripPlannerWizard;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The floating lower-right pill (<x-toast>): "Trip added successfully!" after
 * importing a trip, "Profile changed successfully!" after Save Changes.
 */
class SuccessToastTest extends TestCase
{
    use RefreshDatabase;

    private function traveler(): User
    {
        $user = User::factory()->create();
        UserProfile::create([
            'user_id'      => $user->id,
            'home_city'    => 'Cebu City',
            'daily_budget' => 2000,
            'travel_style' => 'Solo',
        ]);

        return $user;
    }

    /** Someone else's trip with something on it, under a known share code. */
    private function sharedTrip(): Trip
    {
        $trip = Trip::factory()->create(['user_id' => $this->traveler()->id]);
        $trip->forceFill([
            'share_code'       => 'AB1C2D3E',
            'flight_selection' => ['airline' => 'PAL'],
        ])->save();

        return $trip;
    }

    public function test_importing_by_link_flashes_the_pill(): void
    {
        $this->sharedTrip();

        $this->actingAs($this->traveler())
            ->get('/trips/import/AB1C2D3E')
            ->assertRedirect(route('saved-trips'))
            ->assertSessionHas('toast', 'Trip added successfully!')
            ->assertSessionMissing('success');
    }

    public function test_importing_by_code_flashes_the_pill(): void
    {
        $this->sharedTrip();

        Livewire::actingAs($this->traveler())
            ->test(TripPlannerWizard::class)
            ->set('importCodeInput', 'AB1C2D3E')
            ->call('importCode')
            ->assertRedirect(route('saved-trips'));

        $this->assertSame('Trip added successfully!', session('toast'));
    }

    public function test_a_second_import_is_not_called_an_addition(): void
    {
        $this->sharedTrip();
        $me = $this->traveler();

        $this->actingAs($me)->get('/trips/import/AB1C2D3E');
        $this->actingAs($me)->get('/trips/import/AB1C2D3E')
            ->assertSessionHas('success', "You're already a member of this trip.")
            ->assertSessionMissing('toast');
    }

    public function test_the_layout_plays_a_flashed_pill(): void
    {
        $html = $this->actingAs($this->traveler())
            ->withSession(['toast' => 'Trip added successfully!'])
            ->get(route('saved-trips'))->getContent();

        $this->assertMatchesRegularExpression(
            '/id="appToast" class="app-toast is-showing" role="status" aria-live="polite">.*?Trip added successfully!/s',
            $html
        );
    }

    public function test_no_flash_means_no_visible_pill(): void
    {
        $html = $this->actingAs($this->traveler())->get(route('saved-trips'))->getContent();

        $this->assertStringContainsString('id="appToast" class="app-toast" role="status"', $html);
    }

    public function test_profile_save_changes_shows_profile_changed(): void
    {
        $html = $this->actingAs($this->traveler())->get('/profile')->getContent();

        $this->assertStringContainsString('id="profileToast" class="app-toast"', $html);
        $this->assertStringContainsString(
            "window.budgetraToast('Profile changed successfully!', 'profileToast');",
            $html
        );
        $this->assertStringNotContainsString('Avatar changed successfully', $html);
    }

    public function test_profile_save_without_javascript_flashes_the_same_pill(): void
    {
        $me = $this->traveler();

        $this->actingAs($me)->from('/profile')
            ->put(route('profile.update'), ['first_name' => 'Ana', 'last_name' => 'Cruz'])
            ->assertSessionHas('toast', 'Profile changed successfully!');
    }

    public function test_the_pill_is_themed_and_lower_right(): void
    {
        $css = file_get_contents(public_path('css/style.css'));

        $this->assertMatchesRegularExpression('/\.app-toast \{\s*position: fixed; right: 24px; bottom: 24px;/', $css);
        $this->assertStringContainsString('background: var(--bg-white); color: var(--dark);', $css);
        $this->assertStringContainsString('color: var(--bg-white); background: var(--success);', $css);
    }
}
