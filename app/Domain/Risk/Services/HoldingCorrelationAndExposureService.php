<?php

namespace App\Domain\Risk\Services;

use App\Domain\MarketData\Services\BatchFxLookup;
use App\Models\InstrumentPriceDaily;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

/**
 * Pairwise Holding Correlation Matrix & Multi-Currency FX Shock Sensitivity Engine.
 */
final class HoldingCorrelationAndExposureService
{
    public const MIN_OVERLAP_SESSIONS = 20;

    public const MAX_HOLDINGS_IN_MATRIX = 12;

    /**
     * @param  array<string, mixed>  $valuationSummary
     * @return array<string, mixed>
     */
    public function analyze(
        array $valuationSummary,
        ?string $asOfDate = null,
        int $lookbackSessions = 90,
    ): array {
        $summary = $valuationSummary['summary'] ?? [];
        $targetCcy = strtoupper((string) ($summary['reporting_currency'] ?? 'IDR'));
        $holdings = $valuationSummary['consolidatedHoldings'] ?? [];
        $accounts = $valuationSummary['accountComparison'] ?? [];

        $endDate = $asOfDate ?: ($summary['as_of'] ?? now('UTC')->toDateString());
        $matrix = $this->computeCorrelationMatrix($holdings, $targetCcy, $endDate, $lookbackSessions);
        $currencyExposure = $this->computeCurrencyExposure($holdings, $accounts, $targetCcy, (string) ($summary['total_equity'] ?? '0'));

        return [
            'correlation' => $matrix,
            'currency_exposure' => $currencyExposure,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $holdings
     * @return array<string, mixed>
     */
    private function computeCorrelationMatrix(
        array $holdings,
        string $targetCcy,
        string $endDate,
        int $lookbackSessions,
    ): array {
        $topHoldings = array_slice($holdings, 0, self::MAX_HOLDINGS_IN_MATRIX);
        if (empty($topHoldings)) {
            return [
                'has_data' => false,
                'symbols' => [],
                'rows' => [],
                'average_pairwise' => null,
                'highest_pair' => null,
                'lowest_pair' => null,
                'min_overlap_required' => self::MIN_OVERLAP_SESSIONS,
            ];
        }

        $instIds = array_map(fn ($h) => (int) $h['instrument_id'], $topHoldings);
        $cutoffDate = CarbonImmutable::parse($endDate, 'UTC')
            ->subDays(max(60, (int) ceil($lookbackSessions * 1.7)))
            ->toDateString();

        $priceRows = InstrumentPriceDaily::query()
            ->whereIn('instrument_id', $instIds)
            ->whereDate('trade_date', '>=', $cutoffDate)
            ->whereDate('trade_date', '<=', $endDate)
            ->orderBy('trade_date')
            ->get(['instrument_id', 'trade_date', 'close']);

        $fxLookup = BatchFxLookup::load();
        $ccyByInst = [];
        foreach ($topHoldings as $h) {
            $ccyByInst[(int) $h['instrument_id']] = strtoupper((string) $h['currency']);
        }

        /** @var array<int, array<string, float>> $returnsByInst */
        $returnsByInst = [];
        $prevValByInst = [];

        foreach ($priceRows as $row) {
            $id = (int) $row->instrument_id;
            $d = $row->trade_date->toDateString();
            $fx = $fxLookup->resolve($ccyByInst[$id] ?? $targetCcy, $targetCcy, $d);
            if ($fx === null) {
                continue;
            }
            $pxRep = (float) $row->close * (float) $fx;
            if (isset($prevValByInst[$id]) && $prevValByInst[$id] > 0.0 && $pxRep > 0.0) {
                $returnsByInst[$id][$d] = ($pxRep / $prevValByInst[$id]) - 1.0;
            }
            if ($pxRep > 0.0) {
                $prevValByInst[$id] = $pxRep;
            }
        }

        foreach ($returnsByInst as $id => $series) {
            if (count($series) > $lookbackSessions) {
                $returnsByInst[$id] = array_slice($series, -$lookbackSessions, null, true);
            }
        }

        $symbols = array_map(fn ($h): array => [
            'instrument_id' => (int) $h['instrument_id'],
            'symbol' => (string) $h['symbol'],
            'currency' => (string) $h['currency'],
            'observations' => count($returnsByInst[(int) $h['instrument_id']] ?? []),
        ], $topHoldings);

        $rows = [];
        $pairValues = [];
        $highestPair = null;
        $lowestPair = null;

        $n = count($topHoldings);
        for ($i = 0; $i < $n; $i++) {
            $hi = $topHoldings[$i];
            $idI = (int) $hi['instrument_id'];
            $cells = [];

            for ($j = 0; $j < $n; $j++) {
                $hj = $topHoldings[$j];
                $idJ = (int) $hj['instrument_id'];

                if ($i === $j) {
                    $cells[] = [
                        'symbol' => (string) $hj['symbol'],
                        'correlation' => '1.0000',
                        'overlap' => count($returnsByInst[$idI] ?? []),
                    ];
                    continue;
                }

                [$corr, $overlap] = $this->pearsonOverlap(
                    $returnsByInst[$idI] ?? [],
                    $returnsByInst[$idJ] ?? [],
                );

                $cells[] = [
                    'symbol' => (string) $hj['symbol'],
                    'correlation' => $corr,
                    'overlap' => $overlap,
                ];

                if ($j > $i && $corr !== null) {
                    $val = (float) $corr;
                    $pairValues[] = $val;
                    $pairObj = [
                        'pair' => $hi['symbol'].' / '.$hj['symbol'],
                        'correlation' => $corr,
                        'overlap' => $overlap,
                    ];
                    if ($highestPair === null || $val > (float) $highestPair['correlation']) {
                        $highestPair = $pairObj;
                    }
                    if ($lowestPair === null || $val < (float) $lowestPair['correlation']) {
                        $lowestPair = $pairObj;
                    }
                }
            }

            $rows[] = [
                'instrument_id' => $idI,
                'symbol' => (string) $hi['symbol'],
                'cells' => $cells,
            ];
        }

        $avgPairwise = ! empty($pairValues)
            ? Dec::round(number_format(array_sum($pairValues) / count($pairValues), 8, '.', ''), 4)
            : null;

        return [
            'has_data' => $n >= 1,
            'symbols' => $symbols,
            'rows' => $rows,
            'average_pairwise' => $avgPairwise,
            'highest_pair' => $highestPair,
            'lowest_pair' => $lowestPair,
            'min_overlap_required' => self::MIN_OVERLAP_SESSIONS,
        ];
    }

    /**
     * @param  array<string, float>  $a
     * @param  array<string, float>  $b
     * @return array{0: ?string, 1: int}
     */
    private function pearsonOverlap(array $a, array $b): array
    {
        if (empty($a) || empty($b)) {
            return [null, 0];
        }

        $commonDates = array_intersect(array_keys($a), array_keys($b));
        $k = count($commonDates);
        if ($k < self::MIN_OVERLAP_SESSIONS) {
            return [null, $k];
        }

        $xs = [];
        $ys = [];
        foreach ($commonDates as $d) {
            $xs[] = $a[$d];
            $ys[] = $b[$d];
        }

        $meanX = array_sum($xs) / $k;
        $meanY = array_sum($ys) / $k;
        $cov = 0.0;
        $varX = 0.0;
        $varY = 0.0;

        for ($idx = 0; $idx < $k; $idx++) {
            $dx = $xs[$idx] - $meanX;
            $dy = $ys[$idx] - $meanY;
            $cov += $dx * $dy;
            $varX += $dx ** 2;
            $varY += $dy ** 2;
        }

        if ($varX <= 1e-18 || $varY <= 1e-18) {
            return [null, $k];
        }

        $corr = max(-1.0, min(1.0, $cov / sqrt($varX * $varY)));

        return [Dec::round(number_format($corr, 8, '.', ''), 4), $k];
    }

    /**
     * @param  list<array<string, mixed>>  $holdings
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array<string, mixed>>
     */
    private function computeCurrencyExposure(
        array $holdings,
        array $accounts,
        string $targetCcy,
        string $totalEquityRep,
    ): array {
        $buckets = [];

        foreach ($holdings as $h) {
            $ccy = strtoupper((string) $h['currency']);
            $mv = (string) ($h['market_value_reporting'] ?? '0.0000');
            $buckets[$ccy] ??= ['currency' => $ccy, 'equity_rep' => '0.0000', 'cash_rep' => '0.0000'];
            $buckets[$ccy]['equity_rep'] = Dec::add($buckets[$ccy]['equity_rep'], $mv, 4);
        }

        foreach ($accounts as $acct) {
            if (! ($acct['included'] ?? true)) {
                continue;
            }
            $ccy = strtoupper((string) $acct['base_currency']);
            $cash = (string) ($acct['cash_balance_reporting'] ?? '0.0000');
            if (Dec::cmp($cash, '0') === 0 && ! isset($buckets[$ccy])) {
                continue;
            }
            $buckets[$ccy] ??= ['currency' => $ccy, 'equity_rep' => '0.0000', 'cash_rep' => '0.0000'];
            $buckets[$ccy]['cash_rep'] = Dec::add($buckets[$ccy]['cash_rep'], $cash, 4);
        }

        $hasEquityDenom = Dec::cmp($totalEquityRep, '0') > 0;
        $rows = [];

        foreach ($buckets as $ccy => $b) {
            $total = Dec::add($b['equity_rep'], $b['cash_rep'], 4);
            $weight = $hasEquityDenom ? (Dec::div($total, $totalEquityRep, 8) ?? '0.00000000') : '0.00000000';
            $isBase = $ccy === $targetCcy;

            $rows[] = [
                'currency' => $ccy,
                'is_reporting_currency' => $isBase,
                'equity_rep' => $b['equity_rep'],
                'cash_rep' => $b['cash_rep'],
                'total_rep' => $total,
                'weight_pct' => $weight,
                'shock_plus_5pct_rep' => $isBase ? '0.0000' : Dec::mul($total, '0.05', 4),
                'shock_minus_5pct_rep' => $isBase ? '0.0000' : Dec::mul($total, '-0.05', 4),
                'shock_plus_10pct_rep' => $isBase ? '0.0000' : Dec::mul($total, '0.10', 4),
                'shock_minus_10pct_rep' => $isBase ? '0.0000' : Dec::mul($total, '-0.10', 4),
                'portfolio_impact_10pct' => $isBase ? '0.00000000' : Dec::mul($weight, '0.10', 8),
            ];
        }

        usort($rows, fn ($a, $b) => Dec::cmp($b['total_rep'], $a['total_rep']));

        return $rows;
    }
}
