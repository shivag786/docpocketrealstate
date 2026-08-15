<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full working for every upline distribution.
 *
 * reward_ledger records WHAT a receiver is owed; this table records WHY —
 * whose sales produced the pool, how large it was, how many uplines qualified
 * and which position this receiver held. Without it an upline amount cannot be
 * explained, which Phase 13 reconciliation requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upline_calculations', function (Blueprint $table) {
            $table->id();

            $table->string('period', 7);

            // Whose monthly sales created the pool.
            $table->foreignId('seller_id')->constrained('members')->restrictOnDelete();

            // Who receives this share.
            $table->foreignId('receiver_id')->constrained('members')->restrictOnDelete();

            // Position among the ELIGIBLE uplines, 1 = nearest qualifying upline.
            // Inactive members are skipped, so this is not the same as the raw
            // sponsor depth.
            $table->unsignedTinyInteger('upline_level');

            // How many sponsor links above the seller this receiver actually sits.
            // Differs from upline_level whenever inactive members were skipped,
            // which is what makes the compression auditable.
            $table->unsignedSmallInteger('chain_depth');

            $table->decimal('seller_sqft', 12, 2);
            $table->decimal('pool_rate', 10, 2);
            $table->decimal('pool_amount', 15, 2);
            $table->unsignedTinyInteger('eligible_upline_count');
            $table->decimal('receiver_amount', 15, 2);

            $table->foreignId('calculation_run_id')->constrained('calculation_runs')->restrictOnDelete();

            $table->timestamps();

            // One share per receiver per seller per period.
            $table->unique(['period', 'seller_id', 'receiver_id'], 'upline_calc_unique');

            $table->index(['period', 'seller_id']);
            $table->index(['period', 'receiver_id']);
            $table->index('calculation_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upline_calculations');
    }
};
