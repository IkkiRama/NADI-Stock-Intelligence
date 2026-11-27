<?php

namespace App\Support\Math;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Dec
{
    public static function of(string|int|float $value): BigDecimal
    {
        return BigDecimal::of((string) $value);
    }

    public static function add(string $a, string $b, ?int $scale = null, RoundingMode $mode = RoundingMode::HALF_UP): string
    {
        $res = self::of($a)->plus(self::of($b));

        return $scale === null ? (string) $res : (string) $res->toScale($scale, $mode);
    }

    public static function sub(string $a, string $b, ?int $scale = null, RoundingMode $mode = RoundingMode::HALF_UP): string
    {
        $res = self::of($a)->minus(self::of($b));

        return $scale === null ? (string) $res : (string) $res->toScale($scale, $mode);
    }

    public static function mul(string $a, string $b, ?int $scale = null, RoundingMode $mode = RoundingMode::HALF_UP): string
    {
        $res = self::of($a)->multipliedBy(self::of($b));

        return $scale === null ? (string) $res : (string) $res->toScale($scale, $mode);
    }

    public static function div(string $a, string $b, int $scale = 8, RoundingMode $mode = RoundingMode::HALF_UP): ?string
    {
        $divisor = self::of($b);
        if ($divisor->isZero()) {
            return null;
        }

        return (string) self::of($a)->dividedBy($divisor, $scale, $mode);
    }

    public static function pctChange(string $new, string $old, int $scale = 8, RoundingMode $mode = RoundingMode::HALF_UP): ?string
    {
        $oldDec = self::of($old);
        if ($oldDec->isZero()) {
            return null;
        }

        return (string) self::of($new)->minus($oldDec)->dividedBy($oldDec, $scale, $mode);
    }

    public static function round(string $value, int $scale = 2, RoundingMode $mode = RoundingMode::HALF_UP): string
    {
        return (string) self::of($value)->toScale($scale, $mode);
    }

    public static function cmp(string $a, string $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? (string) self::of($a) : (string) self::of($b);
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? (string) self::of($a) : (string) self::of($b);
    }
}
