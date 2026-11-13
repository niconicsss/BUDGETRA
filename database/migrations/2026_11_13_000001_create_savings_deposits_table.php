<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per "Add Savings" — the history a group trip's members see.
        Schema::create('savings_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('savings_goal_id')->constrained('savings_goals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2); // pesos
            $table->timestamps();
        });

        // Savings made before this table existed only survive as a running
        // total — record it as one entry so the history still adds up.
        foreach (DB::table('savings_goals')->where('current_savings', '>', 0)->get() as $g) {
            DB::table('savings_deposits')->insert([
                'savings_goal_id' => $g->id,
                'user_id'         => $g->user_id,
                'amount'          => $g->current_savings,
                'created_at'      => $g->updated_at,
                'updated_at'      => $g->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_deposits');
    }
};
