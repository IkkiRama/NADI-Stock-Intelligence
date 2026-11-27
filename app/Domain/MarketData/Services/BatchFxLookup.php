<?php

namespace App\Domain\MarketData\Services;

use App\Models\FxRateDaily;
use App\Support\Math\Dec;

/**
 * In-memory historical FX rate resolver preloaded in one query per request
 * to eliminate N+1 database queries during daily portfolio & benchmark replays.
 */
final class BatchFxLookup
{
    /** @var array<string, list<array{date: string, rate: string}>> */
    private array $map = [];

    private function __construct()
    {
        $rows = FxRateDaily::query()
            ->orderBy('rate_date')
            ->get(['base_currency', 'quote_currency', 'rate_date', 'rate']);

        foreach ($rows as $r) {
            $key = strtoupper($r->base_currency).':'.strtoupper($r->quote_currency);
            $this->map[$key][] = [
                'date' => $r->rate_date->toDateString(),
                'rate' => (string) $r->rate,
            ];
        }
    }

    public static function load(): self
    {
        return new self();
    }

    public function resolve(string $from, string $to, string $date): ?string
    {
        $src = strtoupper(trim($from));
        $dst = strtoupper(trim($to));

        if ($src === '' || $dst === '') {
            return null;
        }

        if ($src === $dst) {
            return '1.00000000';
        }

        $direct = $this->findPairAsOf("{$src}:{$dst}", $date);
        if ($direct !== null && Dec::cmp($direct, '0') > 0) {
            return $direct;
        }

        $inv = $this->findPairAsOf("{$dst}:{$src}", $date);
        if ($inv !== null && Dec::cmp($inv, '0') > 0) {
            return Dec::div('1', $inv, 8);
        }

        if ($src !== 'IDR' && $dst !== 'IDR') {
            $fIdr = $this->resolve($src, 'IDR', $date);
            $tIdr = $this->resolve($dst, 'IDR', $date);
            if ($fIdr !== null && $tIdr !== null && Dec::cmp($tIdr, '0') > 0) {
                return Dec::div($fIdr, $tIdr, 8);
            }
        }

        return null;
    }

    private function findPairAsOf(string $key, string $date): ?string
    {
        $entries = $this->map[$key] ?? null;
        if (empty($entries)) {
            return null;
        }

        $chosen = null;
        foreach ($entries as $e) {
            if ($e['date'] <= $date) {
                $chosen = $e['rate'];
            } else {
                break;
            }
        }

        return $chosen ?? $entries[0]['rate'];
    }
}
