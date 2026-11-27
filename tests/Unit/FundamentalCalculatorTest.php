<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\FundamentalCalculator;
use App\Domain\MarketData\Services\FxConversionService;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\FinancialStatement;
use App\Models\FxRateDaily;
use App\Models\Instrument;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_industrial_ratios_extraordinary_adjustment_and_point_in_time_cutoff(): void
    {
        $this->seed([ExchangeSeeder::class, DataSourceSeeder::class]);
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $source = DataSource::query()->where('code', 'demo')->firstOrFail();

        $inst = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'INDU',
            'name' => 'Industrial Tbk',
            'currency' => 'IDR',
            'sector' => 'Industrials',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        FinancialStatement::query()->create([
            'instrument_id' => $inst->id,
            'period_type' => 'FY',
            'fiscal_year' => 2024,
            'period_end_date' => '2024-12-31',
            'published_at' => '2025-03-01',
            'currency' => 'IDR',
            'revenue' => '1000.0000',
            'net_income' => '100.0000',
            'total_assets' => '1000.0000',
            'current_assets' => '400.0000',
            'current_liabilities' => '200.0000',
            'total_debt' => '300.0000',
            'total_equity' => '500.0000',
            'operating_cash_flow' => '150.0000',
            'capex' => '50.0000',
            'dividends_paid' => '40.0000',
            'shares_outstanding' => '100.0000',
            'data_source_id' => $source->id,
        ]);

        // FY 2025 published AFTER asOfDate (2026-02-01) -> must NOT leak!
        FinancialStatement::query()->create([
            'instrument_id' => $inst->id,
            'period_type' => 'FY',
            'fiscal_year' => 2025,
            'period_end_date' => '2025-12-31',
            'published_at' => '2026-03-15',
            'currency' => 'IDR',
            'revenue' => '2000.0000',
            'net_income' => '250.0000',
            'extraordinary_items' => '50.0000',
            'total_assets' => '1200.0000',
            'current_assets' => '500.0000',
            'current_liabilities' => '250.0000',
            'total_debt' => '250.0000',
            'total_equity' => '800.0000',
            'operating_cash_flow' => '300.0000',
            'capex' => '80.0000',
            'dividends_paid' => '60.0000',
            'shares_outstanding' => '100.0000',
            'data_source_id' => $source->id,
        ]);

        $calc = new FundamentalCalculator(new FxConversionService());

        $pitBefore = $calc->calculateForInstrument($inst, '20.000000', '2026-02-01');
        $this->assertSame('FY 2024', $pitBefore['period_label']);
        $this->assertSame('1.000000', $pitBefore['eps']);
        $this->assertSame('20.000000', $pitBefore['per']);
        $this->assertSame('4.000000', $pitBefore['pbv']);
        $this->assertSame('0.20000000', $pitBefore['roe']);
        $this->assertSame('2.000000', $pitBefore['current_ratio']);
        $this->assertSame('100.0000', $pitBefore['fcf']);

        $pitAfter = $calc->calculateForInstrument($inst, '20.000000', '2026-04-01');
        $this->assertSame('FY 2025', $pitAfter['period_label']);
        // Adjusted net income = 250 - 50 = 200 -> EPS = 2.00, PER = 10.00, revGrowth = +100%, earnGrowth = +100%
        $this->assertSame('2.000000', $pitAfter['eps']);
        $this->assertSame('10.000000', $pitAfter['per']);
        $this->assertSame('1.00000000', $pitAfter['revenue_growth_yoy']);
        $this->assertSame('1.00000000', $pitAfter['earnings_growth_yoy']);
    }

    public function test_financial_sector_negative_earnings_and_cross_currency_fx_handling(): void
    {
        $this->seed([ExchangeSeeder::class, DataSourceSeeder::class]);
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $source = DataSource::query()->where('code', 'demo')->firstOrFail();

        $bank = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'BANKX',
            'name' => 'Bank Test Tbk',
            'currency' => 'IDR',
            'sector' => 'Financials',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        FinancialStatement::query()->create([
            'instrument_id' => $bank->id,
            'period_type' => 'FY',
            'fiscal_year' => 2025,
            'period_end_date' => '2025-12-31',
            'published_at' => '2026-03-01',
            'currency' => 'IDR',
            'is_financial_sector' => true,
            'revenue' => '500.0000',
            'net_income' => '-50.0000',
            'total_assets' => '2000.0000',
            'total_liabilities' => '1800.0000',
            'total_equity' => '200.0000',
            'dividends_paid' => '10.0000',
            'shares_outstanding' => '100.0000',
            'data_source_id' => $source->id,
        ]);

        $calc = new FundamentalCalculator(new FxConversionService());
        $res = $calc->calculateForInstrument($bank, '100.000000', '2026-04-01');

        $this->assertTrue($res['is_financial_sector']);
        $this->assertSame('9.000000', $res['der']); // total_liabilities / total_equity
        $this->assertNull($res['current_ratio']);
        $this->assertNull($res['fcf']);
        $this->assertNull($res['per']); // negative earnings
        $this->assertNull($res['dividend_payout_ratio']); // negative earnings
        $this->assertContains('negative_or_zero_earnings', $res['missing_fields']);

        // Cross-currency USD statement on IDR equity
        $usdReporter = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'USDR',
            'name' => 'USD Reporter Tbk',
            'currency' => 'IDR',
            'sector' => 'Energy',
            'country_code' => 'ID',
            'is_active' => true,
        ]);

        FinancialStatement::query()->create([
            'instrument_id' => $usdReporter->id,
            'period_type' => 'FY',
            'fiscal_year' => 2025,
            'period_end_date' => '2025-12-31',
            'published_at' => '2026-03-01',
            'currency' => 'USD',
            'revenue' => '100.0000',
            'net_income' => '10.0000',
            'total_assets' => '200.0000',
            'total_equity' => '100.0000',
            'shares_outstanding' => '100.0000',
            'data_source_id' => $source->id,
        ]);

        // Without FX rate -> missing_fx_rate, EPS/PER null
        $noFx = $calc->calculateForInstrument($usdReporter, '3000.000000', '2026-04-01');
        $this->assertNull($noFx['eps']);
        $this->assertContains('missing_fx_rate', $noFx['missing_fields']);

        // With USD/IDR = 15000 -> EPS = (10 * 15000) / 100 = 1500 IDR, PER = 3000 / 1500 = 2.00
        FxRateDaily::query()->create([
            'base_currency' => 'USD',
            'quote_currency' => 'IDR',
            'rate_date' => '2026-04-01',
            'rate' => '15000.00000000',
            'data_source_id' => $source->id,
        ]);

        $withFx = $calc->calculateForInstrument($usdReporter, '3000.000000', '2026-04-01');
        $this->assertSame('1500.000000', $withFx['eps']);
        $this->assertSame('2.000000', $withFx['per']);
    }
}
