<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_usage', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 64)->comment('Guest UUID from localStorage (X-Guest-ID header), or IP as fallback');
            $table->date('date');
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['identifier', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_usage');
    }
};
