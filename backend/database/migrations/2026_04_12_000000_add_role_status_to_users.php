<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', ['user', 'admin'])->default('user')->after('password');
            $table->enum('status', ['active', 'suspended'])->default('active')->after('role');
            $table->timestamp('suspended_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'status', 'suspended_at']);
        });
    }
};
