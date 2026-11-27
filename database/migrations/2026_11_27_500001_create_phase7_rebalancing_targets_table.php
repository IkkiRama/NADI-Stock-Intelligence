<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->json('risk_rebalance_prefs')->nullable()->after('concentration_thresholds');
        });

        Schema::create('portfolio_rebalance_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('portfolio_id')->constrained('portfolios')->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained('instruments')->cascadeOnDelete();
            $table->decimal('target_weight_pct', 6, 2);
            $table->string('notes', 160)->nullable();
            $table->timestamps();

            $table->unique(['portfolio_id', 'instrument_id'], 'prt_pf_inst_unique');
            $table->index(['user_id', 'portfolio_id'], 'prt_user_pf_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_rebalance_targets');
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn('risk_rebalance_prefs');
        });
    }
};
