<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Targets 2 and 3 span more than one month, so a verdict is no longer a
 * statement about a single period.
 *
 * Target 1 is 5,000 Sq.Ft. in ONE calendar month, so `period` was both the
 * measurement window and the verdict date and no more was needed. Target 2
 * (10,000 over 2 months) and Target 3 (35,000 over 3 months) accumulate across
 * the months INSIDE one window, so each row now records which window it belongs
 * to and how much had accumulated by the end of that month.
 *
 * `achieved_sqft` keeps its meaning — the team figure for THIS month alone —
 * and `cumulative_sqft` is the window-to-date total that the threshold is
 * actually tested against. For Target 1 the two are always equal.
 *
 * The third state arrives here too. Before, a row was achieved or missed. A
 * multi-month window adds IN PROGRESS: not achieved, but the window has not
 * closed yet and the member has months left. That is derived, not stored —
 * `achieved` stays the single source of the binary verdict, and a row is in
 * progress when it is not achieved and `period` has not reached `window_end`.
 * See TargetCalculation::outcome().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('target_calculations', function (Blueprint $table) {
            // The window this verdict belongs to, as YYYY-MM. For Target 1 both
            // equal `period`.
            $table->string('window_start', 7)->after('target_level');
            $table->string('window_end', 7)->after('window_start');

            // Frozen alongside target_sqft and rate, for the same reason: a
            // historical verdict must stay readable without consulting today's
            // configuration.
            $table->unsignedTinyInteger('window_months')->default(1)->after('window_end');

            // Window-to-date team Sq.Ft., which is what the threshold is tested
            // against. Equals achieved_sqft on a one-month target.
            $table->decimal('cumulative_sqft', 12, 2)->default(0)->after('achieved_sqft');

            // Both report pages filter by period AND level now that three
            // targets are live.
            $table->index(['period', 'target_level'], 'target_calc_period_level_index');
        });

        // Every existing row is Target 1, whose window is exactly its own month.
        DB::table('target_calculations')->update([
            'window_start' => DB::raw('period'),
            'window_end' => DB::raw('period'),
            'window_months' => 1,
            'cumulative_sqft' => DB::raw('achieved_sqft'),
        ]);
    }

    public function down(): void
    {
        Schema::table('target_calculations', function (Blueprint $table) {
            $table->dropIndex('target_calc_period_level_index');
            $table->dropColumn(['window_start', 'window_end', 'window_months', 'cumulative_sqft']);
        });
    }
};
