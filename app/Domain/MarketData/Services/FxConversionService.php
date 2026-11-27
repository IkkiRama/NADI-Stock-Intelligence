<?php

namespace App\Domain\MarketData\Services;

use App\Models\FxRateDaily;
use App\Support\Math\Dec;

final class FxConversionService
{
    public function rate(string $from, string $to, ?string $asOfDate = null): ?string
    {
        $base = strtoupper(trim($from));
        $quote = strtoupper(trim($to));

        if ($base === '' || $quote === '') {
            return null;
        }

        if ($base === $quote) {
            return '1.00000000';
        }

        $direct = $this->lookupPair($base, $quote, $asOfDate);
        if ($direct !== null && Dec::cmp($direct, '0') > 0) {
            return Dec::round($direct, 8);
        }

        $inverse = $this->lookupPair($quote, $base, $asOfDate);
        if ($inverse !== null && Dec::cmp($inverse, '0') > 0) {
            return Dec::div('1', $inverse, 8);
        }

        if ($base !== 'IDR' && $quote !== 'IDR') {
            $baseToIdr = $this->rate($base, 'IDR', $asOfDate);
            $quoteToIdr = $this->rate($quote, 'IDR', $asOfDate);

            if ($baseToIdr !== null && $quoteToIdr !== null && Dec::cmp($quoteToIdr, '0') > 0) {
                return Dec::div($baseToIdr, $quoteToIdr, 8);
            }
        }

        return null;
    }

    public function convert(string $amount, string $from, string $to, ?string $asOfDate = null, int $scale = 4): ?string
    {
        $fx = $this->rate($from, $to, $asOfDate);
        if ($fx === null) {
            return null;
        }

        return Dec::mul($amount, $fx, $scale);
    }

    private function lookupPair(string $base, string $quote, ?string $asOfDate): ?string
    {
        $query = FxRateDaily::query()
            ->where('base_currency', $base)
            ->where('quote_currency', $quote);

        if ($asOfDate !== null) {
            $query->whereDate('rate_date', '<=', $asOfDate);
        }

        $row = $query->orderByDesc('rate_date')->first();

        return $row ? (string) $row->rate : null;
    }
}
