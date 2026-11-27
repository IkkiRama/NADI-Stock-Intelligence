<?php

namespace App\Domain\Reports\Services;

use App\Domain\MarketData\Services\CsvSanitizer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Formula-Injection-Safe CSV Exporter for all NADI institutional report types.
 */
final class ReportCsvExporter
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{meta: list<list<string>>, headers: list<string>, rows: list<list<string>>}
     */
    public function buildRows(array $report): array
    {
        $type = (string) ($report['report_type'] ?? 'executive_statement');
        $meta = $report['meta'] ?? [];

        $metaLines = [
            ['Report', $type],
            ['Portfolio', (string) ($meta['portfolio_name'] ?? 'Portfolio')],
            ['ReportingCurrency', (string) ($meta['reporting_currency'] ?? 'IDR')],
            ['CostBasisMethod', (string) ($meta['cost_basis_method'] ?? 'weighted_average')],
            ['Range', (string) ($meta['range'] ?? '1Y')],
            ['AsOfDate', (string) ($meta['as_of'] ?? '')],
            ['GeneratedAtUTC', (string) ($meta['generated_at_utc'] ?? '')],
            ['ContainsDemoData', ($meta['is_demo'] ?? false) ? 'YES' : 'NO'],
        ];

        $headers = [];
        $rows = [];

        switch ($type) {
            case 'executive_statement':
                $headers = [
                    'Exchange', 'Symbol', 'Name', 'Sector', 'Country', 'Currency',
                    'Quantity', 'AvgCostLocal', 'CloseLocal', 'MarketValueReporting',
                    'CostBasisReporting', 'UnrealizedPnlReporting', 'WeightPct', 'SafetyCategory', 'IsDemo',
                ];
                foreach ($report['valuation']['consolidatedHoldings'] ?? [] as $h) {
                    $rows[] = [
                        (string) ($h['exchange_code'] ?? ''),
                        (string) ($h['symbol'] ?? ''),
                        (string) ($h['name'] ?? ''),
                        (string) ($h['sector'] ?? ''),
                        (string) ($h['country_code'] ?? ''),
                        (string) ($h['currency'] ?? ''),
                        (string) ($h['quantity'] ?? '0'),
                        (string) ($h['avg_cost_per_share'] ?? '0'),
                        (string) ($h['close'] ?? ''),
                        (string) ($h['market_value_reporting'] ?? '0'),
                        (string) ($h['total_cost_reporting'] ?? '0'),
                        (string) ($h['unrealized_pnl_reporting'] ?? '0'),
                        (string) ($h['weight_pct'] ?? '0'),
                        (string) ($h['safety_category'] ?? ''),
                        ($h['is_demo'] ?? false) ? '1' : '0',
                    ];
                }
                break;

            case 'performance_brief':
                $headers = [
                    'Exchange', 'Symbol', 'Name', 'Sector', 'Country', 'Currency',
                    'LocalPriceEffect', 'FxEffect', 'Dividends', 'FeesTaxes', 'TotalPnlReporting', 'ContributionPct',
                ];
                foreach ($report['attribution']['by_stock'] ?? [] as $s) {
                    $rows[] = [
                        (string) ($s['exchange_code'] ?? ''),
                        (string) ($s['symbol'] ?? ''),
                        (string) ($s['name'] ?? ''),
                        (string) ($s['sector'] ?? ''),
                        (string) ($s['country_code'] ?? ''),
                        (string) ($s['currency'] ?? ''),
                        (string) ($s['local_price_effect_rep'] ?? '0'),
                        (string) ($s['fx_effect_rep'] ?? '0'),
                        (string) ($s['dividend_rep'] ?? '0'),
                        (string) ($s['fee_tax_rep'] ?? '0'),
                        (string) ($s['total_pnl_rep'] ?? '0'),
                        (string) ($s['contribution_pct'] ?? ''),
                    ];
                }
                $comp = $report['attribution']['components'] ?? [];
                $rows[] = [
                    '',
                    'CASH_FX_OTHER',
                    'Cash FX & Account Adjustments',
                    '',
                    '',
                    (string) ($meta['reporting_currency'] ?? 'IDR'),
                    '0',
                    '0',
                    '0',
                    '0',
                    (string) ($comp['cash_fx_and_adjustments_rep'] ?? '0'),
                    (string) ($comp['cash_fx_and_adjustments_pct'] ?? ''),
                ];
                break;

            case 'dividend_tax_schedule':
                $headers = [
                    'RecordKind', 'DateOrBasis', 'Exchange', 'Symbol', 'Currency',
                    'GrossAmount', 'TaxAmountOrPct', 'NetReporting', 'YieldOnMV', 'YieldOnCost',
                ];
                foreach ($report['dividends']['ledger_history'] ?? [] as $tx) {
                    $rows[] = [
                        'Realized',
                        (string) ($tx['trade_date'] ?? ''),
                        (string) ($tx['exchange_code'] ?? ''),
                        (string) ($tx['symbol'] ?? ''),
                        (string) ($tx['currency'] ?? ''),
                        (string) ($tx['gross_amount'] ?? '0'),
                        (string) ($tx['tax_amount'] ?? '0'),
                        (string) ($tx['net_amount_reporting'] ?? '0'),
                        '',
                        '',
                    ];
                }
                foreach ($report['dividends']['holdings'] ?? [] as $h) {
                    $rows[] = [
                        'ProjectedEstimate',
                        (string) ($h['estimation_basis'] ?? ''),
                        (string) ($h['exchange_code'] ?? ''),
                        (string) ($h['symbol'] ?? ''),
                        (string) ($h['currency'] ?? ''),
                        (string) ($h['projected_annual_gross_rep'] ?? '0'),
                        (string) ($h['tax_rate_pct'] ?? '0').'%',
                        (string) ($h['projected_annual_net_rep'] ?? '0'),
                        (string) ($h['yield_on_mv'] ?? ''),
                        (string) ($h['yield_on_cost'] ?? ''),
                    ];
                }
                break;

            case 'risk_rebalancing_plan':
                $headers = [
                    'Exchange', 'Symbol', 'CurrentWeightPct', 'TargetWeightPct', 'DriftPct',
                    'Action', 'SuggestedLots', 'SuggestedShares', 'RefPriceLocal', 'Currency',
                    'EstGrossReporting', 'EstFeeReporting', 'PostWeightPct', 'SafetyCategory',
                ];
                foreach ($report['rebalance']['rows'] ?? [] as $r) {
                    $rows[] = [
                        (string) ($r['exchange_code'] ?? ''),
                        (string) ($r['symbol'] ?? ''),
                        (string) ($r['current_weight_pct'] ?? '0'),
                        (string) ($r['target_weight_pct'] ?? '0'),
                        (string) ($r['drift_pct'] ?? '0'),
                        (string) ($r['action'] ?? ''),
                        (string) ($r['suggested_lots'] ?? 0),
                        (string) ($r['suggested_shares'] ?? '0'),
                        (string) ($r['ref_price_local'] ?? ''),
                        (string) ($r['currency'] ?? ''),
                        (string) ($r['estimated_gross_rep'] ?? '0'),
                        (string) ($r['estimated_fee_rep'] ?? '0'),
                        (string) ($r['post_weight_pct'] ?? '0'),
                        (string) ($r['safety_category'] ?? ''),
                    ];
                }
                break;

            case 'tax_realized_ledger':
                $headers = [
                    'TradeDate', 'Account', 'Type', 'Exchange', 'Symbol',
                    'Quantity', 'Price', 'GrossAmount', 'BrokerFee', 'ExchangeFee',
                    'TaxAmount', 'NetCashAmount', 'Currency', 'NetCashReporting', 'Notes', 'IsDemo',
                ];
                foreach ($report['ledger']['transactions'] ?? [] as $tx) {
                    $rows[] = [
                        (string) ($tx['trade_date'] ?? ''),
                        (string) ($tx['account_name'] ?? ''),
                        (string) ($tx['transaction_type'] ?? ''),
                        (string) ($tx['exchange_code'] ?? ''),
                        (string) ($tx['symbol'] ?? ''),
                        (string) ($tx['quantity'] ?? ''),
                        (string) ($tx['price'] ?? ''),
                        (string) ($tx['gross_amount'] ?? '0'),
                        (string) ($tx['broker_fee'] ?? '0'),
                        (string) ($tx['exchange_fee'] ?? '0'),
                        (string) ($tx['tax_amount'] ?? '0'),
                        (string) ($tx['net_cash_amount'] ?? '0'),
                        (string) ($tx['transaction_currency'] ?? ''),
                        (string) ($tx['net_cash_reporting'] ?? '0'),
                        (string) ($tx['notes'] ?? ''),
                        ($tx['is_demo'] ?? false) ? '1' : '0',
                    ];
                }
                break;
        }

        return [
            'meta' => $metaLines,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function toCsvString(array $report): string
    {
        $built = $this->buildRows($report);
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($built['meta'] as $mRow) {
            fputcsv($handle, array_map([CsvSanitizer::class, 'escapeCell'], $mRow));
        }
        fputcsv($handle, []);
        fputcsv($handle, array_map([CsvSanitizer::class, 'escapeCell'], $built['headers']));
        foreach ($built['rows'] as $row) {
            fputcsv($handle, array_map([CsvSanitizer::class, 'escapeCell'], $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function streamResponse(array $report, string $filename): StreamedResponse
    {
        $csv = $this->toCsvString($report);

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
