<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tle_records', function (Blueprint $table) {
            $table->dateTime('epoch_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tle_records', function (Blueprint $table) {
            $table->timestamp('epoch_at')->nullable()->change();
        });
    }
};
