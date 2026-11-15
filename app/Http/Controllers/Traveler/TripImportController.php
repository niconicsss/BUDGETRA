<?php
namespace App\Http\Controllers\Traveler;

use App\Http\Controllers\Controller;
use App\Services\TripImportService;

class TripImportController extends Controller
{
    public function import(string $code, TripImportService $importer)
    {
        $sourceTrip = $importer->findByCode($code);

        if (!$sourceTrip) {
            return redirect()->route('saved-trips')->with('error', 'That share code or link is no longer valid.');
        }
        if ($sourceTrip->user_id === auth()->id()) {
            // Still blocked — just no error banner for it.
            return redirect()->route('saved-trips');
        }
        if (!$importer->isShareable($sourceTrip)) {
            return redirect()->route('saved-trips')->with('error', 'This trip has nothing shareable saved on it.');
        }

        $joined = $importer->joinTrip($sourceTrip, auth()->user());

        // Same split as the share-code box in the planner: a new trip gets the
        // floating pill, an existing membership the inline note.
        return $joined
            ? redirect()->route('saved-trips')->with('toast', 'Trip added successfully!')
            : redirect()->route('saved-trips')->with('success', "You're already a member of this trip.");
    }
}
