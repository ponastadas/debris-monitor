<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conjunction_events', function (Blueprint $table) {
            $table->id();

            // Space-Track CDM identifier — primary dedup key.
            // A single conjunction event may get multiple updated CDM messages;
            // each CDM_ID is distinct, so we store the latest message per event.
            $table->string('cdm_id', 20)->unique();

            $table->timestamp('created_at_cdm')->nullable();      // when CDM was issued
            $table->timestamp('tca');                              // time of closest approach (UTC)
            $table->decimal('min_range_km', 10, 3);               // minimum range (km)
            $table->double('probability')->nullable();             // PC — collision probability 0–1
            $table->boolean('emergency_reportable')->default(false);

            $table->string('sat1_norad_id', 10)->index();
            $table->string('sat1_name', 120);
            $table->string('sat2_norad_id', 10)->index();
            $table->string('sat2_name', 120);

            // 'space_track_cdm' is the only source currently;
            // leave room for future sources (e.g. ESA CAESAR, LeoLabs).
            $table->string('source', 30)->default('space_track_cdm');

            $table->timestamp('fetched_at');

            $table->timestamps();

            $table->index('tca');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conjunction_events');
    }
};
