<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brokers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name', 120);
            $table->string('exchange_member_code', 12)->nullable();
            $table->string('country_code', 8);
            $table->string('default_currency', 8);
            $table->string('csv_adapter', 32)->default('nadi_standard');
            $table->boolean('supports_live_sync')->default(false);
            $table->string('website_url', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('reporting_currency', 8)->default('IDR');
            $table->string('cost_basis_method', 24)->default('weighted_average');
            $table->boolean('is_default')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_default'], 'pf_user_default_idx');
        });

        Schema::create('brokerage_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('portfolio_id')->constrained('portfolios')->cascadeOnDelete();
            $table->foreignId('broker_id')->nullable()->constrained('brokers')->nullOnDelete();
            $table->string('account_name', 100);
            $table->string('account_label', 60)->nullable();
            $table->string('base_currency', 8)->default('IDR');
            $table->string('cost_basis_method', 24)->default('weighted_average');
            $table->decimal('cash_balance', 24, 4)->default(0);
            $table->decimal('net_contributions', 24, 4)->default(0);
            $table->decimal('realized_pnl_avg', 24, 4)->default(0);
            $table->decimal('realized_pnl_fifo', 24, 4)->default(0);
            $table->decimal('dividends_received', 24, 4)->default(0);
            $table->decimal('fees_paid', 24, 4)->default(0);
            $table->boolean('is_demo')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'portfolio_id'], 'ba_user_pf_idx');
        });

        Schema::create('portfolio_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('portfolio_id')->constrained('portfolios')->cascadeOnDelete();
            $table->foreignId('brokerage_account_id')->constrained('brokerage_accounts')->cascadeOnDelete();
            $table->foreignId('instrument_id')->nullable()->constrained('instruments')->nullOnDelete();
            $table->foreignId('counterparty_account_id')->nullable()->constrained('brokerage_accounts')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('portfolio_transactions')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('transaction_type', 32);
            $table->date('trade_date');
            $table->date('settlement_date')->nullable();
            $table->decimal('quantity', 20, 6)->nullable();
            $table->decimal('price', 20, 6)->nullable();
            $table->decimal('gross_amount', 24, 4)->default(0);
            $table->decimal('broker_fee', 24, 4)->default(0);
            $table->decimal('exchange_fee', 24, 4)->default(0);
            $table->decimal('tax_amount', 24, 4)->default(0);
            $table->decimal('net_cash_amount', 24, 4)->default(0);
            $table->string('transaction_currency', 8);
            $table->decimal('fx_rate_to_account', 20, 8)->default('1.00000000');
            $table->decimal('ratio_numerator', 18, 6)->nullable();
            $table->decimal('ratio_denominator', 18, 6)->nullable();
            $table->string('import_source', 32)->default('manual');
            $table->string('external_ref', 100)->nullable();
            $table->string('dedupe_hash', 64)->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->index(['brokerage_account_id', 'trade_date', 'id'], 'pt_acct_date_id_idx');
            $table->index(['user_id', 'instrument_id'], 'pt_user_inst_idx');
            $table->unique(['brokerage_account_id', 'dedupe_hash'], 'pt_acct_dedupe_unique');
        });

        Schema::create('portfolio_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('portfolio_id')->constrained('portfolios')->cascadeOnDelete();
            $table->foreignId('brokerage_account_id')->constrained('brokerage_accounts')->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained('instruments')->cascadeOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->string('currency', 8);
            $table->decimal('avg_cost_per_share', 20, 6);
            $table->decimal('total_cost_avg', 24, 4);
            $table->decimal('fifo_cost_per_share', 20, 6);
            $table->decimal('total_cost_fifo', 24, 4);
            $table->decimal('realized_pnl_avg', 24, 4)->default(0);
            $table->decimal('realized_pnl_fifo', 24, 4)->default(0);
            $table->decimal('dividends_received', 24, 4)->default(0);
            $table->boolean('is_demo')->default(false);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['brokerage_account_id', 'instrument_id'], 'ph_acct_inst_unique');
            $table->index(['user_id', 'instrument_id'], 'ph_user_inst_idx');
        });

        Schema::create('portfolio_cost_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brokerage_account_id')->constrained('brokerage_accounts')->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained('instruments')->cascadeOnDelete();
            $table->foreignId('source_transaction_id')->nullable()->constrained('portfolio_transactions')->cascadeOnDelete();
            $table->date('acquired_date');
            $table->decimal('original_quantity', 20, 6);
            $table->decimal('remaining_quantity', 20, 6);
            $table->decimal('unit_cost', 20, 6);
            $table->decimal('total_cost', 24, 4);
            $table->string('currency', 8);
            $table->timestamp('created_at')->nullable();

            $table->index(['brokerage_account_id', 'instrument_id', 'acquired_date'], 'pcl_acct_inst_date_idx');
        });

        Schema::create('portfolio_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('brokerage_account_id')->constrained('brokerage_accounts')->cascadeOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_cash_balance', 24, 4);
            $table->decimal('computed_cash_balance', 24, 4);
            $table->decimal('cash_difference', 24, 4);
            $table->json('statement_positions')->nullable();
            $table->string('status', 24);
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_reconciliations');
        Schema::dropIfExists('portfolio_cost_lots');
        Schema::dropIfExists('portfolio_holdings');
        Schema::dropIfExists('portfolio_transactions');
        Schema::dropIfExists('brokerage_accounts');
        Schema::dropIfExists('portfolios');
        Schema::dropIfExists('brokers');
    }
};
