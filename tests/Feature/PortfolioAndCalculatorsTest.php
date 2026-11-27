<?php

namespace Tests\Feature;

use App\Models\Broker;
use App\Models\BrokerageAccount;
use App\Models\Instrument;
use App\Models\PortfolioTransaction;
use App\Models\User;
use Database\Seeders\BrokerSeeder;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\DemoMarketIntelligenceSeeder;
use Database\Seeders\DemoPriceSeeder;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\MarketUniverseSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SafetyConfigSeeder;
use Database\Seeders\ScoringConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortfolioAndCalculatorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RoleSeeder::class,
            ExchangeSeeder::class,
            MarketUniverseSeeder::class,
            DataSourceSeeder::class,
            ScoringConfigSeeder::class,
            SafetyConfigSeeder::class,
            BrokerSeeder::class,
            DemoPriceSeeder::class,
            DemoMarketIntelligenceSeeder::class,
        ]);
    }

    public function test_multi_broker_portfolio_ledger_csv_reconcile_isolation_and_heatmap_scope(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $stockbit = Broker::query()->where('code', 'STOCKBIT')->firstOrFail();
        $bbca = Instrument::query()->where('symbol', 'BBCA')->firstOrFail();

        // Create brokerage account
        $this->actingAs($userA)
            ->post('/portfolio/accounts', [
                'broker_id' => $stockbit->id,
                'account_name' => 'Akun Utama User A',
                'base_currency' => 'IDR',
                'cost_basis_method' => 'weighted_average',
            ])
            ->assertRedirect();

        $acct = BrokerageAccount::query()->where('user_id', $userA->id)->firstOrFail();

        // Record deposit & buy
        $this->actingAs($userA)
            ->post('/portfolio/transactions', [
                'brokerage_account_id' => $acct->id,
                'transaction_type' => 'cash_deposit',
                'trade_date' => '2026-10-01',
                'gross_amount' => 50000000,
            ])
            ->assertRedirect();

        $this->actingAs($userA)
            ->post('/portfolio/transactions', [
                'brokerage_account_id' => $acct->id,
                'instrument_id' => $bbca->id,
                'transaction_type' => 'buy',
                'trade_date' => '2026-10-02',
                'quantity' => 1000,
                'price' => 9000,
                'broker_fee' => 15000,
            ])
            ->assertRedirect();

        // Portfolio page renders consolidated holding, stock detail shows holdingStatus, heatmap portfolio scope includes BBCA
        $this->actingAs($userA)
            ->get('/portfolio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolio/index')
                ->where('summary.holdings_count', 1)
                ->has('consolidatedHoldings', 1)
            );

        $this->actingAs($userA)
            ->get('/stocks/idx/bbca')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stocks/show')
                ->where('holdingStatus.quantity', '1000.000000')
            );

        $this->actingAs($userA)
            ->get('/heatmap?scope=portfolio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('markets/heatmap')
                ->where('filters.scope', 'portfolio')
                ->has('sectors', 1)
            );

        // User B cannot reverse User A's transaction or reconcile User A's account or import CSV into it
        $tx = PortfolioTransaction::query()->where('brokerage_account_id', $acct->id)->where('transaction_type', 'buy')->firstOrFail();
        $this->actingAs($userB)
            ->post("/portfolio/transactions/{$tx->id}/reverse", ['reason' => 'Unauthorized'])
            ->assertForbidden();

        $this->actingAs($userB)
            ->post('/portfolio/reconcile', [
                'brokerage_account_id' => $acct->id,
                'statement_date' => '2026-10-05',
                'statement_cash_balance' => 100,
            ])
            ->assertForbidden();

        // User A imports CSV & exports sanitized CSV
        $csvFile = UploadedFile::fake()->createWithContent('trades.csv', "trade_date,transaction_type,exchange,symbol,lots,price,broker_fee,external_ref\n2026-10-05,buy,IDX,TLKM,10,3000,4500,IMP-1\n");
        $this->actingAs($userA)
            ->post('/portfolio/import-csv', [
                'brokerage_account_id' => $acct->id,
                'adapter' => 'stockbit',
                'file' => $csvFile,
            ])
            ->assertRedirect();

        $exportRes = $this->actingAs($userA)->get('/portfolio/transactions/export')->assertOk();
        $exportRes->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('BBCA', $exportRes->streamedContent());

        // Calculators & redirects
        $this->actingAs($userA)
            ->get('/calculators')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tools/calculators')
                ->has('exchanges')
                ->has('fxRatesToIdr')
            );

        $this->actingAs($userA)->get('/segera/portfolio')->assertRedirect('/portfolio');
        $this->actingAs($userA)->get('/segera/calculators')->assertRedirect('/calculators');
    }
}
