<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLIENT DECISION (2026-08-31): a readable copy of each operator's password,
 * so it can be looked up from the database and from the Settings screen.
 *
 * THIS IS A DELIBERATE SECURITY TRADE-OFF, MADE BY THE CLIENT AFTER THE RISK
 * WAS PUT TO THEM IN WRITING. Recording it here so nobody later "fixes" it
 * without knowing it was a decision, and so nobody mistakes it for an accident:
 *
 *   - `password` is still the bcrypt hash and is still the ONLY column
 *     authentication reads. Nothing signs in using this column.
 *   - `password_plain` is a readable convenience copy, nothing more. Deleting
 *     it, or setting every value to NULL, breaks nothing — the panel simply
 *     stops being able to show the password.
 *
 * What it costs: anyone who can read this table — a hosting provider, a stray
 * database dump, a backup on someone's laptop, a future developer — has the
 * operator's real password, and with it any other account where that password
 * was reused.
 *
 * It is nullable because an account whose password predates this column has no
 * readable copy, and one cannot be derived from a hash. Those rows stay NULL
 * until the password is next set through the panel or `app:set-password`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_plain')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_plain');
        });
    }
};
