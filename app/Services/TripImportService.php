<?php
namespace App\Services;

use App\Models\GroupMember;
use App\Models\Itinerary;
use App\Models\Notification;
use App\Models\Trip;
use App\Models\User;

class TripImportService
{
    // A trip only has selection snapshots if it was saved after the Share
    // feature shipped — older trips have nulls in these columns, so sharing
    // is disabled for them rather than importing an empty shell.
    public function isShareable(Trip $trip): bool
    {
        return $trip->flight_selection !== null
            || $trip->hotel_selection !== null
            || $trip->venue_selection !== null
            || $trip->attraction_selection !== null;
    }

    // Same code forever once generated — no revoke, per product decision.
    public function shareCodeFor(Trip $trip): string
    {
        // Re-read before generating so two people opening Share at the same
        // moment (owner and a member) can't each mint a different code.
        $current = Trip::whereKey($trip->id)->value('share_code');
        if (!$current) {
            $current = Trip::generateUniqueShareCode();
            Trip::whereKey($trip->id)->whereNull('share_code')->update(['share_code' => $current]);
            $current = Trip::whereKey($trip->id)->value('share_code');
        }
        $trip->share_code = $current;
        return $current;
    }

    public function findByCode(string $code): ?Trip
    {
        $code = strtoupper(trim($code));
        if ($code === '') return null;
        return Trip::where('share_code', $code)->first();
    }

    // Pasting a share code joins the recipient to the owner's trip as a group
    // member rather than handing them a detached copy — so the trip itself
    // becomes a Group trip, the owner sees who joined in Edit Trip, and both
    // travellers are looking at the same plan. Returns false when the
    // recipient was already a member (nothing changed).
    public function joinTrip(Trip $sourceTrip, User $recipient): bool
    {
        $link = GroupMember::firstOrCreate([
            'trip_id' => $sourceTrip->id,
            'user_id' => $recipient->id,
        ]);

        $memberCount = GroupMember::where('trip_id', $sourceTrip->id)->count();
        $sourceTrip->update([
            'travel_type'   => 'Group',
            'is_shared'     => true,
            'num_travelers' => max((int) $sourceTrip->num_travelers, $memberCount + 1),
        ]);

        if ($link->wasRecentlyCreated) {
            $who   = $recipient->full_name ?: $recipient->email;
            $where = $sourceTrip->trip_name ?: $sourceTrip->destination;
            Notification::create([
                'user_id' => $sourceTrip->user_id,
                'trip_id' => $sourceTrip->id,
                'type'    => 'trip_joined',
                'message' => "{$who} joined your trip to {$where} using your share code.",
                'is_read' => false,
            ]);
        }

        return $link->wasRecentlyCreated;
    }

    // Creates a brand-new Trip for the recipient from the source trip's
    // core details, raw selection snapshots, and its saved itinerary
    // calendar entries (the traveler's own picks plus whichever AI-suggested
    // itinerary they selected) — no emergency fund. Lands in the recipient's
    // Saved Trips exactly like a normal saved trip.
    public function import(Trip $sourceTrip, User $recipient): Trip
    {
        $summaryData = $this->buildSummaryData($sourceTrip);

        $newTrip = $recipient->trips()->create([
            'destination'       => $sourceTrip->destination,
            'trip_name'         => $sourceTrip->trip_name,
            'start_date'        => $sourceTrip->start_date,
            'end_date'          => $sourceTrip->end_date,
            'num_travelers'     => $sourceTrip->num_travelers,
            'budget_limit'      => $sourceTrip->budget_limit,
            'travel_type'       => $sourceTrip->travel_type,
            'notes'             => $sourceTrip->notes,
            'origin'            => $sourceTrip->origin,
            'origin_code'       => $sourceTrip->origin_code,
            'destination_code'  => $sourceTrip->destination_code,
            'cover_image'       => $sourceTrip->cover_image,
            'total_cost'        => $this->totalCost($sourceTrip),
            'summary_data'      => $summaryData,
            'is_multi_city'         => $sourceTrip->is_multi_city,
            'leg2_destination'      => $sourceTrip->leg2_destination,
            'leg2_destination_code' => $sourceTrip->leg2_destination_code,
            'leg2_start_date'       => $sourceTrip->leg2_start_date,
            'leg2_end_date'         => $sourceTrip->leg2_end_date,
            'flight_selection'          => $sourceTrip->flight_selection,
            'hotel_selection'           => $sourceTrip->hotel_selection,
            'venue_selection'           => $sourceTrip->venue_selection,
            'attraction_selection'      => $sourceTrip->attraction_selection,
            'leg2_flight_selection'     => $sourceTrip->leg2_flight_selection,
            'leg2_hotel_selection'      => $sourceTrip->leg2_hotel_selection,
            'leg2_venue_selection'      => $sourceTrip->leg2_venue_selection,
            'leg2_attraction_selection' => $sourceTrip->leg2_attraction_selection,
        ]);

        $sourceTrip->itinerary()->orderBy('start_datetime')->get()->each(
            fn (Itinerary $item) => $newTrip->itinerary()->create([
                'title'          => $item->title,
                'type'           => $item->type,
                'start_datetime' => $item->start_datetime,
                'end_datetime'   => $item->end_datetime,
                'location'       => $item->location,
                'notes'          => $item->notes,
            ])
        );

        return $newTrip;
    }

