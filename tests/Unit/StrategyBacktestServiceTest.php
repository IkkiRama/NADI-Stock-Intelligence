<?php

namespace Tests\Unit;

use App\Domain\StrategyLab\Services\StrategyBacktestService;
use App\Models\Exchange;
use App\Models\Instrument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyBacktestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enforces_next_bar_open_execution_no_lookahead_and_idx_lot_rounding(): void
    {
        $exchange = new Exchange(['code' => 'IDX', 'name' => 'Indonesia Stock Exchange']);
        $instrument = new Instrument([
            'symbol' => 'BBCA',
            'name' => 'Bank Central Asia Tbk',
            'currency' => 'IDR',
            'lot_size' => 100,
        ]);
        $instrument->setRelation('exchange', $exchange);

        // Build 26 daily bars: downtrend 0..14 so SMA5 < SMA10, crossover at bar 15 close,
        // sharp open gap at bar 16 open (must execute at bar 16 open, NOT bar 15 close),
        // then breakdown at bar 22 close executing at bar 23 open.
        $bars = [];
        $start = CarbonImmutable::parse('2026-01-01', 'UTC');
        for ($i = 0; $i < 26; $i++) {
            $date = $start->addWeekdays($i)->toDateString();
            if ($i <= 14) {
                $px = 5000 - ($i * 20);
                $bars[] = ['date' => $date, 'open' => (string) $px, 'high' => (string) ($px + 25), 'low' => (string) ($px - 25), 'close' => (string) $px];
            } elseif ($i === 15) {
                // Crossover signal fires on bar 15 close (close=5600, open=4720)
                $bars[] = ['date' => $date, 'open' => '4720', 'high' => '5625', 'low' => '4700', 'close' => '5600'];
            } elseif ($i >= 16 && $i <= 21) {
                // Bar 16 gaps open to 6200 -> entry must happen on bar 16 at >= 6200, NOT bar 15 close (5600)
                $bars[] = ['date' => $date, 'open' => '6200', 'high' => '6300', 'low' => '6150', 'close' => '6250'];
            } else {
                // Sharp drop so SMA5 crosses back below SMA10 on bar 22 close and exits on bar 23 open
                $bars[] = ['date' => $date, 'open' => '4300', 'high' => '4350', 'low' => '4250', 'close' => '4275'];
            }
        }

        $service = app(StrategyBacktestService::class);
        $result = $service->simulateOnBars($instrument, $bars, [
            'strategy' => 'sma_crossover',
            'fast_period' => 5,
            'slow_period' => 10,
            'initial_capital' => '50000000',
            'allocation_pct' => '0.95',
            'buy_fee_pct' => '0.0015',
            'sell_fee_pct' => '0.0025',
            'slippage_pct' => '0.0010',
        ]);

        $this->assertNotEmpty($result['trades']);
        $first = $result['trades'][0];

        $this->assertSame($bars[15]['date'], $first['signal_date']);
        $this->assertSame($bars[16]['date'], $first['entry_date']);
        $this->assertGreaterThan($first['signal_date'], $first['entry_date']);
        $this->assertSame('6200.000000', $first['entry_price']); // 6200 * 1.001 = 6206.2 rounded to IDX tick 25 => 6200
        $this->assertSame(0, $first['shares'] % 100);
        $this->assertNotNull($result['summary']['cagr_pct']);
        $this->assertGreaterThan($first['entry_date'], $first['exit_date']);

        $csv = $service->exportBacktestCsv($result);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Strategy,sma_crossover', $csv);
        $this->assertStringContainsString($first['entry_date'], $csv);
    }

    public function test_same_bar_stop_loss_takes_precedence_over_take_profit(): void
    {
        $exchange = new Exchange(['code' => 'NASDAQ', 'name' => 'Nasdaq']);
        $instrument = new Instrument([
            'symbol' => 'MSFT',
            'name' => 'Microsoft Corp',
            'currency' => 'USD',
            'lot_size' => 1,
        ]);
        $instrument->setRelation('exchange', $exchange);

        $bars = [];
        $start = CarbonImmutable::parse('2026-02-02', 'UTC');
        for ($i = 0; $i < 16; $i++) {
            $date = $start->addWeekdays($i)->toDateString();
            if ($i < 10) {
                $px = 120.0 - $i;
                $bars[] = ['date' => $date, 'open' => (string) $px, 'high' => (string) ($px + 1), 'low' => (string) ($px - 1), 'close' => (string) $px];
            } elseif ($i === 10) {
                // Crossover buy signal
                $bars[] = ['date' => $date, 'open' => '110', 'high' => '145', 'low' => '109', 'close' => '145'];
            } elseif ($i === 11) {
                // Next-bar open entry around 140
                $bars[] = ['date' => $date, 'open' => '140', 'high' => '142', 'low' => '139', 'close' => '141'];
            } elseif ($i === 12) {
                // Wide volatile bar hitting BOTH 5% SL (~133) and 10% TP (~154) on the same bar
                $bars[] = ['date' => $date, 'open' => '141', 'high' => '160', 'low' => '125', 'close' => '155'];
            } else {
                $bars[] = ['date' => $date, 'open' => '155', 'high' => '156', 'low' => '154', 'close' => '155'];
            }
        }

        $service = app(StrategyBacktestService::class);
        $result = $service->simulateOnBars($instrument, $bars, [
            'strategy' => 'sma_crossover',
            'fast_period' => 5,
            'slow_period' => 10,
            'initial_capital' => '10000',
            'buy_fee_pct' => '0',
            'sell_fee_pct' => '0',
            'slippage_pct' => '0',
            'stop_loss_pct' => '0.05',
            'take_profit_pct' => '0.10',
        ]);

        $this->assertCount(1, $result['trades']);
        $this->assertSame('stop_loss', $result['trades'][0]['exit_reason']);
        $this->assertSame($bars[12]['date'], $result['trades'][0]['exit_date']);
        $this->assertSame('133.000000', $result['trades'][0]['exit_price']);
    }
}
