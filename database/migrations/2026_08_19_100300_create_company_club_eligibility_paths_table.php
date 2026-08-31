<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a member qualified - every path, not just the first one found.
 *
 * A member may qualify through several selling branches and must be paid only
 * once, but BOTH eligibility paths have to survive so the admin can open "View
 * Calculation Reason" and see each one. `company_club_rewards` holds the single
 * payout; this table holds the several reasons.
 *
 * `chain_depth` > `upline_level` is the audit trail of a skipped inactive
 * sponsor: depth counts database hops, level counts ACTIVE members. The same
 * technique `upline_calculations` has used since Phase 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_club_eligibility_paths', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_club_run_id')
                ->constrained('company_club_calculation_runs')
                ->cascadeOnDelete();

            // The ACTIVE member whose sales created this path.
            $table->foreignId('sale_member_id')->constrained('members')->restrictOnDelete();

            // The ACTIVE upline member who therefore qualifies.
            $table->foreignId('eligible_member_id')->constrained('members')->restrictOnDelete();

            // 1 = immediate ACTIVE sponsor. Company Club is NEVER a level.
            $table->unsignedTinyInteger('upline_level');

            // Database hops from the seller. Exceeds upline_level when inactive
            // sponsors were skipped on the way up.
            $table->unsignedTinyInteger('chain_depth');

            // The seller's eligible Sq.Ft. for the month, carried so the
            // explanation screen can show it without re-querying sales per row.
            $table->decimal('sale_member_sqft', 15, 2)->default(0);

            /*
             * The walk as it stood at calculation time: every member between the
             * seller and the recipient, each marked active or skipped.
             *
             * A snapshot, deliberately. Members can be deactivated or re-parented
             * later, and a historical explanation must keep saying what was true
             * when the money was calculated.
             */
            $table->json('path_snapshot')->nullable();

            $table->timestamps();

            $table->index(['company_club_run_id', 'eligible_member_id'], 'cc_paths_run_recipient');
            $table->index(['company_club_run_id', 'sale_member_id'], 'cc_paths_run_seller');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_club_eligibility_paths');
    }
};