    private function totalCost(Trip $trip): float
    {
        $cost = 0;
        $cost += (float) ($trip->flight_selection['price'] ?? 0);
        $cost += (float) ($trip->hotel_selection['total']  ?? 0);
        foreach ($trip->venue_selection ?? [] as $v) {
            $cost += (float) ($v['priceMax'] ?? $v['priceMin'] ?? 0);
        }
        foreach ($trip->attraction_selection ?? [] as $a) {
            if (!($a['isFree'] ?? false)) {
                $cost += (float) preg_replace('/[^\d.]/', '', $a['price'] ?? '0');
            }
        }
        $cost += (float) ($trip->leg2_flight_selection['price'] ?? 0);
        $cost += (float) ($trip->leg2_hotel_selection['total']  ?? 0);
        foreach ($trip->leg2_venue_selection ?? [] as $v) {
            $cost += (float) ($v['priceMax'] ?? $v['priceMin'] ?? 0);
        }
        foreach ($trip->leg2_attraction_selection ?? [] as $a) {
            if (!($a['isFree'] ?? false)) {
                $cost += (float) preg_replace('/[^\d.]/', '', $a['price'] ?? '0');
            }
        }
        return $cost;
    }

    private function buildSummaryData(Trip $trip): array
    {
        $venueNames = array_column($trip->venue_selection ?? [], 'name');
        if ($trip->leg2_venue_selection) {
            $venueNames = array_merge($venueNames, array_column($trip->leg2_venue_selection, 'name'));
        }
        $attrNames = array_column($trip->attraction_selection ?? [], 'name');
        if ($trip->leg2_attraction_selection) {
            $attrNames = array_merge($attrNames, array_column($trip->leg2_attraction_selection, 'name'));
        }

        $flight  = $trip->flight_selection;
        $flight2 = $trip->leg2_flight_selection;
        $flightDetail = $flight ? (($flight['airline'] ?? 'Flight') . ' (' . ($flight['dep_id'] ?? '') . ' - ' . ($flight['arr_id'] ?? '') . ')') : null;
        if ($flight && $flight2) {
            $flightDetail .= ' + ' . ($flight2['airline'] ?? 'Flight') . ' (' . ($flight2['dep_id'] ?? '') . ' - ' . ($flight2['arr_id'] ?? '') . ')';
        }

        $hotel  = $trip->hotel_selection;
        $hotel2 = $trip->leg2_hotel_selection;
        $nights = (int) ($hotel['nights'] ?? 1);
        $hotelDetail = $hotel ? ($nights . ' night' . ($nights !== 1 ? 's' : '') . ' at ' . ($hotel['name'] ?? 'Hotel')) : null;
        if ($hotel && $hotel2) {
            $nights2 = (int) ($hotel2['nights'] ?? 1);
            $hotelDetail .= ' + ' . $nights2 . ' night' . ($nights2 !== 1 ? 's' : '') . ' at ' . ($hotel2['name'] ?? 'Hotel');
        }

        $flightCost = (float) ($flight['price'] ?? 0) + (float) ($flight2['price'] ?? 0);
        $hotelCost  = (float) ($hotel['total']  ?? 0) + (float) ($hotel2['total']  ?? 0);
        $venueCost  = 0;
        foreach (array_merge($trip->venue_selection ?? [], $trip->leg2_venue_selection ?? []) as $v) {
            $venueCost += (float) ($v['priceMax'] ?? $v['priceMin'] ?? 0);
        }
        $attrCost = 0;
        foreach (array_merge($trip->attraction_selection ?? [], $trip->leg2_attraction_selection ?? []) as $a) {
            if (!($a['isFree'] ?? false)) {
                $attrCost += (float) preg_replace('/[^\d.]/', '', $a['price'] ?? '0');
            }
        }

        return [
            'transportation' => ['detail' => $flightDetail, 'cost' => $flightCost],
            'accommodation'  => ['detail' => $hotelDetail,  'cost' => $hotelCost],
            'food'           => ['detail' => $venueNames ? implode(', ', $venueNames) : null, 'cost' => $venueCost],
            'attractions'    => ['detail' => $attrNames  ? implode(', ', $attrNames)  : null, 'cost' => $attrCost],
        ];
    }
}
