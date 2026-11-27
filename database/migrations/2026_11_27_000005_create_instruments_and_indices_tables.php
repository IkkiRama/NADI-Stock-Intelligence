<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instruments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('name', 200);
            $table->string('type', 24)->default('equity');
            $table->char('currency', 3);
            $table->string('sector', 100)->nullable();
            $table->string('industry', 120)->nullable();
            $table->char('country_code', 2);
            $table->string('isin', 20)->nullable();
            $table->unsignedInteger('lot_size')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['exchange_id', 'symbol']);
            $table->index('name');
            $table->index('sector');
        });

        Schema::create('stock_indices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name', 150);
            $table->char('country_code', 2);
            $table->foreignId('exchange_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3);
            $table->string('index_type', 24)->default('price');
            $table->boolean('is_benchmark')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('index_constituents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_index_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight', 16, 8)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['stock_index_id', 'instrument_id', 'effective_from'], 'idx_const_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_constituents');
        Schema::dropIfExists('stock_indices');
        Schema::dropIfExists('instruments');
    }
};
