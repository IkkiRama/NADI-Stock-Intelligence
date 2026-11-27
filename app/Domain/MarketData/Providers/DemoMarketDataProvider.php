<?php

namespace App\Domain\MarketData\Providers;

use App\Domain\Instruments\Support\TickSize;
use App\Domain\MarketData\Contracts\MarketDataProvider;
use App\Domain\MarketData\Support\SeededRandom;
use App\Models\Instrument;
use App\Models\StockIndex;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class DemoMarketDataProvider implements MarketDataProvider
{
    public function code(): string
    {
        return 'demo';
    }

    public function supports(string $dataset, string $exchangeCode): bool
    {
        return in_array($dataset, ['prices_daily', 'index_values_daily'], true);
    }

    /**
     * Deterministic geometric random walk for demonstration only.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function dailyBars(Instrument|StockIndex $entity, CarbonInterface $from, CarbonInterface $to): iterable
    {
        $isIndex = $entity instanceof StockIndex;
        $exchangeCode = $isIndex
            ? ($entity->exchange?->code ?? 'IDX')
            : ($entity->exchange?->code ?? 'IDX');

        $seedKey = $isIndex
            ? 'INDEX:'.$entity->code
            : $exchangeCode.'.'.$entity->symbol;

        $rng = SeededRandom::fromKey($seedKey);

        $basePrice = $this->resolveBasePrice($entity);
        $baseVolume = $this->resolveBaseVolume($entity);
        $dailySigma = $isIndex ? 0.009 : (0.015 + ($rng->nextFloat() * 0.010));
        $dailyDrift = 0.00025;

        $prevClose = (float) $basePrice;
        $cursor = CarbonImmutable::instance($from)->startOfDay();
        $end = CarbonImmutable::instance($to)->startOfDay();

        $bars = [];
        while ($cursor->lessThanOrEqualTo($end)) {
            if (! $cursor->isWeekend()) {
                $ret = $dailyDrift + $dailySigma * $rng->nextNormal();
                $ret = max(-0.18, min(0.18, $ret));
                $rawClose = max(0.01, $prevClose * exp($ret));

                $gap = ($dailySigma * 0.35) * $rng->nextNormal();
                $rawOpen = max(0.01, $prevClose * (1.0 + $gap));

                $intradaySpan = abs($dailySigma * (0.6 + abs($rng->nextNormal()) * 0.7));
                $rawHigh = max($rawOpen, $rawClose) * (1.0 + $intradaySpan * 0.5);
                $rawLow = max(0.01, min($rawOpen, $rawClose) * (1.0 - $intradaySpan * 0.5));

                if ($isIndex) {
                    $open = Dec::round((string) $rawOpen, 2);
                    $close = Dec::round((string) $rawClose, 2);
                    $high = Dec::max(Dec::round((string) $rawHigh, 2), Dec::max($open, $close));
                    $low = Dec::min(Dec::round((string) $rawLow, 2), Dec::min($open, $close));
                } else {
                    $open = TickSize::roundToTick($exchangeCode, number_format($rawOpen, 6, '.', ''));
                    $close = TickSize::roundToTick($exchangeCode, number_format($rawClose, 6, '.', ''));
                    $highCandidate = TickSize::roundToTick($exchangeCode, number_format($rawHigh, 6, '.', ''));
                    $lowCandidate = TickSize::roundToTick($exchangeCode, number_format($rawLow, 6, '.', ''));

                    $high = Dec::max($highCandidate, Dec::max($open, $close));
                    $low = Dec::min($lowCandidate, Dec::min($open, $close));
                }

                // Lognormal volume with slight positive correlation to absolute return
                $volShock = exp(0.45 * $rng->nextNormal() + min(1.0, abs($ret) * 18.0));
                $volume = max(1000, (int) round($baseVolume * $volShock));
                $valueTraded = $isIndex
                    ? null
                    : Dec::mul($close, (string) $volume, 4);

                $bars[] = [
                    'trade_date' => $cursor->toDateString(),
                    'open' => Dec::round($open, 6),
                    'high' => Dec::round($high, 6),
                    'low' => Dec::round($low, 6),
                    'close' => Dec::round($close, 6),
                    'volume' => $volume,
                    'value_traded' => $valueTraded,
                ];

                $prevClose = (float) $close;
            }

            $cursor = $cursor->addDay();
        }

        return $bars;
    }

    private function resolveBasePrice(Instrument|StockIndex $entity): string
    {
        if ($entity instanceof StockIndex) {
            return match ($entity->code) {
                'IHSG' => '7250.00',
                'LQ45' => '945.00',
                'SPX' => '5450.00',
                'NDX' => '19200.00',
                'DJI' => '41200.00',
                'STI' => '3480.00',
                'CSI300' => '3680.00',
                'HSI' => '18400.00',
                default => '1000.00',
            };
        }

        $meta = $entity->metadata ?? [];
        if (isset($meta['demo_base_price'])) {
            return (string) $meta['demo_base_price'];
        }

        return match ($entity->currency) {
            'IDR' => '3500',
            'USD' => '150',
            'SGD' => '12',
            'CNY' => '45',
            'HKD' => '80',
            default => '100',
        };
    }

    private function resolveBaseVolume(Instrument|StockIndex $entity): int
    {
        if ($entity instanceof StockIndex) {
            return 250000000;
        }

        $meta = $entity->metadata ?? [];
        if (isset($meta['demo_base_volume'])) {
            return (int) $meta['demo_base_volume'];
        }

        return match ($entity->currency) {
            'IDR' => 18000000,
            'USD' => 12000000,
            default => 4500000,
        };
    }
}
