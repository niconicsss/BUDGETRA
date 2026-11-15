<?php
namespace App\Livewire\Traveler;

use App\Models\GroupMember;
use App\Models\Notification;
use App\Models\Trip;
use App\Models\User;
use App\Services\CurrencyConverterService;
use App\Services\TripImportService;
use App\Support\PlaceCatalog;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app', ['active' => 'saved-trips'])]
class SavedTrips extends Component
{
    public ?int $detailTripId   = null;
    public ?int $deleteTripId   = null;
    public string $deleteTripName = '';
    // True when the person deleting is a member rather than the owner: for
    // them "Delete Trip" takes them off the trip and leaves it standing for
    // everyone else. Drives the confirmation wording only — deleteTrip()
    // decides from the database, not from this.
    #[Locked]
    public bool $deleteIsLeave = false;
    public ?int $editNameTripId  = null;
    public string $editNameValue = '';
    public string $editType      = 'Solo';
    public string $editStatus    = 'upcoming';
    public string $memberEmail   = '';
    public string $memberError   = '';
    public array  $pendingMembers = [];
    public array  $savedMembers   = [];
    public array  $tripOwner      = [];
    // Members can rename the trip and change its status, nothing else — no
    // Type switch, no adding or removing people. UI flag only; every action
    // re-checks ownership itself.
    #[Locked]
    public bool $editIsOwner = true;
    public ?int $shareTripId    = null;
    public string $shareCode    = '';
    public string $shareLink    = '';
    public bool $shareNotAvailable = false;

    public function showDetail(int $id): void
    {
        $this->detailTripId = $this->detailTripId === $id ? null : $id;
    }

    public function closeDetail(): void
    {
        $this->detailTripId = null;
    }

    public function confirmDelete(int $id): void
    {
        $trip = Trip::find($id);
        if (!$this->canEditTrip($trip)) return;
        $this->deleteTripId   = $id;
        $this->deleteTripName = $trip->trip_name ?? $trip->destination ?? 'this trip';
        $this->deleteIsLeave  = !$this->ownsTrip($trip);
    }

    public function openEditName(int $id): void
    {
        $trip = Trip::find($id);
        if (!$trip) return;
        // The owner and every member of the trip can edit it.
        if (!$this->canEditTrip($trip)) return;
        $this->editNameTripId = $id;
        $this->editIsOwner    = $this->ownsTrip($trip);
        $this->editNameValue = $trip->trip_name ?? $trip->destination ?? '';
        $hasMembers          = GroupMember::where('trip_id', $id)->exists();
        // A trip with members is a group trip whatever travel_type says —
        // covers trips joined by share code before travel_type was updated.
        $this->editType      = ($hasMembers || strcasecmp($trip->travel_type ?? 'Solo', 'Solo') !== 0) ? 'Group' : 'Solo';
        $owner               = $trip->user;
        $this->tripOwner     = ['name' => $owner->full_name ?? '', 'email' => $owner->email ?? '', 'photo' => $this->photoUrl($owner)];
        $today = \Carbon\Carbon::today();
        $computed = $trip->start_date->gt($today) ? 'upcoming' : ($trip->end_date->lt($today) ? 'past' : 'active');
        $this->editStatus     = $trip->getRawOriginal('status') ?? $computed;
        $this->memberEmail    = '';
        $this->memberError    = '';
        $this->pendingMembers = [];
        $this->savedMembers   = GroupMember::where('trip_id', $id)
            ->with('user')
            ->get()
            ->map(fn($m) => ['id' => $m->user_id, 'name' => $m->user->full_name, 'email' => $m->user->email, 'photo' => $this->photoUrl($m->user)])
            ->toArray();
    }

    // The account's uploaded profile photo, or null to fall back to the initial.
    private function photoUrl(?User $user): ?string
    {
        return $user?->profile_photo ? \Illuminate\Support\Facades\Storage::url($user->profile_photo) : null;
    }

