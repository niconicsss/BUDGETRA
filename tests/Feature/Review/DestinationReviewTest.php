<?php
namespace Tests\Feature\Review;

use App\Models\Attraction;
use App\Models\Destination;
use App\Models\Review;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Travelers read each other's reviews of a destination and share their own
 * on the destination page, the same way they already could on an
 * attraction's page (traveler/partials/reviews).
 */
class DestinationReviewTest extends TestCase
{
    use RefreshDatabase;

    private function traveler(string $name = 'Ana Cruz', ?string $country = 'Philippines'): User
    {
        $user = User::factory()->create(['full_name' => $name, 'country' => $country]);
        UserProfile::create(['user_id' => $user->id]);

        return $user;
    }

    private function siargao(): Destination
    {
        return Destination::create(['name' => 'Siargao', 'country' => 'Philippines']);
    }

    private function review(User $by, array $attrs = []): Review
    {
        return Review::create(array_merge([
            'user_id'     => $by->id,
            'destination' => 'Siargao',
            'rating'      => 5,
            'body'        => 'Great waves and friendly locals all around.',
            'status'      => 'active',
        ], $attrs));
    }

    public function test_the_destination_page_shows_other_travelers_reviews_of_it(): void
    {
        $siargao = $this->siargao();
        $lagoon  = Attraction::create(['name' => 'Sugba Lagoon', 'destination' => 'Siargao', 'region' => 'local', 'category' => 'Nature', 'rating' => 4.7]);

        $this->review($this->traveler('Ben Reyes'), ['body' => 'Cloud 9 at sunrise is unforgettable.']);
        $this->review($this->traveler('Cara Lim'), ['body' => 'Hidden from everyone.', 'status' => 'hidden']);
        $this->review($this->traveler('Dan Uy'), ['body' => 'About the lagoon, not the island.', 'attraction_id' => $lagoon->id]);
        $this->review($this->traveler('Eva Go'), ['body' => 'This one is about Boracay instead.', 'destination' => 'Boracay']);

        $this->actingAs($this->traveler())->get(route('destinations.show', $siargao))
            ->assertOk()
            ->assertSee('Ben Reyes')
            ->assertSee('Cloud 9 at sunrise is unforgettable.')
            ->assertDontSee('Hidden from everyone.')
            ->assertDontSee('About the lagoon, not the island.')   // lives on the lagoon's page
            ->assertDontSee('This one is about Boracay instead.')
            ->assertSee('Write a review');
    }

    public function test_an_empty_destination_invites_the_first_review(): void
    {
        $this->actingAs($this->traveler())->get(route('destinations.show', $this->siargao()))
            ->assertSee('No reviews of Siargao yet. Be the first to share your experience!')
            ->assertSee('name="destination_id" value="', false);
    }

    public function test_a_traveler_shares_a_review_of_a_destination(): void
    {
        $siargao = $this->siargao();
        $me      = $this->traveler();

        $this->actingAs($me)->post(route('reviews.store'), [
            'destination_id' => $siargao->id,
            'destination'    => 'Somewhere else entirely',   // ignored: the id names the place
            'rating'         => 4,
            'body'           => 'Laid-back and beautiful, rent a motorbike.',
        ])
            ->assertRedirect(route('destinations.show', $siargao) . '#reviews')
            ->assertSessionHas('toast', 'Review submitted!');

        $review = Review::sole();
        $this->assertSame('Siargao', $review->destination);
        $this->assertNull($review->attraction_id);
        $this->assertSame([4, 'Laid-back and beautiful, rent a motorbike.'], [$review->rating, $review->body]);

        $this->actingAs($me)->get(route('destinations.show', $siargao))
            ->assertSee('Laid-back and beautiful, rent a motorbike.')
            ->assertSee('Edit your review');
    }

