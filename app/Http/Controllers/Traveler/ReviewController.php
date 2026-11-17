<?php
namespace App\Http\Controllers\Traveler;

use App\Http\Controllers\Controller;
use App\Models\Attraction;
use App\Models\Destination;
use App\Models\DestinationCost;
use App\Models\Review;
use App\Models\ReviewHelpfulVote;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $query  = Review::with('user')->where('status', 'active')->latest();

        if ($request->filled('destination')) {
            // Reviews are written against DestinationCost's fixed, curated
            // names ("Boracay"), but this page is also linked to from
            // Moments, where a traveler can type ANY free-text place name
            // ("Boracay Beach Sunset Spot") when pinning a photo. An exact
            // match would silently show nothing for every Moment whose
            // wording doesn't happen to match a DestinationCost entry
            // verbatim. Matching in both directions — does the review's
            // destination appear inside what was searched, or vice versa —
            // catches both "search is more specific than the review" and
            // "review is more specific than the search" without needing
            // the two features to agree on a shared vocabulary.
            $term = strtolower(trim($request->destination));
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(destination) LIKE ?', ["%{$term}%"])
                  ->orWhereRaw("? LIKE ('%' || LOWER(destination) || '%')", [$term]);
            });
        }

        // withQueryString() would carry "write=1" into every pagination
        // link too — since that param only means "auto-open the write
        // modal on arrival", not a real filter, keeping it would make the
        // modal pop back open every time the traveler pages through
        // results after closing it once.
        $reviews      = $query->paginate(15)->appends($request->except('write'));
        $destinations = DestinationCost::orderBy('destination')->pluck('destination')->unique()->values();
        return view('traveler.reviews.index', compact('reviews', 'destinations'));
    }

    /**
     * A review is its stars and its words. The trip type, group size and
     * amount spent the reviews table also has columns for are no longer asked
     * for or changed here, so editing a review leaves any earlier values be.
     */
    private function reviewRules(): array
    {
        return [
            'rating' => 'required|integer|min:1|max:5',
            'body'   => 'required|string|min:10|max:2000',
        ];
    }

    public function store(Request $request)
    {
        $request->validate($this->reviewRules() + [
            // Reviews written from an attraction or destination page name the
            // place by id; only the free-form reviews page types a name.
            'destination'    => 'required_without_all:attraction_id,destination_id|nullable|string|max:255',
            'attraction_id'  => 'nullable|exists:attractions,id',
            'destination_id' => 'nullable|exists:destinations,id',
        ]);

        [$destinationName, $attractionId] = $this->place($request);

        $data = [
            'destination'   => $destinationName,
            'attraction_id' => $attractionId,
            'rating'        => (int) $request->rating,
            'body'          => $request->body,
            'status'        => 'active',
        ];

        // One review per traveler per place, on the attraction and destination
        // pages: a second submit (another tab, a double-click) edits the first
        // instead of stacking a duplicate under the same name.
        $existing = $this->placeReviewOf($request, $destinationName, $attractionId);
        if ($existing) {
            $existing->update($data);
            return $this->backToPlace($request, 'Review updated!');
        }

        auth()->user()->reviews()->create($data);

        return $this->backToPlace($request, 'Review submitted!');
    }

    public function update(Request $request, Review $review)
    {
        abort_if($review->user_id !== auth()->id(), 403, "You can only edit your own review.");

        $request->validate($this->reviewRules());

        $review->update([
            'rating' => (int) $request->rating,
            'body'   => $request->body,
        ]);

        return $this->backToPlace($request, 'Review updated!');
    }

    public function destroy(Request $request, Review $review)
    {
        abort_if($review->user_id !== auth()->id(), 403, "You can only delete your own review.");

        $review->delete();

        return $this->backToPlace($request, 'Review deleted.');
    }

    /**
     * Which place a new review is about: [destination name, attraction id].
     *
     * Taken from the database record when the form names one by id, not from
     * a hidden "destination" field the browser could have changed.
     */
    private function place(Request $request): array
    {
        if ($request->filled('attraction_id')) {
            $attraction = Attraction::findOrFail($request->attraction_id);
            return [$attraction->destination, $attraction->id];
        }

        if ($request->filled('destination_id')) {
            return [Destination::findOrFail($request->destination_id)->name, null];
        }

        return [trim($request->destination), null];
    }

    private function placeReviewOf(Request $request, string $destinationName, ?int $attractionId): ?Review
    {
        if ($attractionId) {
            return auth()->user()->reviews()->where('attraction_id', $attractionId)->first();
        }

        if ($request->filled('destination_id')) {
            return auth()->user()->reviews()
                ->whereNull('attraction_id')
                ->whereRaw('LOWER(destination) = ?', [mb_strtolower($destinationName)])
                ->first();
        }

        return null;
    }

    /**
     * Back to the reviews on the page the review was written from, with the
     * floating confirmation pill (<x-toast>). The free-form reviews page keeps
     * its inline message.
     */
    private function backToPlace(Request $request, string $message)
    {
        if ($request->filled('attraction_id')) {
            return redirect()->to(route('attractions.show', $request->attraction_id) . '#reviews')->with('toast', $message);
        }

        if ($request->filled('destination_id')) {
            return redirect()->to(route('destinations.show', $request->destination_id) . '#reviews')->with('toast', $message);
        }

        return redirect()->route('reviews.index')->with('success', $message);
    }

    public function flag(Request $request, Review $review)
    {
        abort_if($review->user_id === auth()->id(), 403, "You can't flag your own review.");

        $validated = $request->validate([
            'reason' => 'required|in:inappropriate,improvement',
        ]);

        if ($review->flagged_by === auth()->id()) {
            // The pill (<x-toast>), which every page shows; the review pages
            // never rendered a 'success' message, so reporting looked dead.
            return back()->with('toast', "You've already reported this review.");
        }

        $review->update([
            'flag_reason' => $validated['reason'],
            'flagged_at'  => now(),
            'flagged_by'  => auth()->id(),
        ]);

        return back()->with('toast', 'Thanks — an admin will take a look.');
    }

    // One vote per traveler per review — firstOrCreate on the unique
    // (review_id, user_id) pair silently no-ops on a repeat click instead
    // of inflating the count, so helpful_count always matches the real
    // number of distinct voters.
    public function markHelpful(Review $review)
    {
        $vote = ReviewHelpfulVote::firstOrCreate([
            'review_id' => $review->id,
            'user_id'   => auth()->id(),
        ]);

        if ($vote->wasRecentlyCreated) {
            $review->increment('helpful_count');
        }

        return back();
    }
}
