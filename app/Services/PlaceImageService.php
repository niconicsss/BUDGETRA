<?php
namespace App\Services;

use App\Models\Attraction;
use App\Models\Destination;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PlaceImageService
{
    // Wikimedia asks every API client to identify itself; requests without a
    // User-Agent can be refused. Generic on purpose — nothing about the user.
    private const USER_AGENT = 'Budgetra/1.0 (travel budgeting app)';

    // Words that describe the visit rather than the place: "Niagara Falls Day
    // Trip" is a photo of Niagara Falls. Ignored when checking that a
    // Wikipedia result is about the place asked for.
    private const FILLER_WORDS = [
        'the', 'of', 'a', 'an', 'and', 'de', 'del', 'la', 'el', 'at', 'in', 'on',
        'day', 'trip', 'tour', 'tours', 'view', 'viewing', 'diving', 'hopping',
        'cruise', 'walk', 'visit', 'experience', 'watching',
    ];

    // Fetches a photo and downloads it into local storage — same disk/naming
    // convention as a manual admin upload — rather than storing a bare
    // external URL, so it keeps working even if the source link later breaks
    // or blocks hotlinking. Shared by the admin "Fetch Photo" buttons and the
    // bulk-fill console commands (for both destinations and attractions) so
    // the download/store logic only lives in one place.
    //
    // Wikipedia is asked first: it is free, needs no key, and every
    // destination and landmark of note has a lead photo there. SerpAPI
    // (Google Images) is the fallback, because each of its searches comes out
    // of the same small daily quota flights and hotels run on.
    public function fetchForDestination(Destination $destination, bool $serpApiFallback = true): bool
    {
        return $this->fetch($destination, $destination->name, $destination->country, 'travel destination', 'destination-images', $serpApiFallback);
    }

    public function fetchForAttraction(Attraction $attraction, bool $serpApiFallback = true): bool
    {
        return $this->fetch($attraction, $attraction->name, $attraction->destination, 'tourist attraction landmark', 'attraction-images', $serpApiFallback);
    }

    private function fetch(Destination|Attraction $model, string $name, ?string $context, string $hint, string $folder, bool $serpApiFallback): bool
    {
        // Wikipedia's article photo, then Wikimedia Commons' photo library —
        // both free — and only then SerpAPI's paid quota. Each source is only
        // asked once the one before it has failed, and a photo that won't
        // download moves on to the next source rather than ending the search:
        // Bilbao's article photo is a large original Wikimedia refused to
        // serve, while Commons had a Guggenheim photo ready.
        $sources = function () use ($name, $context, $hint, $serpApiFallback) {
            yield $this->wikipediaImage($name, $context);
            yield $this->commonsImage($name, $context);
            if ($serpApiFallback) {
                yield (new SerpApiService())->searchPlaceImage($name, $context, $hint);
            }
        };

        foreach ($sources() as $url) {
            if ($url && $this->store($model, $name, $folder, $url)) return true;
        }

        return false;
    }

    /** Downloads one photo and saves it as this place's image. */
    private function store(Destination|Attraction $model, string $name, string $folder, string $url): bool
    {
        try {
            $response = $this->http(15)->get($url);
        } catch (\Throwable) {
            return false;
        }
        if (!$response->successful()) return false;
        if (!str_starts_with($response->header('Content-Type', ''), 'image/')) return false;

        $body      = $response->body();
        $extension = match (true) {
            str_contains($response->header('Content-Type', ''), 'png')  => 'png',
            str_contains($response->header('Content-Type', ''), 'webp') => 'webp',
            default => 'jpg',
        };

        // Shrunk to the size the pages actually show. Wikipedia only serves
        // fixed thumbnail steps, so a 1280px original arrives at ~350 KB.
        if ($shrunk = $this->shrink($body)) {
            [$body, $extension] = [$shrunk, 'jpg'];
        }

        // The id keeps two same-named places ("Night Market" in two cities)
        // from overwriting each other's photo.
        $filename = Str::slug($name) . '-' . $model->getKey() . '.' . $extension;
        Storage::disk('public')->put($folder . '/' . $filename, $body);
        $model->update(['image' => $folder . '/' . $filename]);
        return true;
    }

    // Wikimedia throttles bursts (HTTP 429). A bulk fill of 400 places hit
    // that, and three retries 0.8s apart were not enough: Tokyo, Seoul and
    // Burj Khalifa all came back empty while each one worked on its own.
    // Backing off for longer turns those into photos.
    private function http(int $timeout): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($timeout)
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->retry([1500, 4000, 8000], throw: false);
    }

    /**
     * Re-encodes a photo at no more than 1024px wide as an 82%-quality JPEG,
     * or returns null to keep the original (no GD, an unreadable image, or
     * one that is already small enough to leave alone).
     */
    private function shrink(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;

        $image = @imagecreatefromstring($bytes);
        if ($image === false) return null;

        $width = imagesx($image);
        if ($width > 1024) {
            $scaled = imagescale($image, 1024);
            imagedestroy($image);
            if ($scaled === false) return null;
            $image = $scaled;
        }

        ob_start();
        imagejpeg($image, null, 82);
        imagedestroy($image);
        $jpeg = (string) ob_get_clean();

        return ($jpeg !== '' && strlen($jpeg) < strlen($bytes)) ? $jpeg : null;
    }

    /**
     * The lead photo of the Wikipedia article about this place, or null.
     *
     * Searches "name + context" (the country for a destination, the city for
     * an attraction) and takes the result that is most about the place:
     *  - its title must share a real word with the name, and
     *  - a title with the name's own words ("Big Buddha") beats one that only
     *    shares the city also written into it ("Big Buddha Phuket" → the
     *    Phuket article), which is kept only as a last resort.
     * Ties keep Wikipedia's search order. The lead image has to be a photo:
     * locator maps, flags and seals are almost always SVG drawings, so those
     * results are skipped. Only freely licensed images are returned (the
     * API's default).
     */
    public function wikipediaImage(string $name, ?string $context): ?string
    {
        try {
            $response = $this->http(10)
                ->get('https://en.wikipedia.org/w/api.php', [
                    'action'      => 'query',
                    'format'      => 'json',
                    'formatversion' => 2,
                    'generator'   => 'search',
                    'gsrsearch'   => trim($this->searchName($name) . ' ' . $context),
                    'gsrlimit'    => 5,
                    'prop'        => 'pageimages',
                    'piprop'      => 'thumbnail',
                    // Wide enough for a page's hero image, about a third the
                    // size of a 1280px one on disk.
                    'pithumbsize' => 1024,
                ]);
        } catch (\Throwable) {
            return null;
        }
        if (!$response->successful()) return null;

        $pages = $response->json('query.pages') ?? [];
        usort($pages, fn ($a, $b) => ($a['index'] ?? 99) <=> ($b['index'] ?? 99));

        $wanted      = $this->words($name);
        // The words that are the place's own, not its city or country.
        $distinctive = array_values(array_diff($wanted, $this->words((string) $context))) ?: $wanted;

        $best = null;
        $bestScore = 0;

        foreach ($pages as $page) {
            $src = $page['thumbnail']['source'] ?? null;
            if (!$src) continue;
            // GIFs too: on Wikipedia they are maps and animations, not photos
            // (Garden Route's lead image is an overview map).
            // Whole words only: "Calle_Crisologo.jpg" contains "logo".
            if (preg_match('/\.svg|\.gif|_map[._]|(?<![a-z])(locator|logo|flag_of|seal_of|coat_of_arms)(?![a-z])/i', $src)) continue;

            $title = $this->words($page['title'] ?? '');
            if (!array_intersect($wanted, $title)) continue;

            // Distinctive words count for far more than shared ones, so
            // "Big Buddha (Phuket)" outranks "Phuket" for "Big Buddha Phuket".
            $score = 10 * count(array_intersect($distinctive, $title)) + count(array_intersect($wanted, $title));
            if ($score > $bestScore) {
                $best = $src;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * A photo from Wikimedia Commons, Wikipedia's free media library, or null.
     *
     * The fallback for places whose Wikipedia article has no usable lead
     * photo (Bali's is a locator map) or that have no article of their own
     * (Big Lagoon, Lake Sebu): Commons holds photos of nearly every named
     * place, and every file there is freely licensed.
     *
     * Files are matched on their names, which describe the photo ("Big Lagoon,
     * El Nido, Palawan.jpg"), so the name has to carry the place's own words —
     * both "big" and "lagoon", not just "El Nido" — and a name with more of
     * them wins. Only JPEG photos at least 800px wide are taken; maps, flags,
     * logos and diagrams are skipped.
     */
    public function commonsImage(string $name, ?string $context): ?string
    {
        // With the city first; if that finds nothing, the name alone — the
        // city's own files can crowd a smaller place out of the results.
        $searchName = $this->searchName($name);

        return $this->commonsSearch($name, $context, trim($searchName . ' ' . $context))
            ?? ($context ? $this->commonsSearch($name, $context, $searchName) : null);
    }

    private function commonsSearch(string $name, ?string $context, string $query): ?string
    {
        try {
            $response = $this->http(10)
                ->get('https://commons.wikimedia.org/w/api.php', [
                    'action'        => 'query',
                    'format'        => 'json',
                    'formatversion' => 2,
                    'generator'     => 'search',
                    'gsrnamespace'  => 6,   // File:
                    'gsrsearch'     => $query,
                    'gsrlimit'      => 15,
                    'prop'          => 'imageinfo',
                    'iiprop'        => 'url|mime|size',
                    'iiurlwidth'    => 1024,
                ]);
        } catch (\Throwable) {
            return null;
        }
        if (!$response->successful()) return null;

        $pages = $response->json('query.pages') ?? [];
        usort($pages, fn ($a, $b) => ($a['index'] ?? 99) <=> ($b['index'] ?? 99));

        $wanted      = $this->words($name);
        $distinctive = array_values(array_diff($wanted, $this->words((string) $context))) ?: $wanted;
        // Every distinctive word for a short name; most of them for a long
        // one ("Gardens of Malasag Eco-Tourism Village").
        $needed      = min(count($distinctive), max(1, (int) ceil(count($distinctive) * 0.6)));

        $best = null;
        $bestScore = 0;

        foreach ($pages as $page) {
            $info  = $page['imageinfo'][0] ?? null;
            $title = $page['title'] ?? '';
            if (!$info || ($info['mime'] ?? '') !== 'image/jpeg') continue;
            if (($info['width'] ?? 0) < 800) continue;
            // Whole words only: "Calle Crisologo" contains "logo".
            if (preg_match('/(?<![a-z])(maps?|locator|flags?|logos?|seals?|coat of arms|diagram|plan|chart|poster)(?![a-z])/i', $title)) continue;

            $words = $this->words(preg_replace('/^File:|\.[a-z0-9]+$/i', '', $title));
            $hits  = count(array_intersect($distinctive, $words));
            if ($hits < $needed) continue;

            $score = 10 * $hits + count(array_intersect($wanted, $words));
            if ($score > $bestScore) {
                $best = $info['thumburl'] ?? $info['url'] ?? null;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * The name as search text, without the words that describe the visit:
     * "Ha Long Bay Day Trip" → "Ha Long Bay". Search engines want every word
     * of the query, and photos are not named "… day trip".
     */
    private function searchName(string $name): string
    {
        $visit = array_slice(self::FILLER_WORDS, array_search('day', self::FILLER_WORDS, true));
        $kept  = array_filter(preg_split('/\s+/', trim($name)), fn ($w) => !in_array(strtolower($w), $visit, true));

        return implode(' ', $kept) ?: $name;
    }

    /** "Basilica del Santo Niño" → ['basilica', 'santo', 'nino'] */
    private function words(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_diff($words, self::FILLER_WORDS));
    }
}
