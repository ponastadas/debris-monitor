<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key', 64)->unique();
            $table->string('tier')->default('free');
            $table->unsignedInteger('daily_limit')->nullable();
            $table->boolean('webhooks_enabled')->default(false);
            $table->unsignedInteger('satellite_limit')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('api_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->string('endpoint');
            $table->string('method', 10);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('response_ms');
            $table->string('ip', 45);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage');
        Schema::dropIfExists('api_keys');
    }
};
