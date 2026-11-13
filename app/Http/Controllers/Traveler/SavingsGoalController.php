<?php
namespace App\Http\Controllers\Traveler;

use App\Http\Controllers\Controller;
use App\Models\SavingsGoal;
use App\Models\Trip;
use Illuminate\Http\Request;

class SavingsGoalController extends Controller
{
    public function index()
    {
        // A goal per trip the traveller can see — theirs, and any they were
        // added to. Scoped by user_id as well as trip_id: on a group trip each
        // member saves toward their own share, so the owner's goal is not the
        // member's goal and "already has a goal" has to be asked per person.
        // Only the trip owner has a savings goal. Members of a group trip save
        // into the owner's goal, so they never get one of their own.
        $tripsWithoutGoals = Trip::where('user_id', auth()->id())
            ->whereDoesntHave('savingsGoals', fn ($q) => $q->where('user_id', auth()->id()))
            ->get();

        foreach ($tripsWithoutGoals as $trip) {
            $total   = (float) ($trip->total_cost ?: $trip->budget_limit ?: 0);

            SavingsGoal::create([
                'user_id'         => auth()->id(),
                'trip_id'         => $trip->id,
                'goal_name'       => $trip->destination . ' Trip',
                'target_amount'   => max(1, round($total, 2)),
                'current_savings' => 0,
                'deadline'        => $trip->start_date,
            ]);
        }

        // One card per trip: the traveller's own goal. On a group trip that
        // card totals every member's savings and its History lists who added
        // what (SavingsGoalManager), so other members' goals aren't listed.
        // The owner's goal for every trip the traveller is on — their own
        // trips, and the trips they joined as a member.
        $goals = SavingsGoal::with('trip')
            ->whereIn('trip_id', auth()->user()->accessibleTrips()->select('id'))
            ->whereHas('trip', fn ($t) => $t->whereColumn('trips.user_id', 'savings_goals.user_id'))
            ->latest()->get()
            // Two goals whose trips share the same start date usually means
            // one is a single-leg duplicate of a multi-city trip's own leg
            // — put the multi-city trip's goal first in that case, whether
            // its flights were one-way or round-trip, instead of leaving it
            // to arrival order.
            ->sort(function (SavingsGoal $a, SavingsGoal $b) {
                if (!$a->trip || !$b->trip || !$a->trip->start_date->eq($b->trip->start_date)) return 0;
                return ((bool) $b->trip->is_multi_city <=> (bool) $a->trip->is_multi_city);
            })
            ->values();
        return view('traveler.savings.index', compact('goals'));
    }

    public function create()
    {
        $trips = auth()->user()->accessibleTrips()->orderBy('destination')->get();
        return view('traveler.savings.create', compact('trips'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'goal_name'       => 'required|string|max:255',
            'target_amount'   => 'required|numeric|min:1',
            'current_savings' => 'nullable|numeric|min:0',
            'deadline'        => 'required|date|after:today',
            'trip_id'         => 'nullable|exists:trips,id',
        ]);

        if ($request->filled('trip_id')) {
            $trip = Trip::find($request->trip_id);
            abort_if($trip->user_id !== auth()->id(), 403);
        }

        auth()->user()->savingsGoals()->create([
            ...$validated,
            'current_savings' => $validated['current_savings'] ?? 0,
        ]);

        return redirect()->route('savings.index')->with('success', 'Savings goal created!');
    }

    public function edit(SavingsGoal $goal)
    {
        abort_if($goal->user_id !== auth()->id(), 403);
        $trips = auth()->user()->accessibleTrips()->orderBy('destination')->get();
        return view('traveler.savings.edit', compact('goal', 'trips'));
    }

    public function update(Request $request, SavingsGoal $goal)
    {
        abort_if($goal->user_id !== auth()->id(), 403);
        $validated = $request->validate([
            'goal_name'       => 'required|string|max:255',
            'target_amount'   => 'required|numeric|min:1',
            'current_savings' => 'nullable|numeric|min:0',
            'deadline'        => 'required|date',
            'trip_id'         => 'nullable|exists:trips,id',
        ]);
        $goal->update([...$validated, 'current_savings' => $validated['current_savings'] ?? $goal->current_savings]);
        return redirect()->route('savings.index')->with('success', 'Goal updated.');
    }

    public function destroy(SavingsGoal $goal)
    {
        abort_if($goal->user_id !== auth()->id(), 403);
        $goal->delete();
        return redirect()->route('savings.index')->with('success', 'Goal deleted.');
    }
}
