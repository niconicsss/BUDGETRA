<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\UserProfileSaver;
use App\Support\PlaceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every country and territory can be picked at registration, as of 2026.
 *
 * The registered country decides two things beyond the dropdown: the home
 * currency (PlaceCatalog::COUNTRY_CURRENCIES, which falls back to pesos when
 * a country is missing) and the home cities the Profile Builder offers
 * (config/country_cities.php, a pick-from-list). So every country needs both.
 */
class CountryCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function countries(): array
    {
        return array_keys(config('country_cities'));
    }

    public function test_every_country_and_territory_is_listed(): void
    {
        // ISO 3166-1's 249 entries, plus Kosovo, less the seven with no
        // permanent population (Antarctica, Bouvet Island, Heard and McDonald
        // Islands, South Georgia, the French Southern Territories, the US
        // Minor Outlying Islands and the British Indian Ocean Territory).
        $this->assertCount(243, $this->countries());

        foreach (['Philippines', 'Peru', 'Kosovo', 'Taiwan', 'Palestine', 'Türkiye', 'Czechia',
                  'Eswatini', 'North Macedonia', 'Timor-Leste', "Côte d'Ivoire", 'Greenland',
                  'Puerto Rico', 'Hong Kong', 'Macau', 'Tokelau', 'Vatican City'] as $country) {
            $this->assertContains($country, $this->countries());
        }
        foreach (['Antarctica', 'Turkey', 'Czech Republic', 'Swaziland', 'Burma'] as $gone) {
            $this->assertNotContains($gone, $this->countries());
        }
    }

    public function test_every_country_has_a_currency_and_nothing_else_does(): void
    {
        $this->assertSame([], array_values(array_diff($this->countries(), array_keys(PlaceCatalog::COUNTRY_CURRENCIES))));
        $this->assertSame([], array_values(array_diff(array_keys(PlaceCatalog::COUNTRY_CURRENCIES), $this->countries())));

        foreach (PlaceCatalog::COUNTRY_CURRENCIES as $country => $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $code, $country);
        }
    }

    public function test_currencies_are_current(): void
    {
        $this->assertSame('EUR', PlaceCatalog::COUNTRY_CURRENCIES['Bulgaria']);   // joined the euro, Jan 2026
        $this->assertSame('EUR', PlaceCatalog::COUNTRY_CURRENCIES['Croatia']);
        $this->assertSame('XCG', PlaceCatalog::COUNTRY_CURRENCIES['Curaçao']);    // replaced the ANG, 2025
        $this->assertSame('SLE', PlaceCatalog::COUNTRY_CURRENCIES['Sierra Leone']);
        $this->assertSame('VES', PlaceCatalog::COUNTRY_CURRENCIES['Venezuela']);
        $this->assertSame('USD', PlaceCatalog::COUNTRY_CURRENCIES['Ecuador']);
    }

    public function test_every_country_has_home_cities_with_airport_codes(): void
    {
        $seen = [];

        foreach (config('country_cities') as $country => $cities) {
            $this->assertNotEmpty($cities, "{$country} has no cities to pick");

            foreach ($cities as $code => $city) {
                $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $code, "{$city}, {$country}");

                // A city name in two countries would make the home city's
                // currency depend on which one the lookup reached first.
                $key = mb_strtolower($city);
                $this->assertArrayNotHasKey($key, $seen, "{$city} is in both {$country} and " . ($seen[$key] ?? ''));
                $seen[$key] = $country;
            }
        }
    }

    public function test_a_new_country_gets_its_own_currency_not_pesos(): void
    {
        $this->actingAs(User::factory()->create(['country' => 'Peru']));

        $this->assertSame('PEN', home_currency());
        $this->assertSame('PEN', UserProfileSaver::currencyForHomeCity('Lima'));

        // No symbol on file for PEN: labelled with the code, never '₱'.
        $this->assertSame('PEN ', UserProfileSaver::budgetSymbolForHomeCity('Lima'));
    }

    public function test_the_trip_planner_offers_a_new_country_its_own_cities_with_codes(): void
    {
        $local = array_values(array_filter(
            PlaceCatalog::cityOptionsFor('Peru'),
            fn ($o) => $o['group'] === 'Local'
        ));

        $this->assertSame(['Lima', 'Cusco'], array_column($local, 'name'));
        $this->assertSame(['LIM', 'CUZ'], array_column($local, 'code'));

        // San José, Costa Rica — not "San Jose", which IATA_CODES maps to the
        // Philippine airport SJI.
        $costaRica = PlaceCatalog::cityOptionsFor('Costa Rica');
        $this->assertSame('SJO', $costaRica[0]['code']);
    }

    public function test_cambodia_flies_from_its_new_airports(): void
    {
        // Techo International (KTI) replaced Phnom Penh International (PNH) in
        // September 2025; Siem Reap–Angkor (SAI) replaced REP in October 2023.
        $this->assertSame('KTI', PlaceCatalog::IATA_CODES['phnom penh']);
        $this->assertSame('KTI', PlaceCatalog::IATA_CODES['cambodia']);
        $this->assertSame('SAI', PlaceCatalog::IATA_CODES['siem reap']);
        $this->assertSame(['KTI' => 'Phnom Penh', 'SAI' => 'Siem Reap'], config('country_cities.Cambodia'));

        // The trip planner keeps its own copy of the map; it must agree.
        $wizard = file_get_contents(app_path('Livewire/Traveler/TripPlannerWizard.php'));
        $this->assertStringContainsString("'phnom penh' => 'KTI', 'cambodia' => 'KTI',", $wizard);
        $this->assertStringContainsString("'siem reap' => 'SAI',", $wizard);
        $this->assertDoesNotMatchRegularExpression("/=> '(PNH|REP)'/", $wizard);
    }

    public function test_the_destination_catalogue_uses_the_same_country_names(): void
    {
        $this->assertSame('Istanbul, Türkiye', PlaceCatalog::withCountry('Istanbul'));
        $this->assertSame('Prague, Czechia', PlaceCatalog::withCountry('Prague'));

        foreach (array_unique(PlaceCatalog::DESTINATION_COUNTRIES) as $country) {
            $this->assertContains($country, $this->countries(), "{$country} is not a dropdown name");
        }
    }
}
