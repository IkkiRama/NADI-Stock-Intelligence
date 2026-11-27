<?php

namespace Tests\Unit;

use App\Domain\TradingSignals\Services\PositionSizingCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class PositionSizingCalculatorTest extends TestCase
{
    public function test_lot_rounding_down_fees_slippage_and_capital_cap(): void
    {
        $calc = new PositionSizingCalculator();

        // Capital 100,000,000 IDR, max risk 1% = 1,000,000 IDR
        // Entry 10,000, Stop 9,500, Target 11,200, IDX lot 100
        $res = $calc->calculate(
            capital: '100000000',
            maxRiskPct: '1.0',
            entryPrice: '10000',
            stopLoss: '9500',
            targetPrice: '11200',
            lotSize: 100,
            buyFeePct: '0.0015',
            sellFeePct: '0.0025',
            slippagePct: '0.0010',
        );

        // Risk per share with slippage & fees > 500 -> floor(1,000,000 / (~559.0) / 100) = 17 lots = 1,700 shares
        $this->assertSame(17, $res['lots']);
        $this->assertSame(1700, $res['shares']);
        $this->assertFalse($res['capped_by_capital']);
        $this->assertLessThanOrEqual(1.0, (float) $res['actual_risk_pct']);
        $this->assertNotNull($res['net_rr']);
        $this->assertGreaterThan(1.5, (float) $res['net_rr']);

        // Tight stop with tiny capital forces affordability cap
        $capped = $calc->calculate(
            capital: '2500000',
            maxRiskPct: '10.0',
            entryPrice: '10000',
            stopLoss: '9900',
            targetPrice: null,
            lotSize: 100,
            buyFeePct: '0.0015',
            sellFeePct: '0.0025',
            slippagePct: '0.0010',
        );

        $this->assertTrue($capped['capped_by_capital']);
        $this->assertSame(2, $capped['lots']);
        $this->assertSame(200, $capped['shares']);
        $this->assertLessThanOrEqual(2500000.0, (float) $capped['total_capital_required']);
    }

    public function test_stop_above_entry_throws_invalid_argument_exception(): void
    {
        $calc = new PositionSizingCalculator();

        $this->expectException(InvalidArgumentException::class);
        $calc->calculate('10000000', '1', '5000', '5100', null, 100);
    }
}
