<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The duplicate-protection index must include the period.
 *
 * Direct rewards are sourced from a registry sale, and a sale id is naturally
 * unique to one period — so (member, type, source_type, source_id) was enough.
 *
 * Upline rewards are sourced from a SELLER's monthly total, so the same
 * (receiver, seller) pair legitimately recurs every month. Without `period` in
 * the index the second month's upline run would collide with the first and be
 * rejected as a duplicate.
 *
 * Adding period keeps duplicate protection exactly as strong WITHIN a period,
 * which is what the rule actually requires, while allowing the same source to
 * pay again in a different month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_ledger', function (Blueprint $table) {
            $table->dropUnique('reward_ledger_source_unique');

            $table->unique(
                ['member_id', 'reward_type', 'source_type', 'source_id', 'period'],
                'reward_ledger_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('reward_ledger', function (Blueprint $table) {
            $table->dropUnique('reward_ledger_source_unique');

            $table->unique(
                ['member_id', 'reward_type', 'source_type', 'source_id'],
                'reward_ledger_source_unique'
            );
        });
    }
};
