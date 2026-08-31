<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLIENT REQUEST (2026-08-31): a member record now also carries a blood group
 * and a designation.
 *
 * Neither takes part in any reward calculation. They exist because the welcome
 * letter and the ID card print them, and because staff are asked for a member's
 * blood group at field events.
 *
 * `designation` is NOT NULL with a default, because every member holds one from
 * the day they join. The list of permitted values is admin-editable and lives in
 * `company_settings`; it is deliberately not an enum, so the client can rename a
 * rank without a deployment. `blood_group` is nullable — it is genuinely often
 * unknown at registration, and a blank is more honest than a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('blood_group', 5)->nullable()->after('email');

            $table->string('designation', 100)
                ->default(config('company.designations.default', 'Sales Advisor'))
                ->after('blood_group');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['blood_group', 'designation']);
        });
    }
};
