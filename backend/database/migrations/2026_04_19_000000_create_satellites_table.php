<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('satellites', function (Blueprint $table) {
            $table->id();
            $table->string('norad_id', 10)->unique();
            $table->string('name', 120);
            $table->string('object_type', 30)->nullable();
            $table->string('international_designator', 20)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->date('launch_date')->nullable();
            $table->date('decay_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('catalog_source', 30)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('satellites');
    }
};
