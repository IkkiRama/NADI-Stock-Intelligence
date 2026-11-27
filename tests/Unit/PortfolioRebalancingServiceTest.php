<?php

namespace Tests\Unit;

use App\Domain\Portfolio\Services\PortfolioValuationService;
use App\Domain\PortfolioAccounting\Services\PortfolioAccountingService;
use App\Domain\Risk\Services\PortfolioRebalancingService;
use App\Models\Broker;
use App\Models\BrokerageAccount;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\InstrumentQuoteLatest;
use App\Models\StockSafetyAssessment;
use App\Models\User;
use Database\Seeders\BrokerSeeder;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioRebalancingServiceTest extends TestCase
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

    public function test_two_pass_rebalancer_enforces_idx_lots_cash_buffer_and_stock_safety_suppression(): void
    {
        $user = User::factory()->create();
        $portfolio = $user->ensureDefaultPortfolio();
        $idxEx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $stockbit = Broker::query()->where('code', 'STOCKBIT')->firstOrFail();
        $ds = DataSource::query()->firstOrFail();

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
        $tlkm = Instrument::query()->create([
            'exchange_id' => $idxEx->id,
            'symbol' => 'TLKM',
            'name' => 'Telkom Indonesia Tbk',
            'type' => 'equity',
            'currency' => 'IDR',
            'sector' => 'Communication Services',
            'country_code' => 'ID',
            'lot_size' => 100,
            'is_active' => true,
        ]);
        $bbri = Instrument::query()->create([
            'exchange_id' => $idxEx->id,
            'symbol' => 'BBRI',
            'name' => 'Bank Rakyat Indonesia Tbk',
            'type' => 'equity',
            'currency' => 'IDR',
            'sector' => 'Financials',
            'country_code' => 'ID',
            'lot_size' => 100,
            'is_active' => true,
        ]);

        foreach ([[$bbca, 10000], [$tlkm, 3000], [$bbri, 4500]] as [$inst, $px]) {
            InstrumentPriceDaily::query()->create([
                'instrument_id' => $inst->id,
                'trade_date' => '2025-05-02',
                'open' => $px,
                'high' => $px,
                'low' => $px,
                'close' => $px,
                'volume' => 5000000,
                'data_source_id' => $ds->id,
            ]);
            InstrumentQuoteLatest::query()->create([
                'instrument_id' => $inst->id,
                'trade_date' => '2025-05-02',
                'close' => $px,
                'prev_close' => $px,
                'change' => 0,
                'change_pct' => 0,
                'volume' => 5000000,
                'data_source_id' => $ds->id,
                'refreshed_at' => now('UTC'),
            ]);
        }

        // Trigger Stock Safety safeguard on BBRI
        StockSafetyAssessment::query()->create([
            'instrument_id' => $bbri->id,
            'as_of_date' => '2025-05-02',
            'risk_score' => '78.00',
            'risk_category' => 'elevated',
            'safeguard_triggered' => true,
            'triggered_rules' => ['volatility_extreme'],
            'data_confidence' => '90.00',
            'missing_factors' => [],
            'calculation_version' => 'v1',
            'is_demo' => true,
            'calculated_at' => now('UTC'),
        ]);

        $acct = BrokerageAccount::query()->create([
            'user_id' => $user->id,
            'portfolio_id' => $portfolio->id,
            'broker_id' => $stockbit->id,
            'account_name' => 'Stockbit IDX',
            'base_currency' => 'IDR',
            'cost_basis_method' => 'weighted_average',
        ]);

        $accounting = app(PortfolioAccountingService::class);
        // Deposit 100,000,000 IDR and put ~85M into BBCA (85% overweight vs 30% target)
        $accounting->recordTransaction($user, $acct, [
            'transaction_type' => 'cash_deposit',
            'trade_date' => '2025-05-01',
            'gross_amount' => '100000000',
            'transaction_currency' => 'IDR',
        ]);
        $accounting->recordTransaction($user, $acct, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'buy',
            'trade_date' => '2025-05-02',
            'quantity' => '8500',
            'price' => '10000',
            'broker_fee' => '0',
            'transaction_currency' => 'IDR',
        ]);

        $valuation = app(PortfolioValuationService::class)->summarize($user, $portfolio);
        $rebalancer = app(PortfolioRebalancingService::class);

        // Targets: BBCA 30% (overweight -> SELL), TLKM 40% (underweight -> BUY), BBRI 20% (safeguard -> BLOCKED), Cash 10%
        $sim = $rebalancer->simulate(
            $user,
            $portfolio,
            $valuation,
            [
                'cash_buffer_pct' => 10.0,
                'drift_band_pct' => 1.0,
                'exclude_safety_flagged_buys' => true,
            ],
            [
                ['instrument_id' => $bbca->id, 'target_weight_pct' => 30.0],
                ['instrument_id' => $tlkm->id, 'target_weight_pct' => 40.0],
                ['instrument_id' => $bbri->id, 'target_weight_pct' => 20.0],
            ],
        );

        $this->assertTrue($sim['has_data']);
        $this->assertSame(1, $sim['safety_blocked_count']);
        $this->assertGreaterThanOrEqual((float) $sim['target_cash_rep'] - 1.0, (float) $sim['post_cash_rep']);

        $bySym = collect($sim['rows'])->keyBy('symbol');

        $this->assertSame('sell', $bySym['BBCA']['action']);
        $this->assertSame(0, (int) $bySym['BBCA']['suggested_shares'] % 100);
        $this->assertGreaterThan(0, $bySym['BBCA']['suggested_lots']);

        $this->assertSame('buy', $bySym['TLKM']['action']);
        $this->assertSame(0, (int) $bySym['TLKM']['suggested_shares'] % 100);
        $this->assertGreaterThan(0, $bySym['TLKM']['suggested_lots']);

        $this->assertSame('blocked_by_safety', $bySym['BBRI']['action']);
        $this->assertSame(0, $bySym['BBRI']['suggested_lots']);
    }
}
