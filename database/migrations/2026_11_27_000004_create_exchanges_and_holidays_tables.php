<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 120);
            $table->string('mic', 16)->nullable();
            $table->char('country_code', 2);
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->unsignedInteger('default_lot_size')->default(1);
            $table->json('sessions');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('market_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name', 150);
            $table->boolean('is_half_day')->default(false);
            $table->timestamps();
            $table->unique(['exchange_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_holidays');
        Schema::dropIfExists('exchanges');
    }
};
