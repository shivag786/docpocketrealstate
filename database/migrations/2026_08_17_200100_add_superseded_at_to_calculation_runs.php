<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a run stopped holding the live figures.
 *
 * Figures are recalculated on every sale entry, so a month accumulates many
 * runs. Superseded runs are kept rather than deleted — they are the record of
 * who calculated what and when — but their results have been removed and only
 * the newest completed run per period and type holds live figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculation_runs', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('calculation_runs', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};
