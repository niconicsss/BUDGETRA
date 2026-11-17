<?php
namespace Tests\Feature\Services;

use App\Models\Attraction;
use App\Models\Destination;
use App\Services\PlaceImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Destination and attraction photos come from Wikipedia first — free, no key
 * and no share of the SerpAPI quota flights and hotels run on.
 *
 * None of the 265 destinations or 151 attractions had a photo: the only
 * source was SerpAPI, capped at 20 a day so live searches keep their share.
 */
class PlaceImageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Sleep::fake();   // the retry back-off
    }

    /** One Wikipedia search result. */
    private function page(int $index, string $title, ?string $thumb): array
    {
        return array_filter([
            'index'     => $index,
            'title'     => $title,
            'thumbnail' => $thumb ? ['source' => $thumb] : null,
        ]);
    }

    private function wikipedia(array $pages): array
    {
        return ['https://en.wikipedia.org/w/api.php*' => Http::response(['query' => ['pages' => $pages]])];
    }

    private function jpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 30, 120, 200));
        ob_start();
        imagejpeg($img, null, 100);
        imagedestroy($img);

        return ob_get_clean();
    }

    public function test_the_attractions_own_article_beats_its_citys(): void
    {
        // "Big Buddha Phuket" used to land on the Phuket article — a beach.
        Http::fake($this->wikipedia([
            $this->page(1, 'Phuket province', 'https://upload.example/phuket-beach.jpg'),
            $this->page(2, 'Big Buddha (Phuket)', 'https://upload.example/big-buddha.jpg'),
        ]));

        $this->assertSame(
            'https://upload.example/big-buddha.jpg',
            (new PlaceImageService())->wikipediaImage('Big Buddha Phuket', 'Phuket')
        );
    }

    public function test_the_visit_words_are_ignored_when_matching(): void
    {
        Http::fake($this->wikipedia([
            $this->page(1, 'Niagara Falls', 'https://upload.example/falls.jpg'),
        ]));

        $this->assertSame('https://upload.example/falls.jpg',
            (new PlaceImageService())->wikipediaImage('Niagara Falls Day Trip', 'Toronto'));
    }

    public function test_maps_flags_and_seals_are_skipped(): void
    {
        Http::fake($this->wikipedia([
            $this->page(1, 'Coron, Palawan', 'https://upload.example/Ph_locator_palawan_coron.svg.png'),
            $this->page(2, 'Coron Island', 'https://upload.example/Flag_of_Coron.png'),
            $this->page(3, 'Coron Bay', 'https://upload.example/coron-bay.jpg'),
        ]));

        $this->assertSame('https://upload.example/coron-bay.jpg',
            (new PlaceImageService())->wikipediaImage('Coron', 'Philippines'));
    }

    public function test_an_unrelated_article_is_not_used(): void
    {
        // Search always returns *something*; a title sharing no word with the
        // place is someone else's photo.
        Http::fake($this->wikipedia([
            $this->page(1, 'El Nido, Palawan', 'https://upload.example/el-nido.jpg'),
        ]));

        $this->assertNull((new PlaceImageService())->wikipediaImage('Big Lagoon', 'El Nido'));
    }

    public function test_wikipedia_is_asked_with_a_user_agent(): void
    {
        Http::fake($this->wikipedia([]));

        (new PlaceImageService())->wikipediaImage('Siargao', 'Philippines');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://en.wikipedia.org/w/api.php')
            && $r->hasHeader('User-Agent', 'Budgetra/1.0 (travel budgeting app)')
            && $r['gsrsearch'] === 'Siargao Philippines');
    }

    public function test_a_found_photo_is_stored_shrunk_to_1024px(): void
    {
        $destination = Destination::create(['name' => 'Siargao', 'country' => 'Philippines']);

        Http::fake($this->wikipedia([
            $this->page(1, 'Siargao', 'https://upload.wikimedia.org/siargao.jpg'),
        ]) + ['https://upload.wikimedia.org/*' => Http::response($this->jpeg(2000, 1000), 200, ['Content-Type' => 'image/jpeg'])]);

        $this->assertTrue((new PlaceImageService())->fetchForDestination($destination, false));

        $path = $destination->fresh()->image;
        $this->assertSame("destination-images/siargao-{$destination->id}.jpg", $path);
        Storage::disk('public')->assertExists($path);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([1024, 512], [$width, $height]);
    }

    /** One Wikimedia Commons file. */
    private function file(int $index, string $title, string $mime = 'image/jpeg', int $width = 3000): array
    {
        return ['index' => $index, 'title' => "File:{$title}", 'imageinfo' => [[
            'mime' => $mime, 'width' => $width, 'thumburl' => 'https://upload.wikimedia.org/thumb/' . rawurlencode($title),
        ]]];
    }

    private function commons(array $files): array
    {
        return ['https://commons.wikimedia.org/w/api.php*' => Http::response(['query' => ['pages' => $files]])];
    }

    public function test_commons_fills_in_when_wikipedia_has_no_photo(): void
    {
        // Bali's article photo is a locator map; "Big Lagoon" has no article.
        Http::fake($this->wikipedia([$this->page(1, 'Bali', 'https://upload.example/Bali_in_Indonesia.svg.png')])
            + $this->commons([$this->file(1, 'Sunset, Kuta, Bali, Indonesia.jpg')]));

        $this->assertNull((new PlaceImageService())->wikipediaImage('Bali', 'Indonesia'));
        $this->assertSame(
            'https://upload.wikimedia.org/thumb/' . rawurlencode('Sunset, Kuta, Bali, Indonesia.jpg'),
            (new PlaceImageService())->commonsImage('Bali', 'Indonesia')
        );
    }

    public function test_a_commons_file_has_to_name_the_place_itself(): void
    {
        // An El Nido photo is not a Big Lagoon photo: both of the attraction's
        // own words have to be in the file name.
        Http::fake($this->commons([
            $this->file(1, 'El Nido town beach.jpg'),
            $this->file(2, 'Big rocks near El Nido.jpg'),
            $this->file(3, 'Big Lagoon at El Nido, Palawan, Philippines.jpg'),
        ]));

        $this->assertStringContainsString(rawurlencode('Big Lagoon at El Nido'),
            (new PlaceImageService())->commonsImage('Big Lagoon', 'El Nido'));
    }

    public function test_commons_takes_only_real_photos(): void
    {
        Http::fake($this->commons([
            $this->file(1, 'Lake Sebu map.jpg'),
            $this->file(2, 'Lake Sebu logo.png', 'image/png'),
            $this->file(3, 'Lake Sebu tiny.jpg', 'image/jpeg', 320),
            $this->file(4, 'The picturesque view of Lake Sebu.jpg'),
        ]));

        $this->assertStringContainsString(rawurlencode('The picturesque view of Lake Sebu'),
            (new PlaceImageService())->commonsImage('Lake Sebu', 'General Santos'));
    }

    public function test_commons_retries_with_the_name_alone(): void
    {
        // The city's own files can crowd a small place out of the first search.
        Http::fake(function (Request $r) {
            $hits = $r['gsrsearch'] === 'Lake Sebu'
                ? [$this->file(1, 'The picturesque view of Lake Sebu.jpg')]
                : [$this->file(1, 'General Santos fish port.jpg')];

            return Http::response(['query' => ['pages' => $hits]]);
        });

        $this->assertNotNull((new PlaceImageService())->commonsImage('Lake Sebu', 'General Santos'));
    }

    public function test_a_place_name_that_contains_logo_is_not_a_logo(): void
    {
        // "Crisologo" — every Calle Crisologo photo used to be thrown out.
        Http::fake($this->commons([$this->file(1, 'Calle Crisologo, Vigan City.jpg'), $this->file(2, 'Vigan city logo.jpg')]));

        $this->assertStringContainsString(rawurlencode('Calle Crisologo, Vigan City'),
            (new PlaceImageService())->commonsImage('Calle Crisologo', 'Vigan'));
    }

    public function test_the_search_leaves_out_the_visit_words(): void
    {
        Http::fake($this->wikipedia([]) + $this->commons([]));

        (new PlaceImageService())->wikipediaImage('Ha Long Bay Day Trip', 'Hanoi');
        (new PlaceImageService())->commonsImage('Iwahig Firefly Watching', 'Puerto Princesa');

        Http::assertSent(fn (Request $r) => $r['gsrsearch'] === 'Ha Long Bay Hanoi');
        Http::assertSent(fn (Request $r) => $r['gsrsearch'] === 'Iwahig Firefly Puerto Princesa');
    }

    public function test_a_photo_that_will_not_download_falls_through_to_commons(): void
    {
        // Bilbao's article photo is a large original Wikimedia refused (429).
        $bilbao = Destination::create(['name' => 'Bilbao', 'country' => 'Spain']);

        Http::fake($this->wikipedia([$this->page(1, 'Bilbao', 'https://upload.wikimedia.org/Collage_de_Bilbao.png')])
            + $this->commons([$this->file(1, 'Museo Guggenheim, Bilbao.jpg')])
            + [
                'https://upload.wikimedia.org/Collage_de_Bilbao.png' => Http::response('Too many requests', 429, ['Content-Type' => 'text/html']),
                'https://upload.wikimedia.org/thumb/*'                => Http::response($this->jpeg(1200, 800), 200, ['Content-Type' => 'image/jpeg']),
            ]);

        $this->assertTrue((new PlaceImageService())->fetchForDestination($bilbao, false));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'Guggenheim'));
        $this->assertSame("destination-images/bilbao-{$bilbao->id}.jpg", $bilbao->fresh()->image);
    }

    public function test_serpapi_skips_social_media_links_that_are_pages_not_images(): void
    {
        // Gardens of Malasag and KCC Mall Grounds both got a Facebook
        // "lookaside" link as their largest result; it answers with HTML.
        config(['services.serpapi.key' => 'test-key']);
        Http::fake(['serpapi.com/*' => Http::response(['images_results' => [
            ['original' => 'https://lookaside.fbsbx.com/lookaside/crawler/media/?media_id=1', 'original_width' => 4000, 'original_height' => 3000],
            ['original' => 'https://example.org/kcc-mall.jpg', 'original_width' => 1600, 'original_height' => 1200],
        ]])]);

        $this->assertSame('https://example.org/kcc-mall.jpg',
            (new \App\Services\SerpApiService())->searchPlaceImage('KCC Mall Grounds', 'General Santos'));
    }

    public function test_a_download_that_is_not_an_image_is_not_saved(): void
    {
        $place = Destination::create(['name' => 'Nowhereville', 'country' => 'Philippines']);
        Http::fake($this->wikipedia([$this->page(1, 'Nowhereville', 'https://upload.wikimedia.org/nowhereville.jpg')])
            + $this->commons([])
            + ['https://upload.wikimedia.org/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertFalse((new PlaceImageService())->fetchForDestination($place, false));
        $this->assertNull($place->fresh()->image);
    }

    public function test_the_backfill_never_spends_serpapi_quota(): void
    {
        $destination = Destination::create(['name' => 'Nowhereville', 'country' => 'Philippines']);
        Http::fake($this->wikipedia([]) + ['*' => Http::response('', 500)]);

        $this->assertFalse((new PlaceImageService())->fetchForDestination($destination, false));

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'serpapi.com'));
        $this->assertNull($destination->fresh()->image);
    }

    public function test_the_command_fills_both_and_lends_an_attraction_its_citys_photo(): void
    {
        $elNido = Destination::create(['name' => 'El Nido', 'country' => 'Philippines']);
        $lagoon = Attraction::create(['name' => 'Big Lagoon', 'destination' => 'El Nido', 'region' => 'local', 'category' => 'Nature', 'rating' => 4.8]);

        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'wikipedia.org/w/api.php')) {
                // El Nido has an article; "Big Lagoon" matches nothing.
                return Http::response(['query' => ['pages' => str_contains($r['gsrsearch'], 'Big Lagoon')
                    ? [$this->page(1, 'Palawan', 'https://upload.wikimedia.org/palawan.jpg')]
                    : [$this->page(1, 'El Nido, Palawan', 'https://upload.wikimedia.org/el-nido.jpg')]]]);
            }

            return Http::response($this->jpeg(800, 600), 200, ['Content-Type' => 'image/jpeg']);
        });

        $this->artisan('app:fill-place-images-from-wikipedia')
            ->expectsOutputToContain('1 of 1 destination photo(s) found on Wikipedia or Commons.')
            ->expectsOutputToContain("Using their city's photo: Big Lagoon")
            ->assertSuccessful();

        $this->assertSame("destination-images/el-nido-{$elNido->id}.jpg", $elNido->fresh()->image);
        $this->assertSame($elNido->fresh()->image, $lagoon->fresh()->image);
    }
}
