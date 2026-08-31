<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEMBER STATUS AUTOMATION MODULE — isolated table 3 of 3.
 *
 * The audit trail (spec §20). A row is written ONLY when the status actually
 * changes, so a daily job over a quiet network writes nothing at all and the
 * table stays a readable history rather than a log of every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_status_history', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('member_id');

            // Null on the very first calculation: the member had no calculated
            // status before it, which is not the same as having been ACTIVE.
            $table->string('old_status', 16)->nullable();
            $table->string('new_status', 16);

            // Human-readable, e.g. "No qualifying activity for 90 days" or
            // "Direct referral property sale".
            $table->string('reason', 255);

            // The date the change takes effect — the calculation date, which
            // may be back-dated when a status is recalculated "as of" a day.
            $table->date('effective_at');

            // created_at only. A history row is a fact about a moment; it is
            // never edited, so there is nothing for updated_at to record.
            $table->timestamp('created_at')->nullable();

            $table->index(['member_id', 'effective_at']);
            $table->index('new_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_status_history');
    }
};
