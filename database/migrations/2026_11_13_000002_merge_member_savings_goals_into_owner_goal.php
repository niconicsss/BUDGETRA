<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Only a trip's owner has a savings goal now. Goals that group members
     * set up on someone else's trip are folded into the owner's goal: their
     * savings are added to it, and their deposits move over still credited
     * to the member, so History keeps showing who put in what.
     */
    public function up(): void
    {
        DB::transaction(function () {
            $memberGoals = DB::table('savings_goals as g')
                ->join('trips as t', 't.id', '=', 'g.trip_id')
                ->whereColumn('g.user_id', '!=', 't.user_id')
                ->select('g.*', 't.user_id as owner_id', 't.total_cost', 't.budget_limit', 't.start_date', 't.destination')
                ->get();

            foreach ($memberGoals as $mg) {
                $owner = DB::table('savings_goals')
                    ->where('trip_id', $mg->trip_id)->where('user_id', $mg->owner_id)->first();

                if (!$owner) {
                    // No owner goal yet — this one becomes it.
                    DB::table('savings_goals')->where('id', $mg->id)->update([
                        'user_id'       => $mg->owner_id,
                        'target_amount' => max(1, (float) ($mg->total_cost ?: $mg->budget_limit ?: $mg->target_amount)),
                    ]);
                    continue;
                }

                DB::table('savings_goals')->where('id', $owner->id)
                    ->increment('current_savings', (float) $mg->current_savings);
                DB::table('savings_deposits')->where('savings_goal_id', $mg->id)
                    ->update(['savings_goal_id' => $owner->id]);
                DB::table('savings_goals')->where('id', $mg->id)->delete();
            }
        });
    }

    public function down(): void
    {
        // Merged goals can't be split back apart.
    }
};
