<?php

namespace Tests\Unit;

use App\Domain\Reports\Services\PortfolioReportBuilderService;
use App\Domain\Reports\Services\ReportCsvExporter;
use Tests\TestCase;

class ReportCsvExporterTest extends TestCase
{
    public function test_exports_all_five_report_types_with_utf8_bom_and_metadata_headers(): void
    {
        $exporter = new ReportCsvExporter;

        $sampleReports = [
            'executive_statement' => [
                'report_type' => 'executive_statement',
                'meta' => [
                    'portfolio_name' => 'Global Equity',
                    'reporting_currency' => 'IDR',
                    'cost_basis_method' => 'weighted_average',
                    'range' => '1Y',
                    'as_of' => '2026-11-27',
                    'generated_at_utc' => '2026-11-27T00:00:00Z',
                    'is_demo' => true,
                ],
                'valuation' => [
                    'consolidatedHoldings' => [
                        [
                            'exchange_code' => 'IDX',
                            'symbol' => 'BBCA',
                            'name' => 'Bank Central Asia Tbk',
                            'sector' => 'Financials',
                            'country_code' => 'ID',
                            'currency' => 'IDR',
                            'quantity' => '1000',
                            'avg_cost_per_share' => '9500',
                            'close' => '10000',
                            'market_value_reporting' => '10000000',
                            'total_cost_reporting' => '9500000',
                            'unrealized_pnl_reporting' => '500000',
                            'weight_pct' => '100',
                            'safety_category' => 'Good',
                            'is_demo' => true,
                        ],
                    ],
                ],
            ],
            'performance_brief' => [
                'report_type' => 'performance_brief',
                'meta' => ['portfolio_name' => 'Global Equity', 'reporting_currency' => 'IDR'],
                'attribution' => [
                    'by_stock' => [
                        [
                            'exchange_code' => 'IDX',
                            'symbol' => 'BBCA',
                            'name' => 'Bank Central Asia Tbk',
                            'sector' => 'Financials',
                            'country_code' => 'ID',
                            'currency' => 'IDR',
                            'local_price_effect_rep' => '500000',
                            'fx_effect_rep' => '0',
                            'dividend_rep' => '200000',
                            'fee_tax_rep' => '-15000',
                            'total_pnl_rep' => '685000',
                            'contribution_pct' => '6.85',
                        ],
                    ],
                    'components' => [
                        'cash_fx_and_adjustments_rep' => '-12000',
                        'cash_fx_and_adjustments_pct' => '-0.12',
                    ],
                ],
            ],
            'dividend_tax_schedule' => [
                'report_type' => 'dividend_tax_schedule',
                'meta' => ['portfolio_name' => 'Global Equity', 'reporting_currency' => 'IDR'],
                'dividends' => [
                    'ledger_history' => [
                        [
                            'trade_date' => '2026-04-15',
                            'exchange_code' => 'IDX',
                            'symbol' => 'BBCA',
                            'currency' => 'IDR',
                            'gross_amount' => '1200000',
                            'tax_amount' => '120000',
                            'net_amount_reporting' => '1080000',
                        ],
                    ],
                    'holdings' => [
                        [
                            'estimation_basis' => 'trailing_12m',
                            'exchange_code' => 'IDX',
                            'symbol' => 'BBCA',
                            'currency' => 'IDR',
                            'projected_annual_gross_rep' => '1500000',
                            'tax_rate_pct' => '10',
                            'projected_annual_net_rep' => '1350000',
                            'yield_on_mv' => '3.10',
                            'yield_on_cost' => '3.50',
                        ],
                    ],
                ],
            ],
            'risk_rebalancing_plan' => [
                'report_type' => 'risk_rebalancing_plan',
                'meta' => ['portfolio_name' => 'Global Equity', 'reporting_currency' => 'IDR'],
                'rebalance' => [
                    'rows' => [
                        [
                            'exchange_code' => 'IDX',
                            'symbol' => 'BBCA',
                            'current_weight_pct' => '55.00',
                            'target_weight_pct' => '50.00',
                            'drift_pct' => '5.00',
                            'action' => 'TRIM',
                            'suggested_lots' => 5,
                            'suggested_shares' => '500',
                            'ref_price_local' => '10000',
                            'currency' => 'IDR',
                            'estimated_gross_rep' => '5000000',
                            'estimated_fee_rep' => '12500',
                            'post_weight_pct' => '50.00',
                            'safety_category' => 'Good',
                        ],
                    ],
                ],
            ],
            'tax_realized_ledger' => [
                'report_type' => 'tax_realized_ledger',
                'meta' => ['portfolio_name' => 'Global Equity', 'reporting_currency' => 'IDR'],
                'ledger' => [
                    'transactions' => [
                        [
                            'trade_date' => '2026-05-01',
                            'account_name' => 'Stockbit',
                            'transaction_type' => 'buy',
                            'exchange_code' => 'IDX',
                            'symbol' => 'BBCA',
                            'quantity' => '100',
                            'price' => '10000',
                            'gross_amount' => '1000000',
                            'broker_fee' => '1500',
                            'exchange_fee' => '400',
                            'tax_amount' => '0',
                            'net_cash_amount' => '-1001900',
                            'transaction_currency' => 'IDR',
                            'net_cash_reporting' => '-1001900',
                            'notes' => 'Core buy',
                            'is_demo' => true,
                        ],
                    ],
                ],
            ],
        ];

        foreach (PortfolioReportBuilderService::REPORT_TYPES as $type) {
            $csv = $exporter->toCsvString($sampleReports[$type]);
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, "Missing UTF-8 BOM for {$type}");
            $this->assertStringContainsString("Report,{$type}", $csv);
            $this->assertStringContainsString('ReportingCurrency,IDR', $csv);
            $this->assertStringContainsString('BBCA', $csv);
        }
    }

    public function test_escapes_csv_formula_injection_while_preserving_negative_numbers(): void
    {
        $exporter = new ReportCsvExporter;

        $csv = $exporter->toCsvString([
            'report_type' => 'tax_realized_ledger',
            'meta' => [
                'portfolio_name' => '=HYPERLINK("http://evil","Click")',
                'reporting_currency' => 'IDR',
            ],
            'ledger' => [
                'transactions' => [
                    [
                        'trade_date' => '2026-06-01',
                        'account_name' => '+cmd|calc',
                        'transaction_type' => 'buy',
                        'exchange_code' => 'NASDAQ',
                        'symbol' => '@SUM(1+1)',
                        'quantity' => '10',
                        'price' => '100',
                        'gross_amount' => '1000',
                        'broker_fee' => '0',
                        'exchange_fee' => '0',
                        'tax_amount' => '0',
                        'net_cash_amount' => '-123.45',
                        'transaction_currency' => 'USD',
                        'net_cash_reporting' => '-1975200.00',
                        'notes' => '-A1+B1',
                        'is_demo' => false,
                    ],
                ],
            ],
        ]);

        // Leading formula characters must be neutralized with leading single quote
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+cmd|calc", $csv);
        $this->assertStringContainsString("'@SUM(1+1)", $csv);
        $this->assertStringContainsString("'-A1+B1", $csv);

        // Negative numeric literals must remain unescaped numeric strings
        $this->assertStringContainsString(',-123.45,', $csv);
        $this->assertStringContainsString(',-1975200.00,', $csv);
    }
}
