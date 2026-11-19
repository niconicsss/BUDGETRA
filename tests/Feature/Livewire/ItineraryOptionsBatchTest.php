<?php
namespace Tests\Feature\Livewire;

use App\Livewire\Traveler\TripPlannerWizard;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\GroqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 8 is meant to show three itinerary options. Groq's free tier allows
 * 8,000 tokens a minute and an itinerary is ~2,300, so asked for one at a
 * time it refused the second and third, and with the other providers down
 * only one card ever appeared. All three now come from one request, and a
 * per-minute refusal is waited out rather than treated as final.
 */
class ItineraryOptionsBatchTest extends TestCase
{
    use RefreshDatabase;

    private function day(int $n, string $label, string $title, int $cost): array
    {
        return ['day' => $n, 'label' => $label, 'activities' => [
            ['time' => '09:00 AM', 'title' => $title, 'description' => 'x', 'type' => 'beach', 'cost' => $cost],
        ]];
    }

    private function groqAnswer(array $payload): array
    {
        return ['choices' => [['message' => ['content' => json_encode($payload)]]]];
    }

    private function threeOptions(): array
    {
        return ['options' => [
            ['days' => [$this->day(1, 'Culture', 'Museum Walk', 1500)]],
            ['days' => [$this->day(1, 'Adventure', 'Cliff Diving', 4000)]],
            ['days' => [$this->day(1, 'Budget', 'Free Beach Day', 300)]],
        ]];
    }

    private function wizard()
    {
        $user = User::factory()->create(['country' => 'Philippines']);
        UserProfile::create(['user_id' => $user->id, 'daily_budget' => 3000, 'interests' => ['Beach']]);

        return Livewire::actingAs($user)->test(TripPlannerWizard::class)
            ->set('planningMode', 'manual')->set('manualFrom', 'Manila')->set('manualTo', 'Boracay')
            ->set('startDate', '2026-11-24')->set('endDate', '2026-11-25')
            ->set('manualBudgetMin', '30000')->set('manualBudgetMax', '30000');
    }

    private function response(int $status, array $headers): Response
    {
        return new Response(new \GuzzleHttp\Psr7\Response($status, $headers));
    }

    public function test_one_groq_request_gives_all_three_options(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->groqAnswer($this->threeOptions()))]);

        $options = $this->wizard()->call('generateItinerary')->get('aiItineraryOptions');

        $this->assertCount(3, $options);
        $this->assertSame(['Museum Walk', 'Cliff Diving', 'Free Beach Day'],
            array_map(fn ($o) => $o['days'][0]['activities'][0]['title'], $options));
        $this->assertSame(['Balanced Mix', 'Adventure Focus', 'Budget Friendly'], array_column($options, '_optionLabel'));
        // No per-option requests to any provider once the batch answered.
        Http::assertSentCount(1);
    }

    public function test_the_page_then_shows_three_cards(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->groqAnswer($this->threeOptions()))]);

        $html = $this->wizard()->call('generateItinerary')->html();

        $this->assertSame(3, substr_count($html, 'class="itin8-plan '));
    }

    public function test_options_the_batch_left_out_are_filled_one_at_a_time(): void
    {
        $batch = ['options' => [['days' => [$this->day(1, 'Culture', 'Museum Walk', 1500)]], ['days' => []]]];
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push($this->groqAnswer($batch))
                ->push($this->groqAnswer(['days' => [$this->day(1, 'Adventure', 'Cliff Diving', 4000)]]))
                ->push($this->groqAnswer(['days' => [$this->day(1, 'Budget', 'Free Beach Day', 300)]])),
            '*' => Http::response([], 500),
        ]);

        $options = $this->wizard()->call('generateItinerary')->get('aiItineraryOptions');

        $this->assertSame(['Museum Walk', 'Cliff Diving', 'Free Beach Day'],
            array_map(fn ($o) => $o['days'][0]['activities'][0]['title'], $options));
    }

    public function test_a_per_minute_limit_is_waited_out_and_retried(): void
    {
        Http::fake(['api.groq.com/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429, [
                'x-ratelimit-reset-tokens' => '300ms', 'x-ratelimit-limit-tokens' => '8000', 'x-ratelimit-remaining-tokens' => '7990',
            ])
            ->push($this->groqAnswer(['days' => [$this->day(1, 'A', 'B', 1)]]))]);

        $groq = new GroqService();
        $this->assertNotNull($groq->generate('short prompt', 10, 10));
        Http::assertSentCount(2);
    }

    public function test_a_per_minute_refusal_is_reported_as_temporary(): void
    {
        // A minute-long wait doesn't fit in a 6s budget, so this call gives up,
        // but says the refusal will pass, so the planner keeps Groq for the
        // next option.
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'rate limited'], 429, ['x-ratelimit-reset-tokens' => '45s'])]);

        $groq = new GroqService();
        $this->assertNull($groq->generate('prompt', 6));
        $this->assertTrue($groq->lastFailureWasTemporary);
    }

    public function test_a_daily_or_account_refusal_is_not_temporary(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'quota'], 429, ['x-ratelimit-reset-tokens' => '3h0m0s'])]);

        $groq = new GroqService();
        $this->assertNull($groq->generate('prompt', 6));
        $this->assertFalse($groq->lastFailureWasTemporary);
    }

    public function test_reset_times_are_read_in_groqs_formats(): void
    {
        $this->assertEqualsWithDelta(7.66, GroqService::rateLimitWait($this->response(429, ['x-ratelimit-reset-tokens' => '7.66s'])), 0.001);
        $this->assertEqualsWithDelta(0.142, GroqService::rateLimitWait($this->response(429, ['x-ratelimit-reset-tokens' => '142ms'])), 0.001);
        $this->assertEqualsWithDelta(62.5, GroqService::rateLimitWait($this->response(429, ['x-ratelimit-reset-tokens' => '1m2.5s'])), 0.001);
        $this->assertNull(GroqService::rateLimitWait($this->response(429, [])));
    }

    public function test_the_longer_of_retry_after_and_the_token_reset_wins(): void
    {
        // Seen live: Retry-After 2 with the tokens 17s away. Retrying at 2s
        // just earned another 429.
        $this->assertEqualsWithDelta(17.4, GroqService::rateLimitWait($this->response(429, [
            'Retry-After' => '2', 'x-ratelimit-reset-tokens' => '17.4s', 'x-ratelimit-reset-requests' => '48m57.6s',
        ])), 0.001);
    }
}
