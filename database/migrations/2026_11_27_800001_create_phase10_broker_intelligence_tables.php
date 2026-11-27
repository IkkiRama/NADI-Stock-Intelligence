<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brokers', function (Blueprint $table) {
            $table->string('broker_category', 32)->default('local_retail')->after('csv_adapter');
            $table->decimal('typical_buy_fee_pct', 8, 6)->nullable()->after('broker_category');
            $table->decimal('typical_sell_fee_pct', 8, 6)->nullable()->after('typical_buy_fee_pct');
            $table->string('notes', 255)->nullable()->after('website_url');
        });

        Schema::create('broker_summaries_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained('instruments')->cascadeOnDelete();
            $table->foreignId('broker_id')->nullable()->constrained('brokers')->nullOnDelete();
            $table->string('broker_code', 16);
            $table->string('broker_category', 32)->default('local_retail');
            $table->date('trade_date');
            $table->unsignedBigInteger('buy_volume')->default(0);
            $table->unsignedBigInteger('sell_volume')->default(0);
            $table->bigInteger('net_volume')->default(0);
            $table->decimal('buy_value', 24, 4)->default(0);
            $table->decimal('sell_value', 24, 4)->default(0);
            $table->decimal('net_value', 24, 4)->default(0);
            $table->decimal('avg_buy_price', 20, 6)->nullable();
            $table->decimal('avg_sell_price', 20, 6)->nullable();
            $table->string('currency', 8)->default('IDR');
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->unique(['instrument_id', 'trade_date', 'broker_code'], 'bsd_inst_date_broker_unique');
            $table->index(['trade_date', 'broker_code'], 'bsd_date_broker_idx');
            $table->index(['instrument_id', 'trade_date'], 'bsd_inst_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_summaries_daily');

        Schema::table('brokers', function (Blueprint $table) {
            $table->dropColumn(['broker_category', 'typical_buy_fee_pct', 'typical_sell_fee_pct', 'notes']);
        });
    }
};
