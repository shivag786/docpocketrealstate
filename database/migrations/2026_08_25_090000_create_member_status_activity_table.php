<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEMBER STATUS AUTOMATION MODULE — isolated table 1 of 3.
 *
 * Owned entirely by app/Modules/MemberStatus. No existing table is altered by
 * this migration and no existing table references it, so the module can be
 * removed by dropping these three tables and deleting the module directory
 * (MEMBER_STATUS_INTEGRATION.md §12).
 *
 * The ledger of qualifying activity: one row per member per sale that counted
 * for them. A single sale therefore produces AT MOST TWO rows — one for the
 * seller and one for the seller's direct sponsor. It never produces a row for a
 * grandparent (spec §18).
 *
 * NO FOREIGN KEYS, deliberately. Member and sale data reach this module through
 * the MemberProvider / PropertySaleProvider interfaces and may later come from
 * somewhere other than `members` and `registry_sales`. A constraint here would
 * both hard-wire that assumption and give the module the power to block an
 * existing delete, which it must never have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_status_activity', function (Blueprint $table) {
            $table->id();

            // Whose activity this is.
            $table->unsignedBigInteger('member_id');

            // OWN_SALE | DIRECT_REFERRAL_SALE (Enums\ActivityType).
            $table->string('activity_type', 32);

            // Whose sale produced it: the member themselves for an own sale,
            // the direct referral for a referral sale.
            $table->unsignedBigInteger('source_member_id');

            // Nullable so a provider that has no per-sale identity (an import,
            // a manual backfill) can still record activity.
            $table->unsignedBigInteger('sale_id')->nullable();

            // A date, not a datetime: the whole engine counts whole days.
            $table->date('activity_date');

            $table->timestamps();

            // Recording the same sale twice for the same member is a no-op
            // rather than a duplicate. This is what makes the event listener
            // and the batch backfill safe to run repeatedly (spec §24).
            $table->unique(['member_id', 'activity_type', 'sale_id'], 'member_status_activity_unique');

            // The engine's hot read: "latest activity_date for these members".
            $table->index(['member_id', 'activity_date']);
            $table->index('source_member_id');
            $table->index('sale_id');
            $table->index('activity_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_status_activity');
    }
};
