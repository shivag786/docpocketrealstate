<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLIENT REQUEST (2026-08-31): the sale entry form records WHERE the plot is.
 *
 * The project is picked from a dropdown, because projects are a managed list.
 * The block and the plot number are typed, because they are not: a project
 * gains blocks as it is laid out, and asking an admin to go and create a
 * "Property / Site" record before they can record a sale was the friction the
 * client asked to remove. The form offers the blocks already recorded against
 * the chosen project as suggestions, so repeated entry converges on one
 * spelling without ever refusing a new one.
 *
 * Free text, therefore, but not unchecked — see StoreRegistrySaleRequest.
 *
 * The existing nullable `property_id` is untouched. Sales recorded before today
 * keep it, and nothing that reads it has to learn about these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registry_sales', function (Blueprint $table) {
            $table->string('block_name', 100)->nullable()->after('property_id');
            $table->string('plot_number', 50)->nullable()->after('block_name');

            // Drives the block autocomplete: "the blocks already known for this
            // project", which is a lookup on exactly these two columns.
            $table->index(['project_id', 'block_name']);
        });
    }

    public function down(): void
    {
        Schema::table('registry_sales', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'block_name']);
            $table->dropColumn(['block_name', 'plot_number']);
        });
    }
};
