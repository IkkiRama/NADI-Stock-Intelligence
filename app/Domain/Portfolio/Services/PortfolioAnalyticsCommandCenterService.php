<?php

namespace App\Domain\Portfolio\Services;

use App\Support\Math\Dec;

/**
 * Multi-Dimensional Asset Allocation & Portfolio Concentration Engine.
 *
 * Computes non-duplicating allocations across Stock, Sector, Industry, Country,
 * Exchange, Currency, Brokerage Account, Size Tier, Safety Category, and Asset Class,
 * alongside Top-N concentration, HHI (0–10,000), Effective Holdings, and warnings.
 */
final class PortfolioAnalyticsCommandCenterService
{
    /**
     * @param  array<string, mixed>  $valuationData
     * @param  array<string, float>  $thresholds
     * @return array{allocations: array<string, list<array<string, mixed>>>, concentration: array<string, mixed>}
     */
    public function analyze(array $valuationData, array $thresholds): array
    {
        $summary = $valuationData['summary'];
        $holdings = $valuationData['consolidatedHoldings'];
        $accounts = $valuationData['accountComparison'] ?? [];

        $totalEquitiesMv = (string) ($summary['market_value'] ?? '0.0000');
        $totalCash = (string) ($summary['cash_balance'] ?? '0.0000');
        $totalEquity = (string) ($summary['total_equity'] ?? '0.0000');
        $totalInvestedCost = (string) ($summary['invested_cost'] ?? '0.0000');

        $allocations = [
            'by_stock' => $this->groupHoldings($holdings, fn ($h): array => [$h['symbol'], "{$h['symbol']} ({$h['exchange_code']})"], $totalEquitiesMv, $totalEquity),
            'by_sector' => $this->groupHoldings($holdings, fn ($h): array => [$h['sector'] ?: 'Unclassified', $h['sector'] ?: 'Unclassified'], $totalEquitiesMv, $totalEquity),
            'by_industry' => $this->groupHoldings($holdings, fn ($h): array => [$h['industry'] ?: 'Unclassified', $h['industry'] ?: 'Unclassified'], $totalEquitiesMv, $totalEquity),
            'by_country' => $this->groupHoldings($holdings, fn ($h): array => [$h['country_code'] ?: 'N/A', $h['country_code'] ?: 'N/A'], $totalEquitiesMv, $totalEquity),
            'by_exchange' => $this->groupHoldings($holdings, fn ($h): array => [$h['exchange_code'] ?: 'N/A', $h['exchange_code'] ?: 'N/A'], $totalEquitiesMv, $totalEquity),
            'by_size_tier' => $this->groupHoldings($holdings, fn ($h): array => [$h['size_tier'] ?? 'unclassified', $this->sizeTierLabel($h['size_tier'] ?? 'unclassified')], $totalEquitiesMv, $totalEquity),
            'by_safety' => $this->groupHoldings($holdings, fn ($h): array => [$h['safety_category'] ?? 'insufficient_data', $this->safetyLabel($h['safety_category'] ?? 'insufficient_data')], $totalEquitiesMv, $totalEquity),
            'by_broker' => $this->buildBrokerAllocation($accounts, $totalEquitiesMv, $totalEquity),
            'by_currency' => $this->buildCurrencyAllocation($holdings, $accounts, $totalEquitiesMv, $totalEquity),
            'by_asset_class' => $this->buildAssetClassAllocation($totalEquitiesMv, $totalInvestedCost, $totalCash, $totalEquity, count($holdings)),
        ];

        $concentration = $this->computeConcentration($holdings, $allocations, $summary, $thresholds);

        return [
            'allocations' => $allocations,
            'concentration' => $concentration,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $holdings
     * @param  callable(array<string, mixed>): array{0: string, 1: string}  $keyExtractor
     * @return list<array<string, mixed>>
     */
    private function groupHoldings(array $holdings, callable $keyExtractor, string $totalEquitiesMv, string $totalEquity): array
    {
        $buckets = [];
        foreach ($holdings as $h) {
            [$key, $label] = $keyExtractor($h);
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'count' => 0,
                    'cost_basis' => '0.0000',
                    'market_value' => '0.0000',
                    'unrealized_pnl' => '0.0000',
                ];
            }
            $mv = (string) ($h['market_value_reporting'] ?? '0.0000');
            $cost = (string) ($h['total_cost_reporting'] ?? '0.0000');
            $unrl = Dec::sub($mv, $cost, 4);

            $buckets[$key]['count']++;
            $buckets[$key]['cost_basis'] = Dec::add($buckets[$key]['cost_basis'], $cost, 4);
            $buckets[$key]['market_value'] = Dec::add($buckets[$key]['market_value'], $mv, 4);
            $buckets[$key]['unrealized_pnl'] = Dec::add($buckets[$key]['unrealized_pnl'], $unrl, 4);
        }

        return $this->finalizeBuckets($buckets, $totalEquitiesMv, $totalEquity);
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array<string, mixed>>
     */
    private function buildBrokerAllocation(array $accounts, string $totalEquitiesMv, string $totalEquity): array
    {
        $buckets = [];
        foreach ($accounts as $acct) {
            if (! ($acct['included'] ?? true)) {
                continue;
            }
            $key = (string) $acct['id'];
            $mv = (string) ($acct['total_equity_reporting'] ?? '0.0000');
            $cost = (string) ($acct['total_cost_reporting'] ?? '0.0000');
            $eqMv = (string) ($acct['market_value_reporting'] ?? '0.0000');
            $buckets[$key] = [
                'key' => $acct['broker_code'].'-'.$acct['id'],
                'label' => $acct['account_name']." ({$acct['base_currency']})",
                'count' => (int) ($acct['holdings_count'] ?? 0),
                'cost_basis' => $cost,
                'market_value' => $mv,
                'unrealized_pnl' => Dec::sub($eqMv, $cost, 4),
            ];
        }

        return $this->finalizeBuckets($buckets, $totalEquitiesMv, $totalEquity);
    }

    /**
     * Combines equity market values (by instrument currency) and cash balances (by account base currency)
     * so total allocation equals total_equity without double counting.
     *
     * @param  list<array<string, mixed>>  $holdings
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array<string, mixed>>
     */
    private function buildCurrencyAllocation(array $holdings, array $accounts, string $totalEquitiesMv, string $totalEquity): array
    {
        $buckets = [];
        foreach ($holdings as $h) {
            $ccy = strtoupper((string) ($h['currency'] ?? 'IDR'));
            if (! isset($buckets[$ccy])) {
                $buckets[$ccy] = [
                    'key' => $ccy,
                    'label' => $ccy,
                    'count' => 0,
                    'cost_basis' => '0.0000',
                    'market_value' => '0.0000',
                    'unrealized_pnl' => '0.0000',
                ];
            }
            $mv = (string) ($h['market_value_reporting'] ?? '0.0000');
            $cost = (string) ($h['total_cost_reporting'] ?? '0.0000');
            $buckets[$ccy]['count']++;
            $buckets[$ccy]['cost_basis'] = Dec::add($buckets[$ccy]['cost_basis'], $cost, 4);
            $buckets[$ccy]['market_value'] = Dec::add($buckets[$ccy]['market_value'], $mv, 4);
            $buckets[$ccy]['unrealized_pnl'] = Dec::add($buckets[$ccy]['unrealized_pnl'], Dec::sub($mv, $cost, 4), 4);
        }

        foreach ($accounts as $acct) {
            if (! ($acct['included'] ?? true)) {
                continue;
            }
            $ccy = strtoupper((string) ($acct['base_currency'] ?? 'IDR'));
            $cashRep = (string) ($acct['cash_balance_reporting'] ?? '0.0000');
            if (Dec::cmp($cashRep, '0') === 0) {
                continue;
            }
            if (! isset($buckets[$ccy])) {
                $buckets[$ccy] = [
                    'key' => $ccy,
                    'label' => $ccy,
                    'count' => 0,
                    'cost_basis' => '0.0000',
                    'market_value' => '0.0000',
                    'unrealized_pnl' => '0.0000',
                ];
            }
            $buckets[$ccy]['market_value'] = Dec::add($buckets[$ccy]['market_value'], $cashRep, 4);
        }

        return $this->finalizeBuckets($buckets, $totalEquitiesMv, $totalEquity);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildAssetClassAllocation(
        string $totalEquitiesMv,
        string $totalInvestedCost,
        string $totalCash,
        string $totalEquity,
        int $holdingsCount,
    ): array {
        $buckets = [];
        if (Dec::cmp($totalEquitiesMv, '0') > 0 || $holdingsCount > 0) {
            $buckets['equities'] = [
                'key' => 'equities',
                'label' => 'Equities (Saham)',
                'count' => $holdingsCount,
                'cost_basis' => $totalInvestedCost,
                'market_value' => $totalEquitiesMv,
                'unrealized_pnl' => Dec::sub($totalEquitiesMv, $totalInvestedCost, 4),
            ];
        }
        if (Dec::cmp($totalCash, '0') > 0) {
            $buckets['cash'] = [
                'key' => 'cash',
                'label' => 'Cash (Kas / RDN)',
                'count' => 0,
                'cost_basis' => $totalCash,
                'market_value' => $totalCash,
                'unrealized_pnl' => '0.0000',
            ];
        }

        return $this->finalizeBuckets($buckets, $totalEquitiesMv, $totalEquity);
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @return list<array<string, mixed>>
     */
    private function finalizeBuckets(array $buckets, string $totalEquitiesMv, string $totalEquity): array
    {
        $rows = array_values($buckets);
        foreach ($rows as &$row) {
            $mv = (string) $row['market_value'];
            $row['weight_holdings_pct'] = Dec::cmp($totalEquitiesMv, '0') > 0
                ? (Dec::div(Dec::mul($mv, '100', 6), $totalEquitiesMv, 2) ?? '0.00')
                : '0.00';
            $row['weight_equity_pct'] = Dec::cmp($totalEquity, '0') > 0
                ? (Dec::div(Dec::mul($mv, '100', 6), $totalEquity, 2) ?? '0.00')
                : '0.00';
        }
        unset($row);

        usort($rows, fn ($a, $b) => Dec::cmp((string) $b['market_value'], (string) $a['market_value']));

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $holdings
     * @param  array<string, list<array<string, mixed>>>  $allocations
     * @param  array<string, mixed>  $summary
     * @param  array<string, float>  $thresholds
     * @return array<string, mixed>
     */
    private function computeConcentration(array $holdings, array $allocations, array $summary, array $thresholds): array
    {
        $totalEquitiesMv = (string) ($summary['market_value'] ?? '0.0000');
        $totalEquity = (string) ($summary['total_equity'] ?? '0.0000');
        $accountsCount = (int) ($summary['accounts_count'] ?? 0);

        $sortedStocks = $holdings;
        usort($sortedStocks, fn ($a, $b) => Dec::cmp((string) ($b['market_value_reporting'] ?? '0'), (string) ($a['market_value_reporting'] ?? '0')));

        $top3 = '0.00';
        $top5 = '0.00';
        $top10 = '0.00';
        $hhi = '0.00';
        $largestHolding = null;

        if (Dec::cmp($totalEquitiesMv, '0') > 0 && ! empty($sortedStocks)) {
            foreach ($sortedStocks as $idx => $h) {
                $mv = (string) ($h['market_value_reporting'] ?? '0.0000');
                $wHoldPct = Dec::div(Dec::mul($mv, '100', 8), $totalEquitiesMv, 6) ?? '0';
                $wEqPct = Dec::cmp($totalEquity, '0') > 0
                    ? (Dec::div(Dec::mul($mv, '100', 6), $totalEquity, 2) ?? '0.00')
                    : '0.00';

                if ($idx === 0) {
                    $largestHolding = [
                        'symbol' => $h['symbol'],
                        'name' => $h['name'],
                        'exchange_code' => $h['exchange_code'],
                        'market_value' => $mv,
                        'weight_holdings_pct' => Dec::round($wHoldPct, 2),
                        'weight_equity_pct' => $wEqPct,
                    ];
                }
                if ($idx < 3) {
                    $top3 = Dec::add($top3, $wHoldPct, 6);
                }
                if ($idx < 5) {
                    $top5 = Dec::add($top5, $wHoldPct, 6);
                }
                if ($idx < 10) {
                    $top10 = Dec::add($top10, $wHoldPct, 6);
                }

                $hhi = Dec::add($hhi, Dec::mul($wHoldPct, $wHoldPct, 6), 6);
            }

            $hhi = Dec::round($hhi, 2);
            $effectiveHoldings = Dec::cmp($hhi, '0') > 0 ? (Dec::div('10000', $hhi, 2) ?? '0.00') : null;
        } else {
            $hhi = null;
            $effectiveHoldings = null;
        }

        $hhiNum = $hhi !== null ? (float) $hhi : null;
        $hhiLevel = match (true) {
            $hhiNum === null => 'empty',
            $hhiNum >= (float) ($thresholds['hhi_elevated_threshold'] ?? 2500.0) => 'concentrated',
            $hhiNum >= 1500.0 => 'moderate',
            default => 'diversified',
        };

        $topSector = $allocations['by_sector'][0] ?? null;
        $topCountry = $allocations['by_country'][0] ?? null;
        $topBroker = $allocations['by_broker'][0] ?? null;
        $topCurrency = $allocations['by_currency'][0] ?? null;

        $warnings = [];
        if ($largestHolding && (float) $largestHolding['weight_holdings_pct'] > $thresholds['max_single_stock_pct']) {
            $warnings[] = [
                'code' => 'single_stock_concentration',
                'severity' => 'high',
                'dimension' => 'stock',
                'subject' => $largestHolding['symbol'],
                'actual_pct' => $largestHolding['weight_holdings_pct'],
                'threshold_pct' => number_format($thresholds['max_single_stock_pct'], 2, '.', ''),
                'message' => "Bobot saham {$largestHolding['symbol']} ({$largestHolding['weight_holdings_pct']}% dari portofolio saham) melampaui batas {$thresholds['max_single_stock_pct']}%.",
            ];
        }

        $top3Rounded = Dec::round($top3, 2);
        if (! empty($sortedStocks) && count($sortedStocks) > 1 && (float) $top3Rounded > $thresholds['max_top3_pct']) {
            $warnings[] = [
                'code' => 'top3_concentration',
                'severity' => 'medium',
                'dimension' => 'top3',
                'subject' => 'Top 3',
                'actual_pct' => $top3Rounded,
                'threshold_pct' => number_format($thresholds['max_top3_pct'], 2, '.', ''),
                'message' => "Akumulasi 3 kepemilikan teratas mencapai {$top3Rounded}% (> {$thresholds['max_top3_pct']}%).",
            ];
        }

        if ($topSector && (float) $topSector['weight_holdings_pct'] > $thresholds['max_sector_pct']) {
            $warnings[] = [
                'code' => 'sector_concentration',
                'severity' => 'medium',
                'dimension' => 'sector',
                'subject' => $topSector['label'],
                'actual_pct' => $topSector['weight_holdings_pct'],
                'threshold_pct' => number_format($thresholds['max_sector_pct'], 2, '.', ''),
                'message' => "Eksposur sektor {$topSector['label']} ({$topSector['weight_holdings_pct']}%) melebihi batas {$thresholds['max_sector_pct']}%.",
            ];
        }

        if ($topCountry && (float) $topCountry['weight_holdings_pct'] > $thresholds['max_country_pct']) {
            $warnings[] = [
                'code' => 'country_concentration',
                'severity' => 'low',
                'dimension' => 'country',
                'subject' => $topCountry['label'],
                'actual_pct' => $topCountry['weight_holdings_pct'],
                'threshold_pct' => number_format($thresholds['max_country_pct'], 2, '.', ''),
                'message' => "Eksposur negara {$topCountry['label']} ({$topCountry['weight_holdings_pct']}%) melebihi batas {$thresholds['max_country_pct']}%.",
            ];
        }

        if ($accountsCount > 1 && $topBroker && (float) $topBroker['weight_equity_pct'] > $thresholds['max_broker_pct']) {
            $warnings[] = [
                'code' => 'broker_concentration',
                'severity' => 'low',
                'dimension' => 'broker',
                'subject' => $topBroker['label'],
                'actual_pct' => $topBroker['weight_equity_pct'],
                'threshold_pct' => number_format($thresholds['max_broker_pct'], 2, '.', ''),
                'message' => "Konsentrasi pada akun {$topBroker['label']} ({$topBroker['weight_equity_pct']}% ekuitas) melebihi batas {$thresholds['max_broker_pct']}%.",
            ];
        }

        if ($topCurrency && (float) $topCurrency['weight_equity_pct'] > $thresholds['max_currency_pct']) {
            $warnings[] = [
                'code' => 'currency_concentration',
                'severity' => 'low',
                'dimension' => 'currency',
                'subject' => $topCurrency['label'],
                'actual_pct' => $topCurrency['weight_equity_pct'],
                'threshold_pct' => number_format($thresholds['max_currency_pct'], 2, '.', ''),
                'message' => "Eksposur mata uang {$topCurrency['label']} ({$topCurrency['weight_equity_pct']}%) melebihi batas {$thresholds['max_currency_pct']}%.",
            ];
        }

        if ($hhiNum !== null && $hhiNum >= (float) ($thresholds['hhi_elevated_threshold'] ?? 2500.0)) {
            $warnings[] = [
                'code' => 'hhi_elevated',
                'severity' => 'medium',
                'dimension' => 'hhi',
                'subject' => 'HHI',
                'actual_pct' => $hhi,
                'threshold_pct' => number_format((float) $thresholds['hhi_elevated_threshold'], 2, '.', ''),
                'message' => "Indeks konsentrasi HHI sebesar {$hhi} melampaui ambang {$thresholds['hhi_elevated_threshold']} (setara ~{$effectiveHoldings} posisi merata).",
            ];
        }

        return [
            'top_3_weight_pct' => $top3Rounded,
            'top_5_weight_pct' => Dec::round($top5, 2),
            'top_10_weight_pct' => Dec::round($top10, 2),
            'largest_holding' => $largestHolding,
            'hhi_holdings' => $hhi,
            'effective_holdings' => $effectiveHoldings,
            'hhi_level' => $hhiLevel,
            'top_sector' => $topSector,
            'top_country' => $topCountry,
            'top_broker' => $topBroker,
            'top_currency' => $topCurrency,
            'warnings' => $warnings,
        ];
    }

    private function sizeTierLabel(string $tier): string
    {
        return match ($tier) {
            'large_cap' => 'Large Cap (>= 100T IDR)',
            'mid_cap' => 'Mid Cap (10T–100T IDR)',
            'small_cap' => 'Small Cap (< 10T IDR)',
            default => 'Unclassified',
        };
    }

    private function safetyLabel(string $cat): string
    {
        return match ($cat) {
            'low' => 'Safety: Rendah',
            'moderate' => 'Safety: Sedang',
            'elevated' => 'Safety: Meningkat',
            'high' => 'Safety: Tinggi',
            default => 'Safety: Data Terbatas',
        };
    }
}
