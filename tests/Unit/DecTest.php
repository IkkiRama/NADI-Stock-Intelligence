<?php

namespace Tests\Unit;

use App\Support\Math\Dec;
use PHPUnit\Framework\TestCase;

class DecTest extends TestCase
{
    public function test_arithmetic_and_division_by_zero(): void
    {
        $this->assertSame('150.7500', Dec::add('100.25', '50.50', 4));
        $this->assertSame('49.7500', Dec::sub('100.25', '50.50', 4));
        $this->assertSame('1005.00', Dec::mul('100.5', '10', 2));
        $this->assertSame('0.33333333', Dec::div('1', '3', 8));
        $this->assertNull(Dec::div('100', '0'));
        $this->assertNull(Dec::div('100', '0.000000'));
    }

    public function test_percentage_change_rounding_and_comparison(): void
    {
        $this->assertSame('0.10000000', Dec::pctChange('110', '100', 8));
        $this->assertNull(Dec::pctChange('110', '0', 8));
        $this->assertSame('2.35', Dec::round('2.345', 2));
        $this->assertSame(-1, Dec::cmp('99.99', '100'));
        $this->assertSame(0, Dec::cmp('100.00', '100'));
        $this->assertSame(1, Dec::cmp('100.01', '100'));
    }
}
