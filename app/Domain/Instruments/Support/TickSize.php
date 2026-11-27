<?php

namespace App\Domain\Instruments\Support;

use App\Support\Math\Dec;
use Brick\Math\RoundingMode;

/**
 * Exchange tick-size lookup and rounding helper.
 *
 * NOTE: Tick bands and spread tables are based on standard exchange schedules and
 * must be verified against current official exchange rules before live trading use.
 */
final class TickSize
{
    public static function forPrice(string $exchangeCode, string $price): string
    {
        $code = strtoupper($exchangeCode);
        $p = Dec::of($price);

        return match ($code) {
            'IDX' => match (true) {
                $p->isLessThan('200') => '1',
                $p->isLessThan('500') => '2',
                $p->isLessThan('2000') => '5',
                $p->isLessThan('5000') => '10',
                default => '25',
            },
            'SGX' => $p->isLessThan('1') ? '0.005' : '0.01',
            'HKEX' => match (true) {
                $p->isLessThanOrEqualTo('0.25') => '0.001',
                $p->isLessThanOrEqualTo('0.50') => '0.005',
                $p->isLessThanOrEqualTo('10.00') => '0.01',
                $p->isLessThanOrEqualTo('20.00') => '0.02',
                $p->isLessThanOrEqualTo('100.00') => '0.05',
                $p->isLessThanOrEqualTo('200.00') => '0.1',
                $p->isLessThanOrEqualTo('500.00') => '0.2',
                $p->isLessThanOrEqualTo('1000.00') => '0.5',
                $p->isLessThanOrEqualTo('2000.00') => '1',
                $p->isLessThanOrEqualTo('5000.00') => '2',
                default => '5',
            },
            'NYSE', 'NASDAQ', 'SSE', 'SZSE' => '0.01',
            default => '0.01',
        };
    }

    public static function roundToTick(string $exchangeCode, string $price, int $scale = 6): string
    {
        $tick = self::forPrice($exchangeCode, $price);
        $tickDec = Dec::of($tick);
        $priceDec = Dec::of($price);

        if ($priceDec->isLessThanOrEqualTo('0')) {
            return (string) $tickDec->toScale($scale, RoundingMode::HALF_UP);
        }

        $steps = $priceDec->dividedBy($tickDec, 0, RoundingMode::HALF_UP);
        if ($steps->isLessThan('1')) {
            $steps = Dec::of('1');
        }

        $rounded = $steps->multipliedBy($tickDec);
        // Re-check boundary if rounding pushed across a tier
        $verifiedTick = self::forPrice($exchangeCode, (string) $rounded);
        if ($verifiedTick !== $tick) {
            $vTickDec = Dec::of($verifiedTick);
            $rounded = $priceDec->dividedBy($vTickDec, 0, RoundingMode::HALF_UP)->multipliedBy($vTickDec);
            if ($rounded->isLessThan($vTickDec)) {
                $rounded = $vTickDec;
            }
        }

        return (string) $rounded->toScale($scale, RoundingMode::HALF_UP);
    }
}
