<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepare admin_accounts for TOTP MFA:
 *
 *  1. mfa_secret       — widen from varchar(255) to text so encrypted content fits.
 *                        Stored via Laravel's 'encrypted' Eloquent cast (AES-256-GCM,
 *                        keyed by APP_KEY).  Null means MFA is not configured.
 *
 *  2. mfa_recovery_codes — text nullable.  Stored via 'encrypted:array' cast:
 *                          the array of bcrypt hashes is JSON-encoded then encrypted
 *                          before persisting, so neither the plain codes nor the hashes
 *                          are visible in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_accounts', function (Blueprint $table) {
            // Widen existing column — encrypted blobs exceed varchar(255)
            $table->text('mfa_secret')->nullable()->change();

            // Array of bcrypt-hashed recovery codes, stored encrypted
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_secret');
        });
    }

    public function down(): void
    {
        Schema::table('admin_accounts', function (Blueprint $table) {
            $table->dropColumn('mfa_recovery_codes');
            $table->string('mfa_secret')->nullable()->change();
        });
    }
};
