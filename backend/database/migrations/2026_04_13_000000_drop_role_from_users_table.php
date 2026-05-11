<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safety: demote any legacy admin users before the column is removed.
        // Admin access is now exclusively via the admin_accounts table.
        DB::table('users')->where('role', 'admin')->update(['role' => 'user']);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', ['user', 'admin'])->default('user')->after('password');
        });
    }
};
