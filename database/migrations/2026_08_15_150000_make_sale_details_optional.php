<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLIENT CORRECTION (2026-08-15, after Phase 5):
 *
 * A sale now needs only a member and a Sq.Ft. figure. The project, property,
 * registry number and registry date are supporting detail the admin may record
 * if they have it.
 *
 * `registry_reference` stays UNIQUE, which in MySQL/MariaDB still permits many
 * NULL rows. So a registry number remains a duplicate guard when supplied, and
 * simply does not act as one when omitted.
 *
 * `registry_date` remains NOT NULL: it decides which month a sale belongs to for
 * every reward calculation. The form no longer asks for it, and the application
 * fills it with the entry date, which is the client-confirmed rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registry_sales', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['property_id']);
        });

        Schema::table('registry_sales', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
            $table->foreignId('property_id')->nullable()->change();
            $table->string('registry_reference', 100)->nullable()->change();
        });

        Schema::table('registry_sales', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registry_sales', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['property_id']);
        });

        Schema::table('registry_sales', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->foreignId('property_id')->nullable(false)->change();
            $table->string('registry_reference', 100)->nullable(false)->change();
        });

        Schema::table('registry_sales', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
        });
    }
};
