<?php

namespace Tests\Unit;

use App\Domain\Portfolio\Services\PortfolioAnalyticsCommandCenterService;
use App\Models\Portfolio;
use Tests\TestCase;

class PortfolioCommandCenterServiceTest extends TestCase
{
    public function test_computes_exact_hhi_effective_holdings_non_duplicating_allocations_and_warnings(): void
    {
        $svc = new PortfolioAnalyticsCommandCenterService();

        // 4 equal holdings of 25,000,000 each = 100,000,000 equities + 20,000,000 cash = 120,000,000 equity
        $equalData = [
            'summary' => [
                'market_value' => '100000000.0000',
                'cash_balance' => '20000000.0000',
                'total_equity' => '120000000.0000',
                'invested_cost' => '90000000.0000',
                'accounts_count' => 2,
            ],
            'consolidatedHoldings' => [
                [
                    'instrument_id' => 1, 'symbol' => 'BBCA', 'name' => 'BCA', 'exchange_code' => 'IDX',
                    'sector' => 'Financials', 'industry' => 'Banking', 'country_code' => 'ID', 'size_tier' => 'large_cap',
                    'safety_category' => 'low', 'currency' => 'IDR',
                    'market_value_reporting' => '25000000.0000', 'total_cost_reporting' => '22000000.0000',
                ],
                [
                    'instrument_id' => 2, 'symbol' => 'TLKM', 'name' => 'Telkom', 'exchange_code' => 'IDX',
                    'sector' => 'Communication Services', 'industry' => 'Telecom', 'country_code' => 'ID', 'size_tier' => 'large_cap',
                    'safety_category' => 'low', 'currency' => 'IDR',
                    'market_value_reporting' => '25000000.0000', 'total_cost_reporting' => '24000000.0000',
                ],
                [
                    'instrument_id' => 3, 'symbol' => 'MSFT', 'name' => 'Microsoft', 'exchange_code' => 'NASDAQ',
                    'sector' => 'Information Technology', 'industry' => 'Software', 'country_code' => 'US', 'size_tier' => 'large_cap',
                    'safety_category' => 'low', 'currency' => 'USD',
                    'market_value_reporting' => '25000000.0000', 'total_cost_reporting' => '22000000.0000',
                ],
                [
                    'instrument_id' => 4, 'symbol' => 'DBS', 'name' => 'DBS Group', 'exchange_code' => 'SGX',
                    'sector' => 'Financials', 'industry' => 'Banking', 'country_code' => 'SG', 'size_tier' => 'large_cap',
                    'safety_category' => 'moderate', 'currency' => 'SGD',
                    'market_value_reporting' => '25000000.0000', 'total_cost_reporting' => '22000000.0000',
                ],
            ],
            'accountComparison' => [
                [
                    'id' => 1, 'broker_code' => 'STOCKBIT', 'account_name' => 'Stockbit IDR', 'base_currency' => 'IDR',
                    'included' => true, 'holdings_count' => 2,
                    'cash_balance_reporting' => '10000000.0000', 'market_value_reporting' => '50000000.0000',
                    'total_cost_reporting' => '46000000.0000', 'total_equity_reporting' => '60000000.0000',
                ],
                [
                    'id' => 2, 'broker_code' => 'IBKR', 'account_name' => 'IBKR Global', 'base_currency' => 'USD',
                    'included' => true, 'holdings_count' => 2,
                    'cash_balance_reporting' => '10000000.0000', 'market_value_reporting' => '50000000.0000',
                    'total_cost_reporting' => '44000000.0000', 'total_equity_reporting' => '60000000.0000',
                ],
            ],
        ];

        $res = $svc->analyze($equalData, Portfolio::DEFAULT_CONCENTRATION_THRESHOLDS);
        $conc = $res['concentration'];

        // 4 * 25^2 = 2500.00 HHI, effective holdings = 10000 / 2500 = 4.00
        $this->assertSame('2500.00', $conc['hhi_holdings']);
        $this->assertSame('4.00', $conc['effective_holdings']);
        $this->assertSame('75.00', $conc['top_3_weight_pct']);
        $this->assertSame('concentrated', $conc['hhi_level']);

        // Check zero double counting across dimensions: sum(by_sector) == 100M, sum(by_broker) == 120M, sum(by_currency) == 120M
        $sumSector = array_reduce($res['allocations']['by_sector'], fn ($c, $r) => $c + (float) $r['market_value'], 0.0);
        $sumBroker = array_reduce($res['allocations']['by_broker'], fn ($c, $r) => $c + (float) $r['market_value'], 0.0);
        $sumCcy = array_reduce($res['allocations']['by_currency'], fn ($c, $r) => $c + (float) $r['market_value'], 0.0);
        $this->assertEqualsWithDelta(100000000.0, $sumSector, 0.01);
        $this->assertEqualsWithDelta(120000000.0, $sumBroker, 0.01);
        $this->assertEqualsWithDelta(120000000.0, $sumCcy, 0.01);

        // 80% / 20% concentrated portfolio -> HHI = 6400 + 400 = 6800.00
        $skewedData = $equalData;
        $skewedData['consolidatedHoldings'] = [
            array_merge($equalData['consolidatedHoldings'][0], ['market_value_reporting' => '80000000.0000']),
            array_merge($equalData['consolidatedHoldings'][1], ['market_value_reporting' => '20000000.0000']),
        ];
        $skewedRes = $svc->analyze($skewedData, Portfolio::DEFAULT_CONCENTRATION_THRESHOLDS);
        $this->assertSame('6800.00', $skewedRes['concentration']['hhi_holdings']);
        $warningCodes = array_column($skewedRes['concentration']['warnings'], 'code');
        $this->assertContains('single_stock_concentration', $warningCodes);
        $this->assertContains('hhi_elevated', $warningCodes);
    }
}
