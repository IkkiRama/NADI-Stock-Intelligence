<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates_daily', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 20, 8);
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'rate_date'], 'fx_pair_date_unique');
            $table->index('rate_date');
        });

        Schema::create('corporate_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->string('action_type', 32);
            $table->date('ex_date');
            $table->date('record_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->char('declared_currency', 3)->nullable();
            $table->decimal('cash_amount', 20, 6)->nullable();
            $table->decimal('ratio_numerator', 16, 6)->nullable();
            $table->decimal('ratio_denominator', 16, 6)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->unique(['instrument_id', 'action_type', 'ex_date'], 'corp_action_unique');
            $table->index('ex_date');
        });

        Schema::create('financial_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->string('period_type', 12);
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('period_end_date');
            $table->date('published_at');
            $table->char('currency', 3);
            $table->boolean('is_financial_sector')->default(false);
            $table->decimal('revenue', 24, 4)->nullable();
            $table->decimal('gross_profit', 24, 4)->nullable();
            $table->decimal('operating_income', 24, 4)->nullable();
            $table->decimal('net_income', 24, 4)->nullable();
            $table->decimal('ebitda', 24, 4)->nullable();
            $table->decimal('extraordinary_items', 24, 4)->nullable();
            $table->decimal('total_assets', 24, 4)->nullable();
            $table->decimal('current_assets', 24, 4)->nullable();
            $table->decimal('cash_and_equivalents', 24, 4)->nullable();
            $table->decimal('total_liabilities', 24, 4)->nullable();
            $table->decimal('current_liabilities', 24, 4)->nullable();
            $table->decimal('total_debt', 24, 4)->nullable();
            $table->decimal('total_equity', 24, 4)->nullable();
            $table->decimal('operating_cash_flow', 24, 4)->nullable();
            $table->decimal('capex', 24, 4)->nullable();
            $table->decimal('free_cash_flow', 24, 4)->nullable();
            $table->decimal('dividends_paid', 24, 4)->nullable();
            $table->decimal('shares_outstanding', 24, 4)->nullable();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->unique(['instrument_id', 'period_type', 'fiscal_year'], 'fin_stmt_period_unique');
            $table->index(['instrument_id', 'published_at'], 'fin_stmt_pit_idx');
        });

        Schema::create('instrument_fundamentals_latest', function (Blueprint $table) {
            $table->unsignedBigInteger('instrument_id')->primary();
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
            $table->date('as_of_date');
            $table->string('period_label', 24);
            $table->char('statement_currency', 3);
            $table->decimal('market_cap', 24, 4)->nullable();
            $table->decimal('market_cap_idr', 26, 4)->nullable();
            $table->decimal('eps', 20, 6)->nullable();
            $table->decimal('per', 16, 6)->nullable();
            $table->decimal('pbv', 16, 6)->nullable();
            $table->decimal('roe', 16, 8)->nullable();
            $table->decimal('roa', 16, 8)->nullable();
            $table->decimal('der', 16, 6)->nullable();
            $table->decimal('current_ratio', 16, 6)->nullable();
            $table->decimal('net_margin', 16, 8)->nullable();
            $table->decimal('revenue_growth_yoy', 16, 8)->nullable();
            $table->decimal('earnings_growth_yoy', 16, 8)->nullable();
            $table->decimal('ocf', 24, 4)->nullable();
            $table->decimal('fcf', 24, 4)->nullable();
            $table->decimal('dividend_yield', 16, 8)->nullable();
            $table->decimal('dividend_payout_ratio', 16, 8)->nullable();
            $table->boolean('is_financial_sector')->default(false);
            $table->json('missing_fields')->nullable();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('calculated_at');
        });

        Schema::create('instrument_technicals_latest', function (Blueprint $table) {
            $table->unsignedBigInteger('instrument_id')->primary();
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('sma_20', 20, 6)->nullable();
            $table->decimal('sma_50', 20, 6)->nullable();
            $table->decimal('sma_200', 20, 6)->nullable();
            $table->decimal('ema_20', 20, 6)->nullable();
            $table->decimal('ema_50', 20, 6)->nullable();
            $table->decimal('macd_line', 20, 6)->nullable();
            $table->decimal('macd_signal', 20, 6)->nullable();
            $table->decimal('macd_hist', 20, 6)->nullable();
            $table->decimal('bb_upper', 20, 6)->nullable();
            $table->decimal('bb_middle', 20, 6)->nullable();
            $table->decimal('bb_lower', 20, 6)->nullable();
            $table->decimal('atr_14', 20, 6)->nullable();
            $table->decimal('rsi_14', 10, 4)->nullable();
            $table->bigInteger('obv')->nullable();
            $table->decimal('volatility_20d', 16, 8)->nullable();
            $table->decimal('return_1w', 16, 8)->nullable();
            $table->decimal('return_1m', 16, 8)->nullable();
            $table->decimal('return_3m', 16, 8)->nullable();
            $table->decimal('return_6m', 16, 8)->nullable();
            $table->decimal('return_1y', 16, 8)->nullable();
            $table->decimal('relative_strength_1m', 16, 8)->nullable();
            $table->timestamp('calculated_at');
        });

        Schema::create('scoring_configs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->json('weights');
            $table->string('version', 24);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('instrument_scores', function (Blueprint $table) {
            $table->unsignedBigInteger('instrument_id')->primary();
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
            $table->date('as_of_date');
            $table->decimal('overall_score', 6, 2);
            $table->decimal('fundamentals_score', 6, 2)->nullable();
            $table->decimal('valuation_score', 6, 2)->nullable();
            $table->decimal('technical_score', 6, 2)->nullable();
            $table->decimal('risk_score', 6, 2)->nullable();
            $table->decimal('catalysts_score', 6, 2)->nullable();
            $table->decimal('sector_percentile', 6, 2)->nullable();
            $table->decimal('data_coverage', 5, 2);
            $table->json('reasons');
            $table->json('missing_indicators');
            $table->json('weights_used');
            $table->string('calculation_version', 24);
            $table->boolean('is_demo')->default(false);
            $table->timestamp('calculated_at');
        });

        Schema::create('instrument_score_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('score_date');
            $table->decimal('overall_score', 6, 2);
            $table->json('components');
            $table->string('calculation_version', 24);
            $table->timestamp('created_at');

            $table->unique(['instrument_id', 'score_date'], 'score_history_unique');
        });

        Schema::create('saved_screeners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('filters');
            $table->string('sort_by', 40)->default('overall_score');
            $table->string('sort_dir', 4)->default('desc');
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('data_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dataset', 40);
            $table->string('mode', 20);
            $table->string('status', 20);
            $table->unsignedInteger('rows_processed')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->text('error_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['dataset', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_import_runs');
        Schema::dropIfExists('saved_screeners');
        Schema::dropIfExists('instrument_score_history');
        Schema::dropIfExists('instrument_scores');
        Schema::dropIfExists('scoring_configs');
        Schema::dropIfExists('instrument_technicals_latest');
        Schema::dropIfExists('instrument_fundamentals_latest');
        Schema::dropIfExists('financial_statements');
        Schema::dropIfExists('corporate_actions');
        Schema::dropIfExists('fx_rates_daily');
    }
};
