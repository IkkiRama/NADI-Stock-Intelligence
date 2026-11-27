<?php

namespace App\Domain\MarketData\Support;

/**
 * Deterministic xorshift32 PRNG isolated per instance (never touches global mt_rand).
 */
final class SeededRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0x7FFFFFFF) ?: 0x6D2B79F5;
    }

    public static function fromKey(string $key): self
    {
        $crc = crc32($key);

        return new self($crc === 0 ? 123456789 : $crc);
    }

    public function nextFloat(): float
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0x7FFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & 0x7FFFFFFF;
        $this->state = $x ?: 1;

        return ($this->state & 0x7FFFFFFF) / 0x7FFFFFFF;
    }

    /**
     * Standard normal deviate via Box-Muller transform.
     */
    public function nextNormal(): float
    {
        $u1 = max(1e-9, $this->nextFloat());
        $u2 = $this->nextFloat();

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }
}
