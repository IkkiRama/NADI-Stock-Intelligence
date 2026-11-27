<?php

namespace Tests\Unit;

use App\Domain\Instruments\Support\TickSize;
use App\Domain\TradingSignals\Services\SmartEntryExitService;
use App\Models\Exchange;
use App\Models\Instrument;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartEntryExitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_actionable_setup_respects_tick_size_and_safeguard_suppression(): void
    {
        $this->seed(ExchangeSeeder::class);
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();

        $inst = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'QUAL',
            'name' => 'Quality Bluechip Tbk',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        // 60 steady uptrending bars above 5000 IDR (IDX tick = 25)
        $bars = [];
        for ($i = 0; $i < 60; $i++) {
            $close = 8000 + ($i * 25);
            $bars[] = [
                'date' => date('Y-m-d', strtotime("2026-09-01 +{$i} days")),
                'open' => (string) ($close - 25),
                'high' => (string) ($close + 50),
                'low' => (string) ($close - 50),
                'close' => (string) $close,
                'volume' => 1000000,
            ];
        }

        $svc = new SmartEntryExitService();
        $technicals = [
            'sma_20' => '9300',
            'sma_50' => '8800',
            'sma_200' => '8200',
            'ema_20' => '9350',
            'rsi_14' => '58.00',
            'atr_14' => '150.00',
        ];
        $fundamentals = ['eps' => '450.00'];
        $score = ['overall_score' => '75.00'];

        // 1. Clean Stock Safety -> actionable/watchlist setup with IDX tick alignment (multiples of 25)
        $cleanPlans = $svc->evaluateAllHorizons(
            $inst,
            'IDX',
            $bars,
            $fundamentals,
            $technicals,
            $score,
            ['safeguard_triggered' => false, 'triggered_rules' => []],
            $bars[59]['date'],
        );

        $swing = $cleanPlans['swing'];
        $this->assertContains($swing['stance'], ['actionable_setup', 'watchlist_setup']);
        $this->assertFalse($swing['safeguard_suppressed']);
        $this->assertNotNull($swing['entry_low']);
        $this->assertNotNull($swing['stop_loss']);
        $this->assertNotNull($swing['target_1']);
        $this->assertGreaterThan((float) $swing['stop_loss'], (float) $swing['entry_low']);
        $this->assertSame($swing['entry_low'], TickSize::roundToTick('IDX', (string) $swing['entry_low']));
        $this->assertSame($swing['stop_loss'], TickSize::roundToTick('IDX', (string) $swing['stop_loss']));
        $this->assertSame($swing['target_1'], TickSize::roundToTick('IDX', (string) $swing['target_1']));

        // 2. Downtrend / failing technicals -> never forces a BUY setup
        $downtrendPlans = $svc->evaluateAllHorizons(
            $inst,
            'IDX',
            $bars,
            $fundamentals,
            [
                'sma_20' => '12000',
                'sma_50' => '12500',
                'sma_200' => '13000',
                'ema_20' => '11800',
                'rsi_14' => '28.00',
                'atr_14' => '150.00',
            ],
            $score,
            ['safeguard_triggered' => false, 'triggered_rules' => []],
            $bars[59]['date'],
        );
        $this->assertSame('no_setup', $downtrendPlans['swing']['stance']);
        $this->assertNull($downtrendPlans['swing']['entry_low']);

        // 3. Safeguard triggered -> forces suppressed_by_safety
        $suppressedPlans = $svc->evaluateAllHorizons(
            $inst,
            'IDX',
            $bars,
            $fundamentals,
            $technicals,
            $score,
            [
                'safeguard_triggered' => true,
                'triggered_rules' => [['code' => 'special_monitoring', 'title' => 'Pemantauan Khusus Bursa']],
            ],
            $bars[59]['date'],
        );
        $this->assertSame('suppressed_by_safety', $suppressedPlans['swing']['stance']);
        $this->assertTrue($suppressedPlans['swing']['safeguard_suppressed']);
        $this->assertNull($suppressedPlans['swing']['entry_low']);
        $this->assertNull($suppressedPlans['swing']['target_1']);
    }
}
