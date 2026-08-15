<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team sales rollup, one row per leader per period.
 *
 * Phase 7 fills the SALES columns only. `target_sqft`, `achieved` and
 * `reward_amount` belong to the Target engine (Phases 8-10) and stay null until
 * then — team sales are a measurement, not a reward. Nothing in this table pays
 * anybody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_calculations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('leader_id')->constrained('members')->restrictOnDelete();
            $table->string('period', 7);

            // The leader's own approved sales.
            $table->decimal('own_sqft', 12, 2)->default(0);

            // Sales of the leader's DIRECT referrals only (one level down).
            $table->decimal('direct_team_sqft', 12, 2)->default(0);

            // Own + every connected downline at any depth. This is the figure
            // the Target engine measures against.
            $table->decimal('total_team_sqft', 12, 2)->default(0);

            // How many members below this leader recorded a sale this period.
            $table->unsignedInteger('contributing_members')->default(0);

            // --- Phase 8-10 territory; deliberately empty for now ---
            $table->decimal('target_sqft', 12, 2)->nullable();
            $table->boolean('achieved')->nullable();
            $table->decimal('reward_amount', 15, 2)->nullable();

            $table->foreignId('calculation_run_id')->constrained('calculation_runs')->restrictOnDelete();

            $table->timestamps();

            // One rollup per leader per period.
            $table->unique(['leader_id', 'period'], 'team_calc_unique');

            $table->index('period');
            $table->index(['period', 'total_team_sqft']);
            $table->index('calculation_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_calculations');
    }
};
