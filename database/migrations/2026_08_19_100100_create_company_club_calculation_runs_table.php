<?php

use App\Enums\CalculationRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Company Club calculation - the snapshot an operator reads.
 *
 * This sits BESIDE `calculation_runs` rather than replacing it. The generic
 * table owns the lifecycle every engine shares (transaction, recorded failure,
 * supersede-not-delete, the paid-month lock); this table owns the figures that
 * are specific to this engine and that the history screen has to show.
 *
 * ROWS HERE ARE NEVER DELETED. When a month is recalculated the detail tables
 * (company_club_rewards, company_club_eligibility_paths) are cleared and
 * rebuilt, but this row survives with its status set to `superseded`. That is
 * what lets the history screen say "previously CC-2026-08-0002 paid 575,000.00
 * to 7 members on 18 Aug" - client-confirmed 2026-08-19: the admin must never be
 * confused about which calculation they are looking at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_club_calculation_runs', function (Blueprint $table) {
            $table->id();

            // Human-facing identifier, e.g. CC-2026-08-0001. Sequential within
            // the period, so the third rebuild of August is CC-2026-08-0003.
            $table->string('run_code', 24)->unique();

            $table->string('period', 7);          // YYYY-MM

            /*
             * The inputs, FROZEN at calculation time.
             *
             * total_sqft counts only sales by ACTIVE sellers inside the Company
             * Club network - it therefore legitimately differs from the Direct
             * run's total whenever an inactive member has sales.
             */
            $table->decimal('total_sqft', 15, 2)->default(0);
            $table->decimal('rate', 10, 2);

            // ONE pool for the whole month: total_sqft * rate.
            $table->decimal('pool_amount', 18, 2)->default(0);

            // Unique recipients, after duplicates across sale paths are removed.
            $table->unsignedInteger('eligible_count')->default(0);

            // pool_amount / eligible_count, rounded half-up to 2 decimals.
            $table->decimal('equal_share', 18, 2)->default(0);

            // equal_share * eligible_count - what was actually written out.
            $table->decimal('distributed_amount', 18, 2)->default(0);

            /*
             * distributed_amount - pool_amount.
             *
             * Rounding each share to 2 decimals means the shares need not re-sum
             * to the pool. This is real money and is recorded rather than
             * silently absorbed, following the Phase 6 upline precedent. The
             * Company Club decisions document leaves the accounting treatment of
             * the remainder open; nothing here invents one.
             */
            $table->decimal('residual_amount', 18, 2)->default(0);

            // How many ACTIVE members produced the eligible sales.
            $table->unsignedInteger('seller_count')->default(0);

            $table->string('status', 20)->default(CalculationRunStatus::Completed->value);

            // The generic run that owns the transaction and the ledger rows.
            $table->foreignId('calculation_run_id')->constrained('calculation_runs')->restrictOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            // Whether an admin started this run, or a sale landing in an
            // already-calculated month did. Shown on screen so the admin knows.
            $table->boolean('automatic')->default(false);

            $table->timestamps();

            $table->index(['period', 'status']);
            $table->index('calculation_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_club_calculation_runs');
    }
};