    public function test_reviews_are_stars_and_words_only(): void
    {
        // Trip type, number of travelers and total spent were taken back out
        // of the form and the review rows.
        $siargao = $this->siargao();
        $this->review($this->traveler('Ben Reyes'), ['trip_type' => 'Barkada', 'pax_count' => 5, 'spent_amount' => 42000]);

        $html = $this->actingAs($this->traveler())->get(route('destinations.show', $siargao))->getContent();

        foreach (['name="trip_type"', 'name="pax_count"', 'name="spent_amount"', 'Your Trip',
                  'atdReviewFilter', 'Trip type', 'Barkada', '5 travelers', 'Spent'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
    }

    public function test_trip_details_sent_anyway_are_not_saved(): void
    {
        $siargao = $this->siargao();

        $this->actingAs($this->traveler())->post(route('reviews.store'), [
            'destination_id' => $siargao->id, 'rating' => 5, 'body' => 'Worth every minute of the trip.',
            'trip_type' => 'Solo', 'pax_count' => 1, 'spent_amount' => 999,
        ]);

        $review = Review::sole();
        $this->assertSame([null, null, null], [$review->trip_type, $review->pax_count, $review->spent_amount]);
    }

    public function test_editing_keeps_trip_details_saved_before(): void
    {
        $siargao = $this->siargao();
        $me      = $this->traveler();
        $mine    = $this->review($me, ['trip_type' => 'Couple', 'pax_count' => 2, 'spent_amount' => 18500]);

        $this->actingAs($me)->put(route('reviews.update', $mine), [
            'destination_id' => $siargao->id, 'rating' => 3, 'body' => 'Changed my mind after a week here.',
        ]);

        $mine->refresh();
        $this->assertSame('Changed my mind after a week here.', $mine->body);
        $this->assertSame(['Couple', 2, '18500.00'], [$mine->trip_type, $mine->pax_count, $mine->spent_amount]);
    }

    public function test_a_second_review_of_the_same_place_edits_the_first(): void
    {
        $siargao = $this->siargao();
        $me      = $this->traveler();
        $post    = fn (string $body) => $this->actingAs($me)->post(route('reviews.store'), [
            'destination_id' => $siargao->id, 'rating' => 3, 'body' => $body,
        ]);

        $post('First impressions of the island.');
        $post('Changed my mind after a week here.')->assertSessionHas('toast', 'Review updated!');

        $this->assertSame(1, Review::count());
        $this->assertSame('Changed my mind after a week here.', Review::sole()->body);
    }

    public function test_editing_and_deleting_go_back_to_the_destination(): void
    {
        $siargao = $this->siargao();
        $me      = $this->traveler();
        $mine    = $this->review($me);

        $this->actingAs($me)->put(route('reviews.update', $mine), [
            'destination_id' => $siargao->id, 'rating' => 2, 'body' => 'Too crowded in peak season.',
        ])->assertRedirect(route('destinations.show', $siargao) . '#reviews');

        $this->assertSame('Too crowded in peak season.', $mine->fresh()->body);

        $this->actingAs($me)->delete(route('reviews.destroy', $mine), ['destination_id' => $siargao->id])
            ->assertRedirect(route('destinations.show', $siargao) . '#reviews')
            ->assertSessionHas('toast', 'Review deleted.');
        $this->assertSame(0, Review::count());
    }

    public function test_attraction_cards_show_what_travelers_rated_them(): void
    {
        $siargao = $this->siargao();
        $lagoon  = Attraction::create(['name' => 'Sugba Lagoon', 'destination' => 'Siargao', 'region' => 'local', 'category' => 'Nature', 'rating' => 4.7]);
        $this->review($this->traveler('Ben Reyes'), ['attraction_id' => $lagoon->id, 'rating' => 5]);
        $this->review($this->traveler('Cara Lim'), ['attraction_id' => $lagoon->id, 'rating' => 4]);

        $this->actingAs($this->traveler())->get(route('destinations.show', $siargao))
            ->assertSeeInOrder(['Sugba Lagoon', '4.5', '(2)']);
    }

    public function test_the_attraction_page_shares_the_same_reviews_section(): void
    {
        $lagoon = Attraction::create(['name' => 'Sugba Lagoon', 'destination' => 'Siargao', 'region' => 'local', 'category' => 'Nature', 'rating' => 4.7]);
        $this->review($this->traveler('Ben Reyes'), ['attraction_id' => $lagoon->id, 'body' => 'Go early before the tour boats.']);

        $this->actingAs($this->traveler())->get(route('attractions.show', $lagoon))
            ->assertSee('Go early before the tour boats.')
            ->assertSee('name="attraction_id" value="' . $lagoon->id . '"', false)
            ->assertDontSee('Your Trip');
    }
}