    /**
     * A group trip whose last member has just left or been removed is the
     * owner's alone again, so it goes back to Solo with one head — the same
     * reset as switching it to Solo in Edit Trip. Otherwise the owner's card
     * keeps saying GROUP and the bill keeps splitting between people who are
     * no longer on it.
     *
     * Only called when a member actually leaves. A Group trip from the
     * planner that never had app members is left alone: its num_travelers is
     * a real head count, not leftovers. Returns whether it reverted.
     */
    private function revertToSoloIfAlone(Trip $trip): bool
    {
        if (GroupMember::where('trip_id', $trip->id)->exists()) return false;

        $trip->update(['travel_type' => 'Solo', 'num_travelers' => 1]);
        return true;
    }

    private function ownsTrip(?Trip $trip): bool
    {
        return $trip !== null && $trip->user_id === auth()->id();
    }

    private function canEditTrip(?Trip $trip): bool
    {
        if (!$trip) return false;
        return $trip->user_id === auth()->id()
            || GroupMember::where('trip_id', $trip->id)->where('user_id', auth()->id())->exists();
    }

    public function lookupMember(): void
    {
        $this->memberError = '';
        // Only the owner adds people.
        if (!$this->editNameTripId || !$this->ownsTrip(Trip::find($this->editNameTripId))) return;
        $email = trim($this->memberEmail);
        if ($email === '') return;

        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->memberError = 'No registered user found with that email.';
            return;
        }
        if ($user->id === auth()->id()) {
            $this->memberError = 'You are already the trip owner.';
            return;
        }
        $alreadyInSaved   = collect($this->savedMembers)->pluck('id')->contains($user->id);
        $alreadyInPending = collect($this->pendingMembers)->pluck('id')->contains($user->id);
        if ($alreadyInSaved || $alreadyInPending) {
            $this->memberError = 'This user is already added.';
            return;
        }
        $this->pendingMembers[] = ['id' => $user->id, 'name' => $user->full_name, 'email' => $user->email, 'photo' => $this->photoUrl($user)];
        $this->memberEmail = '';
    }

    public function removePendingMember(int $index): void
    {
        array_splice($this->pendingMembers, $index, 1);
    }

    public function removeSavedMember(int $userId): void
    {
        // Only the owner removes people; a member leaves through Delete Trip.
        if (!$this->editNameTripId || !$this->ownsTrip(Trip::find($this->editNameTripId))) return;
        $trip = Trip::find($this->editNameTripId);
        GroupMember::where('trip_id', $trip->id)->where('user_id', $userId)->delete();
        $this->savedMembers = array_values(array_filter($this->savedMembers, fn($m) => $m['id'] !== $userId));

        // That was the last member: the trip is back to Solo now, whether or
        // not the modal is saved. The Type switch follows (it is entangled)
        // unless someone new is already queued to be added.
        if ($this->revertToSoloIfAlone($trip) && empty($this->pendingMembers)) {
            $this->editType = 'Solo';
        }
    }

    public function saveEditName(): void
    {
        if (!$this->editNameTripId || trim($this->editNameValue) === '') return;
        $trip = Trip::find($this->editNameTripId);
        if ($trip && $this->canEditTrip($trip) && !$this->ownsTrip($trip)) {
            // A member: name and status only. Type and the member list are
            // the owner's to change, whatever the request carried.
            $trip->update([
                'trip_name' => trim($this->editNameValue),
                'status'    => $this->editStatus,
            ]);
            $trip->savingsGoals()->update(['goal_name' => trim($this->editNameValue) ?: $trip->destination]);
        } elseif ($trip && $this->ownsTrip($trip)) {
            $trip->update([
                'trip_name'   => trim($this->editNameValue),
                'travel_type' => $this->editType,
                'status'      => $this->editStatus,
            ]);
            $newName = trim($this->editNameValue) ?: $trip->destination;
            $trip->savingsGoals()->update(['goal_name' => $newName]);

            // Back to Solo means back to one traveller: drop the members and
            // reset the head count, otherwise the trip keeps splitting the
            // bill N ways and stays in those members' Saved Trips.
            if ($this->editType === 'Solo') {
                GroupMember::where('trip_id', $trip->id)->delete();
                $trip->update(['num_travelers' => 1]);
            }

            if ($this->editType === 'Group') {
                foreach ($this->pendingMembers as $m) {
                    $link = GroupMember::firstOrCreate(['trip_id' => $trip->id, 'user_id' => $m['id']]);
                    // Only on a genuinely new link — firstOrCreate returns the
                    // existing row when the member was already on the trip, and
                    // re-saving the modal shouldn't re-notify them.
                    if ($link->wasRecentlyCreated) {
                        $this->notifyAddedToTrip($trip, $m['id']);
                    }
                }
            }
        }
        $this->editNameTripId  = null;
        $this->editNameValue   = '';
        $this->editType        = 'Solo';
        $this->editStatus      = 'upcoming';
        $this->memberEmail     = '';
        $this->memberError     = '';
        $this->pendingMembers  = [];
        $this->savedMembers    = [];
        $this->tripOwner       = [];
        $this->editIsOwner     = true;
    }

    public function cancelEditName(): void
    {
        $this->editNameTripId  = null;
        $this->editNameValue   = '';
        $this->editType        = 'Solo';
        $this->editStatus      = 'upcoming';
        $this->memberEmail     = '';
        $this->memberError     = '';
        $this->pendingMembers  = [];
        $this->savedMembers    = [];
        $this->tripOwner       = [];
        $this->editIsOwner     = true;
    }

    public function cancelDelete(): void
    {
        $this->deleteTripId   = null;
        $this->deleteTripName = '';
        $this->deleteIsLeave  = false;
    }

    public function openShare(int $id): void
    {
        $trip = Trip::find($id);
        if (!$trip) return;

        // One trip, one code. The owner and every member see the same code,
        // so whoever passes it on, the next person joins this same trip.
        $isOwner  = $trip->user_id === auth()->id();
        $isMember = GroupMember::where('trip_id', $trip->id)->where('user_id', auth()->id())->exists();
        if (!$isOwner && !$isMember) return;

        $importer = app(TripImportService::class);
        if (!$importer->isShareable($trip)) {
            $this->shareTripId       = $id;
            $this->shareNotAvailable = true;
            $this->shareCode         = '';
            $this->shareLink         = '';
            return;
        }

        $code = $importer->shareCodeFor($trip);
        $this->shareTripId       = $id;
        $this->shareCode         = $code;
        $this->shareLink         = route('trips.import', $code);
        $this->shareNotAvailable = false;
    }

    public function closeShareModal(): void
    {
        $this->shareTripId       = null;
        $this->shareCode         = '';
        $this->shareLink         = '';
        $this->shareNotAvailable = false;
    }

    public function deleteTrip(): void
    {
        if (!$this->deleteTripId) return;
        $trip = Trip::find($this->deleteTripId);
        if ($this->ownsTrip($trip)) {
            $trip->delete();
        } elseif ($trip) {
            // A member's "Delete Trip" removes only them. The trip, and
            // everyone else on it, is untouched; it simply drops out of
            // this traveller's Saved Trips.
            GroupMember::where('trip_id', $trip->id)->where('user_id', auth()->id())->delete();
            $this->revertToSoloIfAlone($trip);
        }
        if ($this->detailTripId === $this->deleteTripId) {
            $this->detailTripId = null;
        }
        $this->deleteTripId   = null;
        $this->deleteTripName = '';
        $this->deleteIsLeave  = false;
    }

    /**
     * Tells a traveller they were added to someone else's trip. The trip then
     * shows up in their own Saved Trips, so the notification links to it.
     */
    private function notifyAddedToTrip(Trip $trip, int $memberId): void
    {
        $inviter = auth()->user()->full_name ?: 'A fellow traveler';
        $where   = $trip->trip_name ?: $trip->destination;

        Notification::create([
            'user_id' => $memberId,
            'trip_id' => $trip->id,
            'type'    => 'trip_shared',
            'message' => "{$inviter} added you to their trip to {$where}. It's now in your Saved Trips.",
            'is_read' => false,
        ]);
    }

    // Peso amount, formatted for display — see trip_amount() in app/helpers.php.
    // Shared with the dashboard's trip stubs, which show the same figures and
    // have to agree with these cards.
    public function displayAmount(Trip $trip, float $pesoAmount): string
    {
        return trip_amount($trip, $pesoAmount);
    }

    private function fetchTrips()
    {
        $uid = auth()->id();

        // Trips the traveller owns, plus any they were added to as a group
        // member. A shared trip is only surfaced once it is a real plan —
        // someone else's unfinished draft is not something a member should
        // see in their list.
        return Trip::where(function ($q) use ($uid) {
                $q->where('user_id', $uid)
                  ->orWhere(fn ($m) => $m
                      ->whereHas('groupMembers', fn ($g) => $g->where('user_id', $uid))
                      ->where(fn ($d) => $d->whereNull('status')->orWhere('status', '!=', 'draft')));
            })
            // Searching happens in the browser so it lands on the keystroke
            // rather than 500ms later; trip_search_haystack() carries the same
            // three fields this clause used to match on.
            ->withSum('expenses', 'amount')
            ->withCount('groupMembers')
            ->with('user:id,full_name')
            ->latest('created_at')
            ->get()
            ->map(function (Trip $trip) {
                $days  = max(1, (int) $trip->start_date->diffInDays($trip->end_date));
                $trip->setAttribute('days', $days);
                // A stored status wins — the traveller can set one from this
                // page's edit form — and the dates decide only when there
                // isn't one. Trip::resolvedStatus is that rule; the dashboard
                // reads the same accessor so the two lists can't disagree.
                $trip->setAttribute('status', $trip->resolved_status);

                // Real spending from logged Expenses, vs. the planned budget —
                // lets the card show actual money tracking, not just the estimate.
                $actualSpent = (float) ($trip->expenses_sum_amount ?? 0);
                $budget      = (float) ($trip->total_cost ?? $trip->budget_limit ?? 0);
                $trip->setAttribute('actual_spent', $actualSpent);
                $trip->setAttribute('spend_pct', $budget > 0 ? min(100, round($actualSpent / $budget * 100)) : 0);

                // Split-bill figures. Head count is the trip's own
                // num_travelers, floored at 1 so a malformed row can't divide
                // by zero and at the real member count when more people were
                // added than the planner originally allowed for.
                // Solo trips are always one head no matter what num_travelers
                // happens to hold — a trip switched Group→Solo can be left
                // carrying the old count.
                $isGroup = strcasecmp($trip->travel_type ?? 'Solo', 'Group') === 0
                    || $trip->group_members_count > 0;
                $heads   = $isGroup
                    ? max(1, (int) $trip->num_travelers, $trip->group_members_count + 1)
                    : 1;
                $trip->setAttribute('head_count', $heads);
                $trip->setAttribute('cost_per_person',  $budget      / $heads);
                $trip->setAttribute('spent_per_person', $actualSpent / $heads);

                $trip->setAttribute('shared_with_me', $trip->user_id !== auth()->id());

                // Destination currency for Upcoming/Ongoing trips when a live
                // rate is reachable, pesos otherwise — see helpers.php.
                trip_apply_display_currency($trip);

                return $trip;
            })
            // Two trips that share the same start date most often means one
            // is a single-leg duplicate of a multi-city trip's own leg (e.g.
            // a separate "Davao City" card alongside "Cebu City & Davao
            // City" that both start Aug 24) — put the multi-city trip first
            // in that case instead of leaving it to arrival order, whether
            // the flights involved were one-way or round-trip.
            ->sort(function (Trip $a, Trip $b) {
                if (!$a->start_date->eq($b->start_date)) return 0;
                return ($b->is_multi_city <=> $a->is_multi_city);
            })
            ->values();
    }

    public function render()
    {
        $trips = $this->fetchTrips();
        $detailTrip = $this->detailTripId
            ? $trips->firstWhere('id', $this->detailTripId)
            : null;
        return view('livewire.traveler.saved-trips', compact('trips', 'detailTrip'));
    }
}
