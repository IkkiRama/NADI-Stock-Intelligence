<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('type', 24);
            $table->json('datasets');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->string('attribution', 255)->nullable();
            $table->text('license_notes')->nullable();
            $table->json('config')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
        });

        Schema::create('instrument_prices_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('open', 20, 6);
            $table->decimal('high', 20, 6);
            $table->decimal('low', 20, 6);
            $table->decimal('close', 20, 6);
            $table->decimal('adj_close', 20, 6)->nullable();
            $table->unsignedBigInteger('volume')->default(0);
            $table->decimal('value_traded', 24, 4)->nullable();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->unique(['instrument_id', 'trade_date']);
            $table->index('trade_date');
        });

        Schema::create('index_values_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_index_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('open', 20, 6);
            $table->decimal('high', 20, 6);
            $table->decimal('low', 20, 6);
            $table->decimal('close', 20, 6);
            $table->unsignedBigInteger('volume')->nullable();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->unique(['stock_index_id', 'trade_date']);
            $table->index('trade_date');
        });

        Schema::create('instrument_quotes_latest', function (Blueprint $table) {
            $table->unsignedBigInteger('instrument_id')->primary();
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('close', 20, 6);
            $table->decimal('prev_close', 20, 6)->nullable();
            $table->decimal('change', 20, 6)->nullable();
            $table->decimal('change_pct', 16, 8)->nullable();
            $table->unsignedBigInteger('volume')->default(0);
            $table->decimal('value_traded', 24, 4)->nullable();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('refreshed_at');
        });

        Schema::create('index_quotes_latest', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_index_id')->primary();
            $table->foreign('stock_index_id')->references('id')->on('stock_indices')->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('close', 20, 6);
            $table->decimal('prev_close', 20, 6)->nullable();
            $table->decimal('change', 20, 6)->nullable();
            $table->decimal('change_pct', 16, 8)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_quotes_latest');
        Schema::dropIfExists('instrument_quotes_latest');
        Schema::dropIfExists('index_values_daily');
        Schema::dropIfExists('instrument_prices_daily');
        Schema::dropIfExists('data_sources');
    }
};
