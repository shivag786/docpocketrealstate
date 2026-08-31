<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company identity - a single row, in the same shape as company_club_settings.
 *
 * What belongs here is everything that is about the BUSINESS rather than the
 * calculation: the trading name, the logo, the address staff put on a printed
 * letter, and who signs it. None of it is ever read by a reward engine, and
 * nothing here may be keyed on by one.
 *
 * The designation list lives here too. It is admin-editable precisely because
 * the client will rename ranks, and a rank name in a PHP enum would need a
 * deployment to change. Members store the chosen string, so renaming a rank in
 * this list does NOT rewrite the designation on members already issued a
 * printed card - which is the correct behaviour: the card in their hand says
 * what it says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();

            $table->string('company_name', 150)->default('');
            $table->string('tagline', 200)->nullable();

            // Relative path on the `public` disk, not a URL: the PDF renderer
            // reads it off disk, and a stored absolute URL would break the
            // moment the site moves domain.
            $table->string('logo_path')->nullable();

            $table->text('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // Printed under the signature line on the welcome letter.
            $table->string('authority_name', 150)->nullable();
            $table->string('authority_designation', 100)->nullable();
            $table->string('signature_path')->nullable();

            // The designations a member may hold, in display order. JSON rather
            // than a child table: it is a short, ordered list of plain strings
            // that is edited as a whole, and a table would add a join to every
            // member form for nothing.
            $table->json('designations')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
