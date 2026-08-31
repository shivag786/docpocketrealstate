<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Club configuration - a single row.
 *
 * The display name is admin-configurable (spec section 3): "Company Club",
 * "Corporate Club", "Main Company", whatever the business calls it. Changing it
 * must never change the calculation, which is why nothing here is keyed on it.
 *
 * The rate and the level cap live here rather than in config/rewards.php alone
 * so an admin can change them without a deployment. Both are FROZEN onto every
 * calculation run, exactly as the Direct rate is frozen onto every ledger row,
 * so editing a setting cannot retroactively rewrite a figure somebody has seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_club_settings', function (Blueprint $table) {
            $table->id();

            $table->string('display_name', 100)->default('Company Club');

            // Rupees per Sq.Ft. of eligible monthly sales. Client-confirmed
            // 2026-08-19 as 50, overriding the 30 that predated this spec.
            $table->decimal('reward_rate', 10, 2)->default(50.00);

            // Maximum ACTIVE sponsor levels collected walking upward. Inactive
            // members are skipped and do not consume a level.
            $table->unsignedTinyInteger('max_upline_levels')->default(5);

            $table->string('status', 20)->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_club_settings');
    }
};
