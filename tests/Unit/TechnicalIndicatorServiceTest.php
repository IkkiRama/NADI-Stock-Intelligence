<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\TechnicalIndicatorService;
use PHPUnit\Framework\TestCase;

class TechnicalIndicatorServiceTest extends TestCase
{
    public function test_computes_sma_ema_rsi_macd_bollinger_atr_obv_and_volatility(): void
    {
        $service = new TechnicalIndicatorService();

        // 40-day monotonically rising series (+1 per day) -> RSI must be 100, OBV rising, SMA/EMA deterministic
        $bars = [];
        for ($i = 0; $i < 40; $i++) {
            $close = 100.0 + $i;
            $bars[] = [
                'date' => sprintf('2026-01-%02d', ($i % 28) + 1),
                'open' => (string) ($close - 0.5),
                'high' => (string) ($close + 1.0),
                'low' => (string) ($close - 1.0),
                'close' => (string) $close,
                'volume' => 1000,
            ];
        }

        $snap = $service->latestSnapshot($bars);
        $this->assertNotNull($snap);

        // Last 20 closes are 120..139 -> mean = 129.5
        $this->assertSame('129.500000', $snap['sma_20']);
        $this->assertNull($snap['sma_50']);
        $this->assertSame('100.0000', $snap['rsi_14']);
        $this->assertSame(39000, $snap['obv']);
        $this->assertNotNull($snap['macd_line']);
        $this->assertNotNull($snap['macd_signal']);
        $this->assertNotNull($snap['atr_14']);
        $this->assertNotNull($snap['bb_upper']);
        $this->assertNotNull($snap['volatility_20d']);

        // Exact SMA(3) & OBV check
        $sma = $service->sma([10.0, 20.0, 30.0, 40.0], 3);
        $this->assertSame([null, null, 20.0, 30.0], $sma);

        $obv = $service->obv([10.0, 12.0, 11.0, 11.0], [100, 250, 100, 500]);
        $this->assertSame([0, 250, 150, 150], $obv);
    }
}
