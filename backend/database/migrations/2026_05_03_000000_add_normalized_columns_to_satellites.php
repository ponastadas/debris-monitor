<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('satellites', function (Blueprint $table) {
            $table->string('name_normalized', 120)
                  ->storedAs("REGEXP_REPLACE(LOWER(name), '[^a-z0-9]', '')")
                  ->after('name');

            $table->string('designator_normalized', 20)
                  ->storedAs("REGEXP_REPLACE(LOWER(international_designator), '[^a-z0-9]', '')")
                  ->nullable()
                  ->after('international_designator');

            $table->index('name_normalized');
            $table->index('designator_normalized');
        });
    }

    public function down(): void
    {
        Schema::table('satellites', function (Blueprint $table) {
            $table->dropIndex(['name_normalized']);
            $table->dropIndex(['designator_normalized']);
            $table->dropColumn(['name_normalized', 'designator_normalized']);
        });
    }
};
