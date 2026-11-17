<?php

namespace App\Console\Commands;

use App\Models\Attraction;
use App\Models\Destination;
use App\Services\PlaceImageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:fill-place-images-from-wikipedia {--destinations : Only destinations} {--attractions : Only attractions} {--limit=0 : Stop after this many places (0 = all)} {--serpapi : For places Wikipedia and Commons have nothing of, fall back to SerpAPI (spends the shared daily search quota)}')]
#[Description('Fill missing destination and attraction photos from Wikipedia and Wikimedia Commons (free, no daily cap); SerpAPI only with --serpapi')]
class FillPlaceImagesFromWikipedia extends Command
{
    // A pause between places keeps this a polite Wikimedia client; at 0.15s
    // a full run of ~400 places got throttled partway through.
    private const PAUSE_MICROSECONDS = 600_000;

    public function handle(PlaceImageService $images): int
    {
        $both  = !$this->option('destinations') && !$this->option('attractions');
        $serp  = (bool) $this->option('serpapi');
        $limit = max(0, (int) $this->option('limit'));
        $done  = 0;

        $jobs = [];
        if ($both || $this->option('destinations')) {
            $jobs['destination'] = [Destination::whereNull('image')->orWhere('image', '')->orderBy('name')->get(),
                                    fn (Destination $d) => $images->fetchForDestination($d, $serp)];
        }
        if ($both || $this->option('attractions')) {
            // Attractions showing a photo borrowed from their city (a
            // destination-images/ path) are tried again too: the borrowed one
            // is a stand-in, and the attraction's own may be there this time.
            $jobs['attraction'] = [Attraction::where(fn ($q) => $q->whereNull('image')->orWhere('image', '')
                                        ->orWhere('image', 'like', 'destination-images/%'))
                                        ->orderBy('name')->get(),
                                   fn (Attraction $a) => $images->fetchForAttraction($a, $serp)];
        }

        foreach ($jobs as $label => [$places, $fetch]) {
            $found    = 0;
            $borrowed = [];
            $missed   = [];

            foreach ($places as $place) {
                if ($limit && $done >= $limit) break;
                $done++;

                if ($fetch($place)) {
                    $found++;
                } elseif ($label === 'attraction' && $this->borrowCityPhoto($place)) {
                    $borrowed[] = $place->name;
                } else {
                    $missed[] = $place->name;
                }
                usleep(self::PAUSE_MICROSECONDS);
            }

            $this->info("{$found} of " . ($found + count($borrowed) + count($missed)) . " {$label} photo(s) found" . ($serp ? '.' : ' on Wikipedia or Commons.'));
            if ($borrowed) {
                $this->line('  Using their city\'s photo: ' . implode(', ', $borrowed));
            }
            if ($missed) {
                $this->line('  No photo for: ' . implode(', ', $missed));
            }
        }

        return self::SUCCESS;
    }

    /**
     * An attraction Wikipedia has no photo of ("Big Lagoon") shows its city's
     * photo instead (El Nido) — a real view of where it is beats the grey
     * placeholder. Points at the same stored file; nothing is copied. Runs
     * after destinations, so their photos are already in place.
     */
    private function borrowCityPhoto(Attraction $attraction): bool
    {
        // Already showing its city's photo from an earlier run: keep it.
        if (str_starts_with((string) $attraction->image, 'destination-images/')) return true;

        $cityPhoto = Destination::where('name', $attraction->destination)
            ->whereNotNull('image')->where('image', '!=', '')
            ->value('image');

        if (!$cityPhoto) return false;

        $attraction->update(['image' => $cityPhoto]);
        return true;
    }
}
