<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEMBER STATUS AUTOMATION MODULE — isolated table 2 of 3.
 *
 * The module's OWN status value, one row per member (spec §21-§22).
 *
 * `members.status` is not read and never written by this module. Until somebody
 * decides to connect the two, this table is the only place the calculated
 * ACTIVE / PENDING / INACTIVE value exists, which is what allows the new rules
 * to be observed against live data without changing how the application behaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_status_snapshot', function (Blueprint $table) {
            $table->id();

            // One snapshot per member: the current calculated status.
            $table->unsignedBigInteger('member_id')->unique();

            // ACTIVE | PENDING | INACTIVE (Enums\CalculatedStatus).
            $table->string('status', 16);

            // Null for a member who has never had qualifying activity; their
            // clock runs from their joining date instead.
            $table->date('last_activity_at')->nullable();

            // Denormalised so the report can say WHY without a second query.
            $table->string('last_activity_type', 32)->nullable();
            $table->unsignedBigInteger('last_activity_source_member_id')->nullable();
            $table->unsignedBigInteger('last_activity_sale_id')->nullable();

            // The date the clock is actually counted from — activity date, or
            // joining date (+ grace) for a member with no activity yet.
            $table->date('reference_date');

            // Whole days from reference_date to calculated_at. Stored rather
            // than derived so a report can sort and filter on it directly.
            $table->unsignedInteger('days_since_activity')->default(0);

            // When the status last actually changed, not when it was last
            // recalculated — those are different questions and the report asks
            // both.
            $table->date('status_changed_at')->nullable();
            $table->date('calculated_at');

            $table->timestamps();

            $table->index('status');
            $table->index('last_activity_at');
            $table->index(['status', 'days_since_activity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_status_snapshot');
    }
};
