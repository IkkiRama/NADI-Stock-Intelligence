<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\PriceReturnService;
use PHPUnit\Framework\TestCase;

class PriceReturnServiceTest extends TestCase
{
    public function test_period_returns_and_insufficient_history(): void
    {
        $service = new PriceReturnService();

        $closes = [
            '2025-12-30' => '100.000000',
            '2026-01-15' => '105.000000',
            '2026-02-14' => '110.000000',
            '2026-02-15' => '121.000000',
        ];

        $this->assertSame('0.10000000', $service->periodReturn($closes, '1D'));
        $this->assertSame('0.21000000', $service->periodReturn($closes, 'YTD'));
        $this->assertSame('0.15238095', $service->periodReturn($closes, '1M'));
        $this->assertNull($service->periodReturn($closes, '1Y'));
        $this->assertNull($service->periodReturn(['2026-02-15' => '100'], '1D'));
    }

    public function test_high_low_and_avg_volume(): void
    {
        $service = new PriceReturnService();

        $highs = ['2026-02-13' => '112.50', '2026-02-14' => '118.00', '2026-02-15' => '115.00'];
        $lows = ['2026-02-13' => '98.00', '2026-02-14' => '101.00', '2026-02-15' => '99.50'];

        $this->assertSame('118.00', $service->high52w($highs));
        $this->assertSame('98.00', $service->low52w($lows));
        $this->assertSame('200.00', $service->avgVolume([100, 200, 300], 20));
    }
}
