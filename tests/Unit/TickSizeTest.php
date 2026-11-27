<?php

namespace Tests\Unit;

use App\Domain\Instruments\Support\TickSize;
use PHPUnit\Framework\TestCase;

class TickSizeTest extends TestCase
{
    public function test_idx_tick_bands(): void
    {
        $this->assertSame('1', TickSize::forPrice('IDX', '199'));
        $this->assertSame('2', TickSize::forPrice('IDX', '200'));
        $this->assertSame('5', TickSize::forPrice('IDX', '500'));
        $this->assertSame('10', TickSize::forPrice('IDX', '4990'));
        $this->assertSame('25', TickSize::forPrice('IDX', '5000'));
    }

    public function test_other_exchange_tick_rules(): void
    {
        $this->assertSame('0.02', TickSize::forPrice('HKEX', '15'));
        $this->assertSame('0.01', TickSize::forPrice('NYSE', '123.45'));
        $this->assertSame('0.01', TickSize::forPrice('NASDAQ', '123.45'));
        $this->assertSame('0.01', TickSize::forPrice('SSE', '50.00'));
        $this->assertSame('0.005', TickSize::forPrice('SGX', '0.50'));
        $this->assertSame('0.01', TickSize::forPrice('SGX', '2.00'));
    }

    public function test_round_to_tick(): void
    {
        $this->assertSame('9825.000000', TickSize::roundToTick('IDX', '9818'));
        $this->assertSame('5000.000000', TickSize::roundToTick('IDX', '4998'));
        $this->assertSame('15.020000', TickSize::roundToTick('HKEX', '15.018'));
    }
}
