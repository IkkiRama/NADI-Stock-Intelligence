<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_configs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->json('thresholds');
            $table->string('version', 24)->default('v1.0');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('instrument_regulatory_statuses', function (Blueprint $table) {
            $table->foreignId('instrument_id')->primary()->constrained('instruments')->cascadeOnDelete();
            $table->boolean('is_suspended')->default(false);
            $table->boolean('has_exchange_warning')->default(false);
            $table->boolean('has_special_monitoring')->default(false);
            $table->decimal('free_float_pct', 6, 2)->nullable();
            $table->decimal('top_holder_pct', 6, 2)->nullable();
            $table->string('status_notes', 255)->nullable();
            $table->string('source_note', 120)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('stock_safety_assessments', function (Blueprint $table) {
            $table->foreignId('instrument_id')->primary()->constrained('instruments')->cascadeOnDelete();
            $table->date('as_of_date');
            $table->decimal('risk_score', 6, 2)->nullable();
            $table->string('risk_category', 24);
            $table->boolean('safeguard_triggered')->default(false);
            $table->json('triggered_rules');
            $table->decimal('data_confidence', 5, 2)->default(0);
            $table->json('missing_factors');
            $table->string('calculation_version', 24)->default('v1.0');
            $table->boolean('is_demo')->default(false);
            $table->timestamp('calculated_at');

            $table->index(['risk_category', 'safeguard_triggered'], 'ssa_cat_safeguard_idx');
            $table->index(['risk_score'], 'ssa_risk_score_idx');
        });

        Schema::create('stock_safety_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained('instruments')->cascadeOnDelete();
            $table->date('assessment_date');
            $table->decimal('risk_score', 6, 2)->nullable();
            $table->string('risk_category', 24);
            $table->boolean('safeguard_triggered')->default(false);
            $table->unsignedSmallInteger('triggered_count')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['instrument_id', 'assessment_date'], 'ssh_inst_date_unique');
        });

        Schema::create('trading_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained('instruments')->cascadeOnDelete();
            $table->string('horizon', 16);
            $table->date('signal_date');
            $table->boolean('is_latest')->default(true);
            $table->string('stance', 28);
            $table->decimal('entry_low', 20, 6)->nullable();
            $table->decimal('entry_high', 20, 6)->nullable();
            $table->decimal('confirmation_price', 20, 6)->nullable();
            $table->decimal('stop_loss', 20, 6)->nullable();
            $table->decimal('invalidation_price', 20, 6)->nullable();
            $table->decimal('target_1', 20, 6)->nullable();
            $table->decimal('target_2', 20, 6)->nullable();
            $table->decimal('trailing_stop_atr_mult', 8, 2)->nullable();
            $table->decimal('support_level', 20, 6)->nullable();
            $table->decimal('resistance_level', 20, 6)->nullable();
            $table->decimal('gross_rr', 10, 4)->nullable();
            $table->decimal('net_rr', 10, 4)->nullable();
            $table->json('entry_stages')->nullable();
            $table->json('rationale');
            $table->string('invalidation_condition', 255)->nullable();
            $table->boolean('safeguard_suppressed')->default(false);
            $table->string('calculation_version', 24)->default('v1.0');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->unique(['instrument_id', 'horizon', 'signal_date'], 'ts_inst_hor_date_unique');
            $table->index(['horizon', 'is_latest', 'stance'], 'ts_hor_latest_stance_idx');
        });

        Schema::create('user_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained('instruments')->cascadeOnDelete();
            $table->string('alert_type', 32);
            $table->decimal('threshold_value', 20, 6)->nullable();
            $table->string('horizon', 16)->nullable();
            $table->string('notes', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active'], 'ua_user_active_idx');
        });

        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_alert_id')->constrained('user_alerts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained('instruments')->cascadeOnDelete();
            $table->date('triggered_date');
            $table->string('message', 255);
            $table->json('context')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_alert_id', 'triggered_date'], 'ae_alert_date_unique');
            $table->index(['user_id', 'read_at'], 'ae_user_read_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('user_alerts');
        Schema::dropIfExists('trading_signals');
        Schema::dropIfExists('stock_safety_history');
        Schema::dropIfExists('stock_safety_assessments');
        Schema::dropIfExists('instrument_regulatory_statuses');
        Schema::dropIfExists('safety_configs');
    }
};
