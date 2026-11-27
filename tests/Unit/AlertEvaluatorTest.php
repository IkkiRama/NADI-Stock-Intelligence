<?php

namespace Tests\Unit;

use App\Domain\Notifications\Services\AlertEvaluator;
use App\Models\AlertEvent;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\InstrumentQuoteLatest;
use App\Models\StockSafetyAssessment;
use App\Models\TradingSignal;
use App\Models\User;
use App\Models\UserAlert;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluates_alerts_and_prevents_duplicate_events_on_same_date(): void
    {
        $this->seed([RoleSeeder::class, ExchangeSeeder::class, DataSourceSeeder::class]);

        $user = User::factory()->create();
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $source = DataSource::query()->where('code', 'demo')->firstOrFail();

        $inst = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'ALRT',
            'name' => 'Alert Test Tbk',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        InstrumentQuoteLatest::query()->create([
            'instrument_id' => $inst->id,
            'trade_date' => '2026-11-26',
            'close' => '5200.000000',
            'volume' => 100000,
            'data_source_id' => $source->id,
            'refreshed_at' => now('UTC'),
        ]);

        StockSafetyAssessment::query()->create([
            'instrument_id' => $inst->id,
            'as_of_date' => '2026-11-26',
            'risk_score' => '72.50',
            'risk_category' => 'high',
            'safeguard_triggered' => true,
            'triggered_rules' => [],
            'data_confidence' => '65.00',
            'missing_factors' => [],
            'calculated_at' => now('UTC'),
        ]);

        TradingSignal::query()->create([
            'instrument_id' => $inst->id,
            'horizon' => 'swing',
            'signal_date' => '2026-11-26',
            'is_latest' => true,
            'stance' => 'actionable_setup',
            'entry_low' => '5100.000000',
            'net_rr' => '2.1000',
            'rationale' => [],
            'safeguard_suppressed' => false,
        ]);

        UserAlert::query()->create([
            'user_id' => $user->id,
            'instrument_id' => $inst->id,
            'alert_type' => 'price_above',
            'threshold_value' => '5000.000000',
            'is_active' => true,
        ]);
        UserAlert::query()->create([
            'user_id' => $user->id,
            'instrument_id' => $inst->id,
            'alert_type' => 'safety_risk_above',
            'threshold_value' => '65.000000',
            'is_active' => true,
        ]);
        UserAlert::query()->create([
            'user_id' => $user->id,
            'instrument_id' => $inst->id,
            'alert_type' => 'signal_actionable',
            'horizon' => 'swing',
            'is_active' => true,
        ]);

        $evaluator = new AlertEvaluator();
        $firstRun = $evaluator->evaluateAll();
        $this->assertSame(3, $firstRun);
        $this->assertSame(3, AlertEvent::query()->where('user_id', $user->id)->count());

        // Second evaluation on same date is idempotent
        $secondRun = $evaluator->evaluateAll();
        $this->assertSame(0, $secondRun);
        $this->assertSame(3, AlertEvent::query()->where('user_id', $user->id)->count());
    }
}
