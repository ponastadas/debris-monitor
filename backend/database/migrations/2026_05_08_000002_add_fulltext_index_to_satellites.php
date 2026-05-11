<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return; // FULLTEXT is MySQL-only; SQLite test env skips this
        }

        Schema::table('satellites', function (Blueprint $table) {
            $table->fullText(['name', 'name_normalized'], 'satellites_name_fulltext');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('satellites', function (Blueprint $table) {
            $table->dropFullText('satellites_name_fulltext');
        });
    }
};
