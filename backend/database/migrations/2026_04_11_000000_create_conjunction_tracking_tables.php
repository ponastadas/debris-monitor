<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watched_satellites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('norad_id', 10);
            $table->string('name')->nullable();
            $table->text('tle_line1')->nullable();
            $table->text('tle_line2')->nullable();
            $table->timestamp('tle_fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'norad_id']);
            $table->index('norad_id');
        });

        Schema::create('conjunction_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('primary_norad_id', 10)->index();
            $table->string('primary_name');
            $table->string('secondary_norad_id', 10)->index();
            $table->string('secondary_name');
            $table->timestamp('tca');                    // time of closest approach
            $table->float('miss_distance_km');
            $table->float('probability')->nullable();    // collision probability 0–1
            $table->unsignedTinyInteger('risk_score');   // 0–100
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            // One alert per pair per TCA day — prevents duplicate alerts across runs
            $table->unique(
                ['primary_norad_id', 'secondary_norad_id', 'tca'],
                'unique_conjunction'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conjunction_alerts');
        Schema::dropIfExists('watched_satellites');
    }
};
