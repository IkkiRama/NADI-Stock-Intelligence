<?php

namespace Tests\Unit;

use App\Domain\Dividends\Services\DividendIncomeService;
use App\Domain\Portfolio\Services\PortfolioValuationService;
use App\Domain\PortfolioAccounting\Services\PortfolioAccountingService;
use App\Models\Broker;
use App\Models\BrokerageAccount;
use App\Models\CorporateAction;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\FxRateDaily;
use App\Models\Instrument;
use App\Models\InstrumentFundamentalLatest;
use App\Models\InstrumentQuoteLatest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BrokerSeeder;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DividendIncomeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            ExchangeSeeder::class,
            DataSourceSeeder::class,
            BrokerSeeder::class,
        ]);
    }

    public function test_separates_realized_dividends_from_projected_income_with_fx_tax_and_payout_warning(): void
    {
        $user = User::factory()->create();
        $portfolio = $user->ensureDefaultPortfolio();
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $nasdaq = Exchange::query()->where('code', 'NASDAQ')->firstOrFail();
        $stockbit = Broker::query()->where('code', 'STOCKBIT')->firstOrFail();
        $ibkr = Broker::query()->where('code', 'IBKR')->firstOrFail();
        $ds = DataSource::query()->firstOrFail();
        $today = CarbonImmutable::now('UTC')->startOfDay();

        $bbca = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'BBCA',
            'name' => 'Bank Central Asia Tbk',
            'type' => 'equity',
            'currency' => 'IDR',
            'sector' => 'Financials',
            'country_code' => 'ID',
            'lot_size' => 100,
            'is_active' => true,
        ]);
        $msft = Instrument::query()->create([
            'exchange_id' => $nasdaq->id,
            'symbol' => 'MSFT',
            'name' => 'Microsoft Corp',
            'type' => 'equity',
            'currency' => 'USD',
            'sector' => 'Technology',
            'country_code' => 'US',
            'lot_size' => 1,
            'is_active' => true,
        ]);

        FxRateDaily::query()->create([
            'base_currency' => 'USD',
            'quote_currency' => 'IDR',
            'rate_date' => $today->subDays(30)->toDateString(),
            'rate' => '16000.00000000',
            'data_source_id' => $ds->id,
        ]);

        InstrumentQuoteLatest::query()->create([
            'instrument_id' => $bbca->id,
            'trade_date' => $today->toDateString(),
            'close' => '10000.000000',
            'prev_close' => '10000.000000',
            'change' => '0',
            'change_pct' => '0',
            'volume' => 1000000,
            'data_source_id' => $ds->id,
            'refreshed_at' => now('UTC'),
        ]);
        InstrumentQuoteLatest::query()->create([
            'instrument_id' => $msft->id,
            'trade_date' => $today->toDateString(),
            'close' => '400.000000',
            'prev_close' => '400.000000',
            'change' => '0',
            'change_pct' => '0',
            'volume' => 1000000,
            'data_source_id' => $ds->id,
            'refreshed_at' => now('UTC'),
        ]);

        // Historical BBCA dividend 250 IDR/share + high payout ratio warning (92%)
        CorporateAction::query()->create([
            'instrument_id' => $bbca->id,
            'action_type' => 'cash_dividend',
            'ex_date' => $today->subDays(60)->toDateString(),
            'declared_currency' => 'IDR',
            'cash_amount' => '250.000000',
            'data_source_id' => $ds->id,
        ]);
        InstrumentFundamentalLatest::query()->create([
            'instrument_id' => $bbca->id,
            'as_of_date' => $today->toDateString(),
            'period_label' => 'FY 2025',
            'statement_currency' => 'IDR',
            'dividend_yield' => '0.02500000',
            'dividend_payout_ratio' => '0.92000000',
            'data_source_id' => $ds->id,
            'calculated_at' => now('UTC'),
        ]);
        // MSFT uses fundamental yield 1.00% ($4/sh)
        InstrumentFundamentalLatest::query()->create([
            'instrument_id' => $msft->id,
            'as_of_date' => $today->toDateString(),
            'period_label' => 'FY 2025',
            'statement_currency' => 'USD',
            'dividend_yield' => '0.01000000',
            'dividend_payout_ratio' => '0.25000000',
            'data_source_id' => $ds->id,
            'calculated_at' => now('UTC'),
        ]);

        $acctIdr = BrokerageAccount::query()->create([
            'user_id' => $user->id,
            'portfolio_id' => $portfolio->id,
            'broker_id' => $stockbit->id,
            'account_name' => 'IDX IDR',
            'base_currency' => 'IDR',
            'cost_basis_method' => 'weighted_average',
        ]);
        $acctUsd = BrokerageAccount::query()->create([
            'user_id' => $user->id,
            'portfolio_id' => $portfolio->id,
            'broker_id' => $ibkr->id,
            'account_name' => 'US USD',
            'base_currency' => 'USD',
            'cost_basis_method' => 'fifo',
        ]);

        $accounting = app(PortfolioAccountingService::class);
        $accounting->recordTransaction($user, $acctIdr, ['transaction_type' => 'cash_deposit', 'trade_date' => $today->subDays(40)->toDateString(), 'gross_amount' => '50000000', 'transaction_currency' => 'IDR']);
        $accounting->recordTransaction($user, $acctIdr, ['instrument_id' => $bbca->id, 'transaction_type' => 'buy', 'trade_date' => $today->subDays(35)->toDateString(), 'quantity' => '4000', 'price' => '8000', 'transaction_currency' => 'IDR']);
        $accounting->recordTransaction($user, $acctIdr, ['instrument_id' => $bbca->id, 'transaction_type' => 'dividend_receipt', 'trade_date' => $today->subDays(10)->toDateString(), 'gross_amount' => '1000000', 'tax_amount' => '100000', 'transaction_currency' => 'IDR']);

        $accounting->recordTransaction($user, $acctUsd, ['transaction_type' => 'cash_deposit', 'trade_date' => $today->subDays(40)->toDateString(), 'gross_amount' => '10000', 'transaction_currency' => 'USD']);
        $accounting->recordTransaction($user, $acctUsd, ['instrument_id' => $msft->id, 'transaction_type' => 'buy', 'trade_date' => $today->subDays(35)->toDateString(), 'quantity' => '10', 'price' => '400', 'transaction_currency' => 'USD']);
        $accounting->recordTransaction($user, $acctUsd, ['instrument_id' => $msft->id, 'transaction_type' => 'dividend_receipt', 'trade_date' => $today->subDays(5)->toDateString(), 'gross_amount' => '20', 'tax_amount' => '3', 'transaction_currency' => 'USD']);

        $val = app(PortfolioValuationService::class)->summarize($user, $portfolio, null, 'IDR');
        $res = app(DividendIncomeService::class)->analyze($user, $portfolio, $val);

        // Realized: BBCA 900,000 + MSFT (17 * 16,000 = 272,000) = 1,172,000 IDR
        $this->assertEqualsWithDelta(1172000.0, (float) $res['summary']['realized_net_all_time_rep'], 1.0);

        // Projected Gross: BBCA (4000 * 250 = 1,000,000) + MSFT (10 * 4 * 16,000 = 640,000) = 1,640,000 IDR
        // Projected Net: BBCA (1,000,000 * 0.90 = 900,000) + MSFT (640,000 * 0.85 = 544,000) = 1,444,000 IDR
        $this->assertEqualsWithDelta(1640000.0, (float) $res['summary']['projected_annual_gross_rep'], 1.0);
        $this->assertEqualsWithDelta(1444000.0, (float) $res['summary']['projected_annual_net_rep'], 1.0);

        $bySym = collect($res['holdings'])->keyBy('symbol');
        $this->assertTrue($bySym['BBCA']['high_payout_flag']);
        $this->assertGreaterThan((float) $bySym['BBCA']['yield_on_mv'], (float) $bySym['BBCA']['yield_on_cost']);
        $this->assertSame('trailing_corp_actions', $bySym['BBCA']['estimation_basis']);
        $this->assertSame('fundamental_yield', $bySym['MSFT']['estimation_basis']);
        $this->assertCount(12, $res['monthly_projected']);
    }
}
