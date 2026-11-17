<?php
namespace Tests\Feature\UI;

use Tests\TestCase;

/**
 * The country dropdown on Create Account.
 *
 * It was a hand-written copy of the 29 keys in config/country_cities.php, in
 * that config's region-grouped order — so it read as unsorted, and the two
 * lists could drift until a traveler picked a home country the Profile Builder
 * had no cities for. It now reads the config and sorts it, and the config
 * covers every country and territory.
 */
class RegisterCountryListTest extends TestCase
{
    /** @return string[] the option labels, in the order rendered */
    private function renderedCountries(): array
    {
        $html = $this->get('/register')->assertStatus(200)->getContent();

        // A search box over a list now, not a <select> (country-combobox.js).
        preg_match('/<ul class="country-combo-list" id="countryList".*?<\/ul>/s', $html, $list);
        $this->assertNotEmpty($list, 'country list not found');

        preg_match_all('/<li class="country-combo-option"[^>]*data-value="([^"]+)"[^>]*>([^<]*)<\/li>/s', $list[0], $m);

        // Blade escapes the apostrophe in "Côte d'Ivoire".
        return array_map(fn ($l) => html_entity_decode(trim($l), ENT_QUOTES), $m[2]);
    }

    private function collated(array $names): array
    {
        (new \Collator('en'))->sort($names);

        return $names;
    }

    public function test_the_countries_are_in_alphabetical_order(): void
    {
        $rendered = $this->renderedCountries();

        $this->assertSame($this->collated($rendered), $rendered);
    }

    public function test_accented_names_sort_by_their_letter_not_to_the_bottom(): void
    {
        // Byte order would put "Åland Islands" after Zimbabwe.
        $rendered = $this->renderedCountries();

        $this->assertSame(['Afghanistan', 'Åland Islands', 'Albania'], array_slice($rendered, 0, 3));
        $this->assertSame('Zimbabwe', end($rendered));
    }

    public function test_every_country_comes_from_the_one_list(): void
    {
        // Not a second hand-maintained copy: a country offered here must be one
        // the Profile Builder can then offer home cities for.
        $this->assertSame($this->collated(array_keys(config('country_cities'))), $this->renderedCountries());
    }

    public function test_adding_a_country_to_the_config_places_it_by_name(): void
    {
        // The config is grouped by region, so a new entry lands at the end of
        // its group. It has to find its own place in the dropdown.
        config(['country_cities.Atlantis' => ['ATL' => 'Poseidonis']]);

        $rendered = $this->renderedCountries();
        $atlantis = array_search('Atlantis', $rendered, true);

        $this->assertNotFalse($atlantis, 'Atlantis never reached the dropdown');
        $this->assertSame('Aruba', $rendered[$atlantis - 1]);
        $this->assertSame('Australia', $rendered[$atlantis + 1]);
    }

    public function test_select_your_country_is_the_search_boxs_placeholder(): void
    {
        $html = $this->get('/register')->getContent();

        // No longer a dead first option in the list: it is what the empty
        // search box says, and nothing is picked until a country is.
        $this->assertMatchesRegularExpression(
            '/<input id="country" type="text"[^>]*placeholder="Select your country"[^>]*role="combobox"/s',
            $html
        );
        $this->assertStringContainsString('<input type="hidden" name="country" id="countryValue" value="">', $html);
        $this->assertStringNotContainsString('<select name="country"', $html);
    }

    public function test_the_search_box_filters_and_only_a_pick_is_submitted(): void
    {
        // Checked in a browser: "ma" → Macau, Madagascar, Malawi… first;
        // "aland" → Åland Islands; "cote" → Côte d'Ivoire; Enter picks the
        // highlighted one; leaving half-typed text puts back the last pick.
        $html = $this->get('/register')->getContent();
        $this->assertStringContainsString('js/country-combobox.js', $html);

        $js = file_get_contents(public_path('js/country-combobox.js'));
        $this->assertStringContainsString(".normalize('NFD').replace(/[\\u0300-\\u036f]/g, '')", $js);
        $this->assertStringContainsString('hidden.value = option.dataset.value;', $js);
        $this->assertStringContainsString('input.value = hidden.value;', $js);
    }

    public function test_a_previous_pick_survives_a_refused_submit(): void
    {
        $html = $this->from('/register')->followingRedirects()
            ->post('/register', ['first_name' => '', 'country' => 'Peru'])
            ->getContent();

        $this->assertStringContainsString('<input type="hidden" name="country" id="countryValue" value="Peru">', $html);
        $this->assertMatchesRegularExpression('/<input id="country" type="text"[^>]*value="Peru"/s', $html);
        $this->assertMatchesRegularExpression('/data-value="Peru" aria-selected="true"/', $html);
    }

    public function test_the_labels_carry_no_leftover_flag_spacing(): void
    {
        // Each option used to render "{{ $flag }} {{ $name }}" against a map
        // whose flag values had all been emptied, leaving every label with a
        // leading space.
        $html = $this->get('/register')->getContent();

        $this->assertStringNotContainsString('> Philippines<', $html);
        $this->assertStringContainsString('>Philippines</li>', $html);
    }
}
