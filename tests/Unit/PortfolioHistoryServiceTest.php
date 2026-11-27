<?php

namespace Tests\Unit;

use App\Domain\MarketData\Services\FxConversionService;
use App\Domain\Portfolio\Services\PortfolioHistoryService;
use App\Domain\PortfolioAccounting\Services\PortfolioAccountingService;
use App\Models\BrokerageAccount;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\User;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconstructs_daily_cost_basis_vs_market_value_under_avg_and_fifo(): void
    {
        $this->seed([RoleSeeder::class, ExchangeSeeder::class, DataSourceSeeder::class]);
        $user = User::factory()->create();
        $portfolio = $user->ensureDefaultPortfolio();
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $source = DataSource::query()->where('code', 'demo')->firstOrFail();

        $acct = BrokerageAccount::query()->create([
            'user_id' => $user->id,
            'portfolio_id' => $portfolio->id,
            'account_name' => 'History Test',
            'base_currency' => 'IDR',
            'cost_basis_method' => 'weighted_average',
        ]);

        $bbca = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'BBCA',
            'name' => 'Bank Central Asia Tbk',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'lot_size' => 100,
            'is_active' => true,
        ]);

        // Seed 3 daily bars for BBCA
        foreach ([
            ['2026-11-23', '9000.000000'],
            ['2026-11-24', '9500.000000'],
            ['2026-11-25', '10500.000000'],
        ] as [$d, $px]) {
            InstrumentPriceDaily::query()->create([
                'instrument_id' => $bbca->id,
                'trade_date' => $d,
                'open' => $px, 'high' => $px, 'low' => $px, 'close' => $px,
                'volume' => 10000,
                'data_source_id' => $source->id,
            ]);
        }

        $accounting = new PortfolioAccountingService(new FxConversionService());
        $accounting->recordTransaction($user, $acct, [
            'transaction_type' => 'cash_deposit',
            'trade_date' => '2026-11-23',
            'gross_amount' => '30000000',
        ]);
        // Day 1: Buy 1000 @ 9000 = 9,000,000 cost
        $accounting->recordTransaction($user, $acct, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'buy',
            'trade_date' => '2026-11-23',
            'quantity' => '1000',
            'price' => '9000',
        ]);
        // Day 2: Buy 1000 @ 10000 = +10,000,000 => 2000 shares, total cost 19,000,000
        $accounting->recordTransaction($user, $acct, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'buy',
            'trade_date' => '2026-11-24',
            'quantity' => '1000',
            'price' => '10000',
        ]);
        // Day 3: Sell 1000 @ 10500 => remaining 1000 shares: Avg cost = 9,500,000; FIFO cost = 10,000,000
        $accounting->recordTransaction($user, $acct, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'sell',
            'trade_date' => '2026-11-25',
            'quantity' => '1000',
            'price' => '10500',
        ]);

        $histSvc = new PortfolioHistoryService();
        $avgSeries = collect($histSvc->buildDailyHistory($user, $portfolio, $acct->id, 'IDR', 'weighted_average'))->keyBy('date');
        $fifoSeries = collect($histSvc->buildDailyHistory($user, $portfolio, $acct->id, 'IDR', 'fifo'))->keyBy('date');

        $this->assertSame('9000000.0000', $avgSeries['2026-11-23']['invested_cost']);
        $this->assertSame('9000000.0000', $avgSeries['2026-11-23']['market_value']);
        $this->assertSame('30000000.0000', $avgSeries['2026-11-23']['net_contributions']);

        // Day 2: 2000 shares @ close 9500 = 19,000,000 MV; cash = 30M - 9M - 10M = 11M; total_equity = 30M
        $this->assertSame('19000000.0000', $avgSeries['2026-11-24']['invested_cost']);
        $this->assertSame('19000000.0000', $avgSeries['2026-11-24']['market_value']);
        $this->assertSame('30000000.0000', $avgSeries['2026-11-24']['total_equity']);

        // Day 3: Avg invested_cost = 9,500,000 vs FIFO invested_cost = 10,000,000
        $this->assertSame('9500000.0000', $avgSeries['2026-11-25']['invested_cost']);
        $this->assertSame('10000000.0000', $fifoSeries['2026-11-25']['invested_cost']);
        $this->assertSame('10500000.0000', $avgSeries['2026-11-25']['market_value']);
    }
}
