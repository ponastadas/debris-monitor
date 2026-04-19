<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tle_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('satellite_id')->constrained()->cascadeOnDelete();
            $table->string('line1', 80);
            $table->string('line2', 80);
            $table->timestamp('epoch_at')->nullable();
            $table->string('source', 30)->default('celestrak');
            $table->timestamp('fetched_at');
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(['satellite_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tle_records');
    }
};
