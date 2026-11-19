<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class GroqService
{
    private const MAX_AI_ITINERARY_DAYS = 10;

    private string $key;
    private string $endpoint = 'https://api.groq.com/openai/v1/chat/completions';
    private string $model    = 'qwen/qwen3.8-27b';

    public function __construct()
    {
        $this->key = config('services.groq.key', '');
    }

    /**
     * True when the last generate() failed only because Groq's per-minute
     * token allowance ran out, which refills within the minute. The trip
     * planner reads it so that one such refusal doesn't strike Groq off for
     * the rest of a batch of itinerary options.
     */
    public bool $lastFailureWasTemporary = false;

    // The longest per-minute wait still worth sitting out. Anything longer
    // is a daily or account-level limit, not the per-minute one.
    private const MAX_RATE_LIMIT_WAIT = 60.0;

    /**
     * $timeout is the whole budget for this call, waiting included: on a
     * per-minute rate limit this sleeps for exactly as long as Groq says the
     * allowance takes to refill (x-ratelimit-reset-tokens), then retries,
     * as long as that still leaves time for the request itself.
     *
     * The free tier allows 8,000 tokens a minute and one itinerary uses
     * about half of that, so the planner's second and third options used to
     * get a 429 back-to-back. This used to wait at most two seconds and give
     * up, which is why step 8 so often showed a single option.
     */
    public function generate(string $prompt, int $timeout = 18, int $maxTokens = 2048): ?string
    {
        $this->lastFailureWasTemporary = false;
        if (!$this->key) return null;

        $deadline = microtime(true) + $timeout;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 3) return null;

            try {
                $response = Http::timeout($remaining)
                    ->connectTimeout(min(10, $remaining))
                    ->withToken($this->key)
                    ->post($this->endpoint, [
                        'model'       => $this->model,
                        'temperature' => 0.7,
                        'max_tokens'  => $maxTokens,
                        'messages'    => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                    ]);
            } catch (\Illuminate\Http\Client\ConnectionException) {
                if ($attempt === 0) continue;
                return null;
            }

            if ($response->successful()) {
                return data_get($response->json(), 'choices.0.message.content');
            }

            if ($response->status() === 429) {
                $wait = self::rateLimitWait($response);
                // The reset header says when SOME tokens are back, not when
                // enough are for this request. A big request (the three-option
                // one is ~6,500 tokens) retried on it just gets another 429, so
                // also work out how long the allowance takes to cover it.
                $limit     = (int) $response->header('x-ratelimit-limit-tokens');
                $available = $response->header('x-ratelimit-remaining-tokens');
                if ($wait !== null && $limit > 0 && is_numeric($available)) {
                    $needed = (int) ceil(strlen($prompt) / 3.5) + $maxTokens;
                    $short  = $needed - (int) $available;
                    if ($short > 0) $wait = max($wait, $short / ($limit / 60));
                }
                if ($wait === null || $wait > self::MAX_RATE_LIMIT_WAIT) return null;

                // Refills within the minute: worth another try, now or on the
                // caller's next attempt if this call has run out of time.
                $this->lastFailureWasTemporary = true;
                // A short margin past the stated reset, and at least 5s left
                // over for the request once the wait is done.
                $wait += 0.5;
                if (microtime(true) + $wait > $deadline - 5) return null;

                usleep((int) ($wait * 1_000_000));
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * Seconds until Groq will take another request, from its own headers:
     * Retry-After when present, otherwise the token or request reset time,
     * written like "7.66s", "142ms" or "1m2.5s". Null when none of them is
     * readable.
     */
    public static function rateLimitWait(\Illuminate\Http\Client\Response $response): ?float
    {
        $waits = [];

        // Retry-After can be shorter than the token reset (2s against 17s was
        // seen): retrying on it alone just earns another 429. Take the longer.
        $retryAfter = $response->header('Retry-After');
        if (is_numeric($retryAfter)) $waits[] = (float) $retryAfter;

        // Tokens first: the per-minute token allowance is what an itinerary
        // runs out of. The request reset tracks the daily request count and
        // reads in tens of minutes even when only the tokens are short, so it
        // is only a fallback.
        foreach (['x-ratelimit-reset-tokens', 'x-ratelimit-reset-requests'] as $header) {
            $value = trim((string) $response->header($header));
            if ($value === '' || !preg_match('/^(?:(\d+)h)?(?:(\d+)m(?!s))?(?:([\d.]+)s)?(?:([\d.]+)ms)?$/', $value, $m)) continue;
            $waits[] = (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (float) ($m[3] ?? 0) + (float) ($m[4] ?? 0) / 1000;
            break;
        }

        return $waits ? max($waits) : null;
    }

    public function planTrip(string $userPrompt): ?array
    {
        $today = date('l, M j, Y');

        $prompt = <<<PROMPT
You are a travel planner AI. Read the traveler's input, extract all details, then generate a realistic trip package. Return JSON only — no markdown, no explanation, no extra text.

Today's date is {$today}.

Traveler's input: "{$userPrompt}"

Instructions:
1. Extract: origin city (default "Manila" if not mentioned), destination city, budget (min and max in PHP pesos — if single value use it for both), travel start date, travel end date, and number of days.
2. Generate a realistic trip package using REAL hotels, airlines, restaurants, and attractions that exist at the DESTINATION city named above — never the origin city, even if the origin is more prominent or familiar to you.
3. ALL costs combined must NOT exceed the budget_max.
4. Budget split suggestion: transport 18%, accommodation 50%, food 28%, attractions 4%.
5. Use correct Philippine IATA airport codes (MNL=Manila, CEB=Cebu City, DVO=Davao, BCD=Bacolod, ILO=Iloilo, ZAM=Zamboanga, KLO=Kalibo/Boracay, MPH=Malay/Boracay, TAG=Tagbilaran/Bohol, PPS=Puerto Princesa, CGY=Cagayan de Oro, GES=General Santos, etc.).
6. Dates: date_from format "Mon D" (e.g. "Jul 16"), date_to format "Mon D, YYYY" (e.g. "Jul 21, 2026"). If the traveler gave a relative time frame ("next week", "in 3 days") or a duration instead of an end date ("for 5 days", "for a week"), compute the actual calendar dates using today's date above as the reference point — the resulting date_from must never be earlier than today.

Return ONLY this JSON:
{
  "from": "origin city name",
  "to": "destination city name",
  "budget_min": number,
  "budget_max": number,
  "date_from": "Mon D",
  "date_to": "Mon D, YYYY",
  "days": number,
  "transport": {
    "from_code": "IATA",
    "to_code": "IATA",
    "detail": "Airline name · Direct Flight · Round Trip",
    "cost": number
  },
  "accommodation": {
    "name": "Hotel name",
    "stars": number,
    "detail": "N Nights · Room type · City",
    "cost": number
  },
  "food": {
    "name": "Restaurant name (₱amount/pax)",
    "detail": "N Days · Breakfast, Lunch & Dinner · City",
    "cost": number
  },
  "attractions": {
    "items": [["Attraction name", "Free or ₱amount"], ["Attraction name", "Free or ₱amount"]],
    "cost": number
  },
  "total": number,
  "budget": number,
  "pct": number
}
PROMPT;

        $raw = $this->generate($prompt);
        if (!$raw) return null;

        $raw = preg_replace('/```json\s*/i', '', $raw);
        $raw = preg_replace('/```\s*/i', '', $raw);
        $raw = trim($raw);

        return json_decode($raw, true);
    }

    public function enrichPackage(array $package, string $destination, int $days, int $budget): ?array
    {
        $json = json_encode($package, JSON_UNESCAPED_UNICODE);
        $prompt = <<<PROMPT
You are a travel expert. A trip package has been assembled from live search data for {$destination} ({$days} days, ₱{$budget} budget). Enrich and fix missing or placeholder values only — do NOT change costs or items that already look real.

Current package JSON:
{$json}

Rules:
1. If hotel name is generic (e.g. "Hotel in {$destination}"), replace with a real hotel name that exists in {$destination}.
2. If hotel room type is "Standard Room" and stars ≥ 4, upgrade to "Deluxe Room" or "Superior Room".
3. If any attraction is listed as "Free" but has a known entrance fee in the Philippines, add the real price as "₱NNN".
4. If food detail city says "Philippines" or is empty, replace with "{$destination}".
5. Keep all numeric costs exactly as-is — never change any number.
6. Return the same JSON structure with only the string fields fixed. Return JSON only, no markdown.
PROMPT;

        $raw = $this->generate($prompt);
        if (!$raw) return null;

        $raw = preg_replace('/```json\s*/i', '', $raw);
        $raw = preg_replace('/```\s*/i', '', $raw);
        $decoded = json_decode(trim($raw), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function suggestAdditionalItinerary(
        string  $destination,
        string  $startDate,
        string  $endDate,
        int     $budgetMin,
        int     $budgetMax,
        int     $profileBudget,
        array   $interests,
        array   $alreadySelected = [],
        ?string $constraint = null,
        string  $departTime = '',
        bool    $needsAccommodation = false,
        int     $timeout = 18
    ): ?array {
        $days         = max(1, (int) round((strtotime($endDate) - strtotime($startDate)) / 86400) + 1);
        $days         = min($days, self::MAX_AI_ITINERARY_DAYS);
        $selected     = implode(', ', $alreadySelected) ?: 'none';
        $tags         = implode(', ', array_filter($interests, fn($i) => strlen($i) < 80)) ?: 'general travel';
        $minBudg      = $budgetMin ?: (int) round(($budgetMax ?: 0) * 0.7);
        $maxBudg      = $budgetMax ?: $budgetMin;
        $minPerDay    = (int) round($minBudg / max(1, $days));
        $maxPerDay    = (int) round($maxBudg / max(1, $days));
        $extraRule       = $constraint ? "\n0. OVERRIDE CONSTRAINT: {$constraint}" : '';
        $departTimeLabel = $departTime ?: '05:00 PM';
        $accommodationRule = $needsAccommodation
            ? "\n7. The traveler has NOT booked accommodation yet. Add ONE lodging suggestion as the FIRST activity on Day 1: a real, existing hotel or guesthouse in {$destination} that fits the budget, type \"accommodation\", time \"02:00 PM\", title the hotel's name, description mentioning room type and star rating, and cost equal to the TOTAL price for all {$days} night(s) of the stay (not a per-night rate). Do not add a separate accommodation entry on any other day."
            : '';

        $prompt = <<<PROMPT
You are a Philippine travel itinerary AI. Generate ADDITIONAL daily activities for a trip to {$destination} that complement what the traveler already selected.

Trip details:
- Destination: {$destination}
- Dates: {$startDate} to {$endDate} ({$days} days)
- Budget range: ₱{$minBudg} (minimum) to ₱{$maxBudg} (maximum)
- Target daily spend: ₱{$minPerDay}–₱{$maxPerDay}/day
- Traveler interests: {$tags}
- Already selected by traveler: {$selected}

Rules:{$extraRule}
1. Do NOT repeat the already-selected items.
2. Suggest realistic, existing places in {$destination}.
3. ALL suggested activities MUST directly reflect the traveler's interests: {$tags}. Every day's label and activities should align with one or more of these interests.
4. BUDGET RULE: The combined cost of ALL suggested activities MUST fall between ₱{$minBudg} and ₱{$maxBudg}. Do NOT exceed ₱{$maxBudg}. Aim close to ₱{$maxBudg}.
5. Each day's activities should collectively cost between ₱{$minPerDay} and ₱{$maxPerDay}.
6. Spread activities across morning / afternoon / evening slots (3 activities per day minimum).{$accommodationRule}
7. On the LAST day, schedule light morning/midday activities (souvenir shopping, nearby café, short attraction) that finish at least 3 hours before the departure flight. The traveler's departure flight is at {$departTimeLabel}. Add a final activity entry on the last day: type "transport", title "Head to Airport / Departure", time set to 2 hours before {$departTimeLabel}, cost 0.
8. Return JSON only — no markdown, no explanation.

Return this exact JSON structure:
{
  "days": [
    {
      "day": 1,
      "label": "Day 1 label (e.g. Arrival & Explore)",
      "activities": [
        {
          "time": "09:00 AM",
          "title": "Activity name",
          "description": "Short description",
          "type": "accommodation|food|restaurant|attraction|leisure|activity|transport|shopping|nature|beach|culture|adventure|nightlife|spa",
          "cost": 0
        }
      ]
    }
  ]
}
PROMPT;

        $raw = $this->generate($prompt, $timeout);
        if (!$raw) return null;

        $raw = preg_replace('/```json\s*/i', '', $raw);
        $raw = preg_replace('/```\s*$/im', '', $raw);
        $decoded = json_decode(trim($raw), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Several different itineraries for the same trip in ONE request, one per
     * entry of $constraints, in that order. Returns the ones that came back
     * well-formed (each ['days' => [...]] with its '_constraint' index), or
     * null if the request failed outright.
     *
     * One request rather than one per option because of the free tier's
     * 8,000 tokens a minute: three separate itineraries back to back meant a
     * 20–40s wait before the third, while all three in one answer fit in a
     * single minute's allowance. Same rules as suggestAdditionalItinerary().
     *
     * @param  string[]  $constraints
     */
    public function suggestItineraryOptions(
        string  $destination,
        string  $startDate,
        string  $endDate,
        int     $budgetMin,
        int     $budgetMax,
        int     $profileBudget,
        array   $interests,
        array   $alreadySelected,
        array   $constraints,
        string  $departTime = '',
        bool    $needsAccommodation = false,
        int     $timeout = 40
    ): ?array {
        $count        = count($constraints);
        if ($count === 0) return [];
        $days         = max(1, (int) round((strtotime($endDate) - strtotime($startDate)) / 86400) + 1);
        $days         = min($days, self::MAX_AI_ITINERARY_DAYS);
        $selected     = implode(', ', $alreadySelected) ?: 'none';
        $tags         = implode(', ', array_filter($interests, fn($i) => strlen($i) < 80)) ?: 'general travel';
        $minBudg      = $budgetMin ?: (int) round(($budgetMax ?: 0) * 0.7);
        $maxBudg      = $budgetMax ?: $budgetMin;
        $minPerDay    = (int) round($minBudg / max(1, $days));
        $maxPerDay    = (int) round($maxBudg / max(1, $days));
        $departTimeLabel = $departTime ?: '05:00 PM';
        $accommodationRule = $needsAccommodation
            ? "\n7. The traveler has NOT booked accommodation yet. In EVERY option, add ONE lodging suggestion as the FIRST activity on Day 1: a real, existing hotel or guesthouse in {$destination} that fits that option's budget, type \"accommodation\", time \"02:00 PM\", title the hotel's name, description mentioning room type and star rating, and cost equal to the TOTAL price for all {$days} night(s) of the stay (not a per-night rate)."
            : '';
        $styles = implode("\n", array_map(
            fn ($c, $i) => '- Option ' . ($i + 1) . ': ' . $c,
            $constraints, array_keys($constraints)
        ));

        $prompt = <<<PROMPT
You are a Philippine travel itinerary AI. Create {$count} DIFFERENT itinerary options for the same trip to {$destination}, each complementing what the traveler already selected.

Trip details:
- Destination: {$destination}
- Dates: {$startDate} to {$endDate} ({$days} days)
- Budget range: ₱{$minBudg} (minimum) to ₱{$maxBudg} (maximum)
- Target daily spend: ₱{$minPerDay}–₱{$maxPerDay}/day
- Traveler interests: {$tags}
- Already selected by traveler: {$selected}

Each option has its own style:
{$styles}

Rules for EVERY option:
0. Follow that option's style. The options must be clearly different from each other — different places, not the same plan reworded.
1. Do NOT repeat the already-selected items.
2. Suggest realistic, existing places in {$destination}.
3. Activities MUST reflect the traveler's interests: {$tags}.
4. BUDGET RULE: an option's combined activity cost MUST fall between ₱{$minBudg} and ₱{$maxBudg}.
5. Each day's activities should collectively cost between ₱{$minPerDay} and ₱{$maxPerDay}.
6. 3 or 4 activities per day across morning / afternoon / evening. Keep each description under 12 words.{$accommodationRule}
7. On the LAST day, keep to light morning/midday activities that finish at least 3 hours before the {$departTimeLabel} departure flight, then a final entry: type "transport", title "Head to Airport / Departure", time 2 hours before {$departTimeLabel}, cost 0.
8. Return JSON only — no markdown, no explanation.

Return this exact JSON structure, with exactly {$count} entries in "options", in the order listed above:
{
  "options": [
    {
      "days": [
        {
          "day": 1,
          "label": "Day 1 label (e.g. Arrival & Explore)",
          "activities": [
            {
              "time": "09:00 AM",
              "title": "Activity name",
              "description": "Short description",
              "type": "accommodation|food|restaurant|attraction|leisure|activity|transport|shopping|nature|beach|culture|adventure|nightlife|spa",
              "cost": 0
            }
          ]
        }
      ]
    }
  ]
}
PROMPT;

        // Room for every option's days, within one minute's 8,000-token
        // allowance once the ~1,000-token prompt is counted.
        $maxTokens = min(6800, 500 + $count * $days * 330);

        $raw = $this->generate($prompt, $timeout, $maxTokens);
        if (!$raw) return null;

        $raw = preg_replace('/<think>.*?<\/think>/s', '', $raw);
        $raw = preg_replace('/```json\s*/i', '', $raw);
        $raw = preg_replace('/```\s*$/im', '', $raw);
        $decoded = json_decode(trim($raw), true);
        if (!is_array($decoded) || !is_array($decoded['options'] ?? null)) return null;

        $options = [];
        foreach (array_values($decoded['options']) as $i => $option) {
            if ($i >= $count) break;
            if (!is_array($option['days'] ?? null) || $option['days'] === []) continue;
            $options[] = ['days' => $option['days'], '_constraint' => $i];
        }

        return $options;
    }
}
