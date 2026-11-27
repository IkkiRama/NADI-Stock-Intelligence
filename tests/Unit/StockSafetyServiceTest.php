<?php

namespace Tests\Unit;

use App\Domain\MarketData\Services\FxConversionService;
use App\Domain\StockSafety\Services\StockSafetyService;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\InstrumentRegulatoryStatus;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockSafetyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_insufficient_history_returns_insufficient_data_without_fabricating_score(): void
    {
        $this->seed(ExchangeSeeder::class);
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();

        $inst = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'NEWX',
            'name' => 'New Listing Tbk',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        $svc = new StockSafetyService(new FxConversionService());
        $bars = [];
        for ($i = 0; $i < 10; $i++) {
            $bars[] = [
                'date' => '2026-11-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'open' => '100',
                'high' => '102',
                'low' => '99',
                'close' => '100',
                'volume' => 10000,
            ];
        }

        $res = $svc->assess($inst, $bars, null, null, null, '2026-11-10');

        $this->assertNull($res['risk_score']);
        $this->assertSame('insufficient_data', $res['risk_category']);
        $this->assertFalse($res['safeguard_triggered']);
        $this->assertContains('price_history_25d', $res['missing_factors']);
    }

    public function test_anomaly_spike_fundamental_divergence_and_regulatory_safeguard_trigger_without_defamatory_claims(): void
    {
        $this->seed(ExchangeSeeder::class);
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();

        $inst = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'SPIK',
            'name' => 'Spike Volatile Tbk',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        $bars = [];
        for ($i = 0; $i < 25; $i++) {
            $close = 100.0;
            $vol = 1000;
            if ($i >= 20) {
                // +45% rally in final 5 bars with 6x volume and large upper wick
                $close = 100.0 + ($i - 19) * 9.0;
                $vol = 6000;
            }
            $bars[] = [
                'date' => date('Y-m-d', strtotime("2026-10-01 +{$i} days")),
                'open' => number_format($close * ($i === 24 ? 1.08 : 0.99), 2, '.', ''),
                'high' => number_format($close * ($i === 24 ? 1.12 : 1.01), 2, '.', ''),
                'low' => number_format($close * 0.97, 2, '.', ''),
                'close' => number_format($close, 2, '.', ''),
                'volume' => $vol,
                'value_traded' => number_format($close * $vol, 2, '.', ''),
            ];
        }

        $reg = new InstrumentRegulatoryStatus([
            'instrument_id' => $inst->id,
            'is_suspended' => true,
            'has_exchange_warning' => true,
            'has_special_monitoring' => true,
            'free_float_pct' => '6.50',
            'status_notes' => 'Suspensi sementara karena volatilitas kumulatif.',
        ]);

        $svc = new StockSafetyService(new FxConversionService());
        $res = $svc->assess(
            $inst,
            $bars,
            ['eps' => '-15.000000', 'per' => null, 'is_demo' => false],
            ['volatility_20d' => '0.85'],
            $reg,
            $bars[24]['date'],
        );

        $this->assertTrue($res['safeguard_triggered']);
        $this->assertSame('high', $res['risk_category']);
        $this->assertGreaterThanOrEqual(70.0, (float) $res['risk_score']);

        $codes = array_column($res['triggered_rules'], 'code');
        $this->assertContains('suspended', $codes);
        $this->assertContains('price_surge_5d', $codes);
        $this->assertContains('volume_spike', $codes);
        $this->assertContains('fundamental_divergence', $codes);

        // Verify neutral statistical wording (never defamatory claims of manipulation)
        $serialized = strtolower(json_encode($res['triggered_rules']));
        $this->assertStringNotContainsString('manipul', $serialized);
        $this->assertStringNotContainsString('fraud', $serialized);
    }
}
