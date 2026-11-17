<?php
namespace App\Http\Controllers\Traveler;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\Review;

class DestinationController extends Controller
{
    public function index()
    {
        return view('traveler.destinations.index');
    }

    public function show(Destination $destination)
    {
        $attractions = $destination->attractions()->orderBy('name')->get();

        // Reviews of the destination itself — the ones written about one of
        // its attractions live on that attraction's page. Matched by name,
        // because that is how reviews.destination has always been keyed.
        $aboutHere = fn ($q) => $q->whereNull('attraction_id')
            ->whereRaw('LOWER(destination) = ?', [mb_strtolower($destination->name)]);

        $reviews = Review::with('user')
            ->where($aboutHere)
            ->where('status', 'active')
            ->latest()
            ->get();

        $avgRating = $reviews->avg('rating') ?? 0;
        $myReview  = auth()->user()->reviews()->where($aboutHere)->first();

        // Star rating on each "Things to Do" card, so the destination page
        // also says what travelers thought of what there is to do.
        $attractionRatings = Review::query()
            ->whereIn('attraction_id', $attractions->pluck('id'))
            ->where('status', 'active')
            ->groupBy('attraction_id')
            ->selectRaw('attraction_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count')
            ->get()
            ->keyBy('attraction_id');

        return view('traveler.destinations.show', compact(
            'destination', 'attractions', 'reviews', 'avgRating', 'myReview', 'attractionRatings'
        ));
    }
}
