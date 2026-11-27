<?php

namespace Tests\Unit;

use App\Domain\Instruments\Support\TickSize;
use App\Domain\MarketData\Providers\DemoMarketDataProvider;
use App\Models\Instrument;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\MarketUniverseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoMarketDataProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_bars_are_deterministic_ohlc_valid_and_tick_aligned(): void
    {
        $this->seed([ExchangeSeeder::class, MarketUniverseSeeder::class]);

        $provider = new DemoMarketDataProvider();
        $bbca = Instrument::query()->with('exchange')->where('symbol', 'BBCA')->firstOrFail();

        $from = CarbonImmutable::parse('2026-10-01', 'UTC');
        $to = CarbonImmutable::parse('2026-10-30', 'UTC');

        $run1 = $provider->dailyBars($bbca, $from, $to);
        $run2 = $provider->dailyBars($bbca, $from, $to);

        $this->assertSame($run1, $run2);
        $this->assertNotEmpty($run1);

        foreach ($run1 as $bar) {
            $this->assertGreaterThanOrEqual(0, Dec::cmp($bar['high'], $bar['open']));
            $this->assertGreaterThanOrEqual(0, Dec::cmp($bar['high'], $bar['close']));
            $this->assertLessThanOrEqual(0, Dec::cmp($bar['low'], $bar['open']));
            $this->assertLessThanOrEqual(0, Dec::cmp($bar['low'], $bar['close']));

            $roundedClose = TickSize::roundToTick('IDX', $bar['close']);
            $this->assertSame($roundedClose, $bar['close']);
        }
    }
}
