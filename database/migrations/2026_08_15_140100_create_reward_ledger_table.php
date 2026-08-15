<?php

use App\Enums\LedgerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_ledger', function (Blueprint $table) {
            $table->id();

            // Who the reward belongs to.
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();

            // Which of the four independent engines produced it. Never mix these.
            $table->string('reward_type', 30);

            // What it was calculated from, so every amount is explainable
            // (docs/03_DATABASE_AND_ARCHITECTURE.md).
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');

            $table->string('period', 7);          // YYYY-MM

            // The exact inputs, frozen at calculation time. The rate is copied
            // here so a historical run stays reproducible even if the configured
            // rate ever changes.
            $table->decimal('sqft', 12, 2);
            $table->decimal('rate', 10, 2);
            $table->decimal('amount', 15, 2);

            $table->string('status', 20)->default(LedgerStatus::Posted->value);

            $table->foreignId('calculation_run_id')->constrained('calculation_runs')->restrictOnDelete();

            $table->timestamps();

            /*
             * Duplicate protection, enforced by the database rather than by
             * application logic alone.
             *
             * One member can receive at most one reward of a given type from a
             * given source. Two concurrent runs for the same period therefore
             * cannot double-pay: the second insert fails on this index even if
             * the run-level guard were somehow bypassed.
             */
            $table->unique(
                ['member_id', 'reward_type', 'source_type', 'source_id'],
                'reward_ledger_source_unique'
            );

            $table->index(['period', 'reward_type']);
            $table->index(['member_id', 'period']);
            $table->index('calculation_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_ledger');
    }
};
