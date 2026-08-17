<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One target evaluation: a member, a period, and the target they were measured
 * against.
 *
 * A row exists for every member MEASURED in that period. Members who have
 * already achieved the target get no further rows — they have permanently moved
 * on to the next target (client-confirmed 2026-08-17, docs/02_BUSINESS_RULES.md
 * §3.1 rule 5).
 *
 * Note on `team_calculations.target_sqft / achieved / reward_amount`: those
 * columns were reserved in Phase 7 for this engine. They are deliberately left
 * NULL. Writing the same verdict in two tables invites drift, and this table
 * owns it — it has the run, the frozen rate and the once-ever guard, none of
 * which the reserved columns could carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('target_calculations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->string('period', 7);

            // Which target this evaluation was against. Phase 8 only ever writes
            // 1; Phases 9 and 10 write 2 and 3.
            $table->unsignedTinyInteger('target_level');

            // The threshold and rate FROZEN at calculation time, so a historical
            // verdict stays reproducible after an admin edits the target settings
            // (Phases 9-10 make these admin-configurable).
            $table->decimal('target_sqft', 12, 2);
            $table->decimal('rate', 10, 2);

            // What the member actually did. `achieved_sqft` is the team figure
            // being tested (own + all connected downline, any depth).
            $table->decimal('achieved_sqft', 12, 2);

            // The member's OWN sales alone, carried here so the reports can show
            // how much of the team figure the member sold personally without
            // re-querying sales per row.
            $table->decimal('own_sqft', 12, 2)->default(0);

            $table->boolean('achieved');

            // How far short they fell. Zero when achieved — never negative, the
            // surplus above the threshold is discarded and is not recorded as a
            // negative shortfall.
            $table->decimal('shortfall_sqft', 12, 2)->default(0);

            /*
             * reward = TARGET Sq.Ft. x rate, never achieved Sq.Ft.
             * A team doing 7,000 against Target 1 is paid 5,000 x 30 = 150,000.
             * Zero on a miss — a missed target pays nothing but is still recorded,
             * because the member retries next month.
             */
            $table->decimal('reward_amount', 15, 2)->default(0);

            /*
             * The once-ever guard, enforced by the database rather than by
             * application logic alone.
             *
             * Holds `target_level` when the target was achieved and NULL when it
             * was not. MySQL/MariaDB unique indexes permit unlimited NULLs, so
             * every miss coexists freely, but the unique index below makes a
             * SECOND achievement of the same target by the same member physically
             * impossible — whatever order periods are calculated in.
             */
            $table->unsignedTinyInteger('achieved_level')->nullable();

            $table->foreignId('calculation_run_id')->constrained('calculation_runs')->restrictOnDelete();

            $table->timestamps();

            // One evaluation per member per period.
            $table->unique(['member_id', 'period'], 'target_calc_member_period_unique');

            // A target pays a member once in their lifetime. See above.
            $table->unique(['member_id', 'achieved_level'], 'target_calc_once_ever_unique');

            // The two report pages filter on exactly this.
            $table->index(['period', 'achieved']);
            $table->index(['period', 'achieved_sqft']);
            $table->index('calculation_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_calculations');
    }
};
