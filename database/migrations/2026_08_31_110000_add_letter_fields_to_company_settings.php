<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLIENT REQUEST (2026-08-31): which details the welcome letter prints is an
 * admin decision, not a developer one.
 *
 * Six optional rows — sponsor, email, designation, blood group, contact number
 * and company name. The member's NAME, ID and JOINING DATE are deliberately not
 * toggleable: a welcome letter that cannot say who it is for, what their code
 * is, or when they joined is not a welcome letter.
 *
 * NULL means "never configured" and reads as the defaults in config/company.php,
 * so an install that predates this column behaves exactly as it did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->json('letter_fields')->nullable()->after('designations');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('letter_fields');
        });
    }
};
