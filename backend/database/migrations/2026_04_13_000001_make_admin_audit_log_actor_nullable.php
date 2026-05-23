<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make admin_audit_logs.admin_account_id nullable so that failed login
 * attempts (unknown email) can be recorded without a valid actor ID.
 * All existing rows have a non-null value, so this is a safe change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            // Drop the current NOT NULL FK, recreate as nullable FK
            $table->dropForeign(['admin_account_id']);
            $table->unsignedBigInteger('admin_account_id')->nullable()->change();
            $table->foreign('admin_account_id')
                ->references('id')->on('admin_accounts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['admin_account_id']);
            $table->unsignedBigInteger('admin_account_id')->nullable(false)->change();
            $table->foreign('admin_account_id')
                ->references('id')->on('admin_accounts')
                ->cascadeOnDelete();
        });
    }
};
