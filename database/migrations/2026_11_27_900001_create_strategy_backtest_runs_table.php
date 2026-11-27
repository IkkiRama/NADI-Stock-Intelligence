<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained('instruments')->cascadeOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('strategy_code', 40);
            $table->string('range_code', 16)->default('1Y');
            $table->string('currency', 8);
            $table->decimal('initial_capital', 24, 4);
            $table->json('parameters');
            $table->json('summary_metrics');
            $table->unsignedInteger('trades_count')->default(0);
            $table->decimal('total_return_pct', 16, 8)->nullable();
            $table->decimal('buy_hold_return_pct', 16, 8)->nullable();
            $table->decimal('max_drawdown_pct', 16, 8)->nullable();
            $table->decimal('sharpe_ratio', 16, 6)->nullable();
            $table->decimal('win_rate_pct', 16, 8)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['user_id', 'executed_at'], 'sbr_user_exec_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_backtest_runs');
    }
};
