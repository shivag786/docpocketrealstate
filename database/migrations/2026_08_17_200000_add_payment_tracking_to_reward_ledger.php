<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment confirmation on the reward ledger.
 *
 * Client-confirmed 2026-08-17: rewards are recalculated continuously as sales
 * arrive and stay provisional all month. An admin then explicitly confirms
 * payment, and that act is what makes an amount final — a paid reward freezes
 * its period against further recalculation.
 *
 * `status` already existed with the single value 'posted'. It now also carries
 * 'paid', and these two columns record who confirmed it and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_ledger', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('status');

            // Nullable and nullOnDelete: losing the staff account must never
            // erase the fact that the payment was confirmed.
            $table->foreignId('paid_by')
                ->nullable()
                ->after('paid_at')
                ->constrained('users')
                ->nullOnDelete();

            // Every recalculation asks "is anything in this period paid?", and
            // the payment screens filter on exactly this.
            $table->index(['period', 'status'], 'reward_ledger_period_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('reward_ledger', function (Blueprint $table) {
            $table->dropIndex('reward_ledger_period_status_index');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn('paid_at');
        });
    }
};
