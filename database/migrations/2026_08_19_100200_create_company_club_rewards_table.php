<?php

use App\Enums\LedgerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One recipient's share of one Company Club run.
 *
 * The money also lands in `reward_ledger` (reward_type `company_club`), which is
 * what the payment workflow and the reconciliation reports read. This table adds
 * what the ledger has no column for: how many separate eligibility paths made
 * this member qualify, which is the number the explanation screen unpacks.
 *
 * THE DUPLICATE RULE IS ENFORCED HERE BY THE DATABASE. A member may qualify
 * through many selling branches but is paid exactly once per run - the unique
 * index below makes a second row physically impossible rather than relying on
 * the engine remembering to de-duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_club_rewards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_club_run_id')
                ->constrained('company_club_calculation_runs')
                ->cascadeOnDelete();

            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();

            $table->decimal('amount', 18, 2);

            // How many (seller -> this member) paths qualified them. Always >= 1.
            $table->unsignedInteger('eligibility_path_count')->default(1);

            // The nearest level this member was reached at, across all paths.
            // Reported so the list reads without opening every explanation.
            $table->unsignedTinyInteger('best_level')->nullable();

            $table->string('status', 20)->default(LedgerStatus::Posted->value);

            $table->timestamps();

            // One payout per member per run. The duplicate rule, in the schema.
            $table->unique(['company_club_run_id', 'member_id'], 'cc_reward_once_per_run');

            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_club_rewards');
    }
};
