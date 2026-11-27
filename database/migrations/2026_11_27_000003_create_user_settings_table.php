<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('locale', 8)->default('id');
            $table->string('appearance', 16)->default('system');
            $table->string('density', 16)->default('comfortable');
            $table->string('analysis_mode', 16)->default('simple');
            $table->char('default_currency', 3)->default('IDR');
            $table->string('default_market', 16)->default('IDX');
            $table->string('default_timeframe', 8)->default('1Y');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->json('dashboard_layout')->nullable();
            $table->json('notification_prefs')->nullable();
            $table->json('chart_presets')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
