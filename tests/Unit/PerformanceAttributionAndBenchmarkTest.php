<?php

namespace Tests\Unit;

use App\Domain\Performance\Services\BenchmarkComparisonService;
use App\Domain\Performance\Services\PerformanceAttributionService;
use App\Domain\Performance\Services\PortfolioNavSeriesService;
use App\Domain\Performance\Services\TwrCalculator;
use App\Domain\PortfolioAccounting\Services\PortfolioAccountingService;
use App\Models\Broker;
use App\Models\BrokerageAccount;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\FxRateDaily;
use App\Models\IndexValueDaily;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\StockIndex;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BrokerSeeder;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceAttributionAndBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private int $sourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            ExchangeSeeder::class,
            DataSourceSeeder::class,
            BrokerSeeder::class,
        ]);
        $this->sourceId = DataSource::query()->firstOrFail()->id;
    }

    public function test_multi_currency_attribution_separates_local_price_fx_dividends_fees_and_reconciles(): void
    {
        $user = User::factory()->create();
        $portfolio = $user->ensureDefaultPortfolio();
        $idxEx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $nasdaqEx = Exchange::query()->where('code', 'NASDAQ')->firstOrFail();
        $stockbit = Broker::query()->where('code', 'STOCKBIT')->firstOrFail();
        $ibkr = Broker::query()->where('code', 'IBKR')->firstOrFail();

        $bbca = Instrument::query()->create([
            'exchange_id' => $idxEx->id,
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
            'exchange_id' => $nasdaqEx->id,
            'symbol' => 'MSFT',
            'name' => 'Microsoft Corp',
            'type' => 'equity',
            'currency' => 'USD',
            'sector' => 'Technology',
            'country_code' => 'US',
            'lot_size' => 1,
            'is_active' => true,
        ]);

        // FX moves from 15,000 -> 16,000 (+1,000 per USD)
        FxRateDaily::query()->create(['base_currency' => 'USD', 'quote_currency' => 'IDR', 'rate_date' => '2025-01-01', 'rate' => '15000.00000000', 'data_source_id' => $this->sourceId]);
        FxRateDaily::query()->create(['base_currency' => 'USD', 'quote_currency' => 'IDR', 'rate_date' => '2025-02-01', 'rate' => '16000.00000000', 'data_source_id' => $this->sourceId]);

        // BBCA: 10,000 -> 11,000 (+10%); MSFT: 100 -> 120 (+20%)
        InstrumentPriceDaily::query()->create(['instrument_id' => $bbca->id, 'trade_date' => '2025-01-01', 'open' => 10000, 'high' => 10000, 'low' => 10000, 'close' => 10000, 'volume' => 1000, 'data_source_id' => $this->sourceId]);
        InstrumentPriceDaily::query()->create(['instrument_id' => $bbca->id, 'trade_date' => '2025-02-01', 'open' => 11000, 'high' => 11000, 'low' => 11000, 'close' => 11000, 'volume' => 1000, 'data_source_id' => $this->sourceId]);
        InstrumentPriceDaily::query()->create(['instrument_id' => $msft->id, 'trade_date' => '2025-01-01', 'open' => 100, 'high' => 100, 'low' => 100, 'close' => 100, 'volume' => 1000, 'data_source_id' => $this->sourceId]);
        InstrumentPriceDaily::query()->create(['instrument_id' => $msft->id, 'trade_date' => '2025-02-01', 'open' => 120, 'high' => 120, 'low' => 120, 'close' => 120, 'volume' => 1000, 'data_source_id' => $this->sourceId]);

        $accounting = app(PortfolioAccountingService::class);
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

        // IDR Account: deposit 1,000,000; buy 100 BBCA @ 10,000 with 15,000 fee? Use 1,100,000 so cash remains positive.
        $accounting->recordTransaction($user, $acctIdr, ['transaction_type' => 'cash_deposit', 'trade_date' => '2025-01-01', 'gross_amount' => '1100000', 'transaction_currency' => 'IDR']);
        $accounting->recordTransaction($user, $acctIdr, ['instrument_id' => $bbca->id, 'transaction_type' => 'buy', 'trade_date' => '2025-01-01', 'quantity' => '100', 'price' => '10000', 'broker_fee' => '15000', 'transaction_currency' => 'IDR']);
        $accounting->recordTransaction($user, $acctIdr, ['instrument_id' => $bbca->id, 'transaction_type' => 'dividend_receipt', 'trade_date' => '2025-02-01', 'gross_amount' => '50000', 'transaction_currency' => 'IDR']);

        // USD Account: deposit 1,000 USD (all spent on 10 MSFT @ 100, zero fee -> 0 cash so no cash FX residual)
        $accounting->recordTransaction($user, $acctUsd, ['transaction_type' => 'cash_deposit', 'trade_date' => '2025-01-01', 'gross_amount' => '1000', 'transaction_currency' => 'USD']);
        $accounting->recordTransaction($user, $acctUsd, ['instrument_id' => $msft->id, 'transaction_type' => 'buy', 'trade_date' => '2025-01-01', 'quantity' => '10', 'price' => '100', 'broker_fee' => '0', 'transaction_currency' => 'USD']);

        $navSeries = app(PortfolioNavSeriesService::class);
        $twrCalc = new TwrCalculator();
        $attrSvc = new PerformanceAttributionService();

        $window = $navSeries->buildWindowSeries($user, $portfolio, null, 'IDR', 'weighted_average', 'CUSTOM', '2025-01-01', '2025-02-01');
        $twr = $twrCalc->calculate($window);
        $attr = $attrSvc->attribute($user, $portfolio, $window, $twr);

        $this->assertTrue($attr['has_data']);

        $bySymbol = collect($attr['by_stock'])->keyBy('symbol');

        // BBCA: +100,000 price gain, 0 FX effect, +50,000 dividend, -15,000 fee => +135,000 total P&L
        $this->assertEqualsWithDelta(100000.0, (float) $bySymbol['BBCA']['local_price_effect_rep'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $bySymbol['BBCA']['fx_effect_rep'], 0.01);
        $this->assertEqualsWithDelta(50000.0, (float) $bySymbol['BBCA']['dividend_rep'], 0.01);
        $this->assertEqualsWithDelta(-15000.0, (float) $bySymbol['BBCA']['fee_tax_rep'], 0.01);
        $this->assertEqualsWithDelta(135000.0, (float) $bySymbol['BBCA']['total_pnl_rep'], 0.01);

        // MSFT: Bought 1,000 USD @ 15,000 = 15,000,000 IDR; Ends 1,200 USD @ 16,000 = 19,200,000 IDR => +4,200,000 total.
        // Local price gain = +200 USD * 15,000 = 3,000,000 IDR; FX translation effect = 1,200 USD * 1,000 = 1,200,000 IDR!
        $this->assertEqualsWithDelta(3000000.0, (float) $bySymbol['MSFT']['local_price_effect_rep'], 0.01);
        $this->assertEqualsWithDelta(1200000.0, (float) $bySymbol['MSFT']['fx_effect_rep'], 0.01);
        $this->assertEqualsWithDelta(4200000.0, (float) $bySymbol['MSFT']['total_pnl_rep'], 0.01);

        // Sum of stock + cash contributions reconciles exactly to total Modified Dietz gain and return
        $sumStockContrib = array_sum(array_map(fn ($r) => (float) $r['contribution_pct'], $attr['by_stock']));
        $cashOtherContrib = (float) ($attr['components']['cash_fx_and_adjustments_pct'] ?? 0.0);
        $this->assertEqualsWithDelta(
            (float) $attr['reconciliation']['modified_dietz_return'],
            $sumStockContrib + $cashOtherContrib,
            1e-6,
        );
    }

    public function test_benchmark_currency_alignment_and_minimum_observation_guard_for_tracking_error(): void
    {
        $nasdaq = Exchange::query()->where('code', 'NASDAQ')->firstOrFail();
        $spx = StockIndex::query()->create([
            'code' => 'SPX',
            'name' => 'S&P 500 Index',
            'country_code' => 'US',
            'exchange_id' => $nasdaq->id,
            'currency' => 'USD',
            'index_type' => 'price',
            'is_benchmark' => true,
            'is_active' => true,
        ]);

        // Short 10-day series: USD SPX flat at 5000->5500 (+10%), USD/IDR appreciates 15,000 -> 16,500 (+10%)
        // In reporting_aligned IDR mode: FX-aligned SPX return = (1.10 * 1.10) - 1 = +21.00%
        // In native_index mode: returns +10.00% WITH currency_mismatch_warning = true
        $start = CarbonImmutable::parse('2025-03-03', 'UTC');
        $dailySeries = [];
        for ($i = 0; $i < 10; $i++) {
            $d = $start->addWeekdays($i)->toDateString();
            $spxClose = 5000.0 + ($i * (500.0 / 9.0));
            $fxRate = 15000.0 + ($i * (1500.0 / 9.0));

            IndexValueDaily::query()->create([
                'stock_index_id' => $spx->id,
                'trade_date' => $d,
                'open' => $spxClose,
                'high' => $spxClose,
                'low' => $spxClose,
                'close' => $spxClose,
                'volume' => 1000000,
                'data_source_id' => $this->sourceId,
                'is_demo' => true,
            ]);
            FxRateDaily::query()->create([
                'base_currency' => 'USD',
                'quote_currency' => 'IDR',
                'rate_date' => $d,
                'rate' => number_format($fxRate, 8, '.', ''),
                'data_source_id' => $this->sourceId,
            ]);

            $dailySeries[] = [
                'date' => $d,
                'daily_return' => $i === 0 ? '0.00000000' : '0.01000000',
                'cumulative_twr' => number_format((1.01 ** $i) - 1.0, 8, '.', ''),
            ];
        }

        $windowSeries = ['reporting_currency' => 'IDR'];
        $twrResult = ['twr_total' => end($dailySeries)['cumulative_twr'], 'daily_series' => $dailySeries];

        $svc = new BenchmarkComparisonService();
        $aligned = $svc->compare($windowSeries, $twrResult, 'SPX', 'reporting_aligned');
        $native = $svc->compare($windowSeries, $twrResult, 'SPX', 'native_index');

        $this->assertTrue($aligned['currency_aligned']);
        $this->assertTrue($aligned['currency_converted']);
        $this->assertFalse($aligned['currency_mismatch_warning']);
        $this->assertEqualsWithDelta(0.21, (float) $aligned['benchmark_return'], 1e-4);
        // < 20 sessions => tracking_error and information_ratio MUST be null
        $this->assertSame(9, $aligned['observations_count']);
        $this->assertNull($aligned['tracking_error']);
        $this->assertNull($aligned['information_ratio']);

        $this->assertFalse($native['currency_aligned']);
        $this->assertTrue($native['currency_mismatch_warning']);
        $this->assertEqualsWithDelta(0.10, (float) $native['benchmark_return'], 1e-4);

        // Extend to 25 sessions => tracking_error and information_ratio become finite strings
        for ($i = 10; $i < 25; $i++) {
            $d = $start->addWeekdays($i)->toDateString();
            $spxClose = 5500.0 + (($i - 9) * 20.0);
            IndexValueDaily::query()->create([
                'stock_index_id' => $spx->id,
                'trade_date' => $d,
                'open' => $spxClose,
                'high' => $spxClose,
                'low' => $spxClose,
                'close' => $spxClose,
                'volume' => 1000000,
                'data_source_id' => $this->sourceId,
                'is_demo' => true,
            ]);
            FxRateDaily::query()->create([
                'base_currency' => 'USD',
                'quote_currency' => 'IDR',
                'rate_date' => $d,
                'rate' => '16500.00000000',
                'data_source_id' => $this->sourceId,
            ]);
            $dailySeries[] = [
                'date' => $d,
                'daily_return' => ($i % 2 === 0) ? '0.01500000' : '-0.00500000',
                'cumulative_twr' => '0.15000000',
            ];
        }

        $longCmp = $svc->compare($windowSeries, ['twr_total' => '0.15000000', 'daily_series' => $dailySeries], 'SPX', 'reporting_aligned');
        $this->assertGreaterThanOrEqual(20, $longCmp['observations_count']);
        $this->assertNotNull($longCmp['tracking_error']);
        $this->assertNotNull($longCmp['information_ratio']);
        $this->assertNotEmpty($longCmp['rolling_excess_20d']);
    }
}
