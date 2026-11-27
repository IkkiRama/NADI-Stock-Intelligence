<?php

namespace Tests\Unit;

use App\Domain\MarketData\Services\FxConversionService;
use App\Domain\PortfolioAccounting\Services\BrokerCsvImporter;
use App\Domain\PortfolioAccounting\Services\PortfolioAccountingService;
use App\Domain\PortfolioAccounting\Services\PortfolioReconciliationService;
use App\Models\BrokerageAccount;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\PortfolioHolding;
use App\Models\User;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrokerCsvImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_stockbit_lots_prevents_duplicates_and_reconciles_statement(): void
    {
        $this->seed([RoleSeeder::class, ExchangeSeeder::class]);
        $user = User::factory()->create();
        $portfolio = $user->ensureDefaultPortfolio();
        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();

        $bbca = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'BBCA',
            'name' => 'Bank Central Asia Tbk',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'lot_size' => 100,
            'is_active' => true,
        ]);

        $account = BrokerageAccount::query()->create([
            'user_id' => $user->id,
            'portfolio_id' => $portfolio->id,
            'account_name' => 'Stockbit CSV Test',
            'base_currency' => 'IDR',
            'cost_basis_method' => 'weighted_average',
        ]);

        $csv = <<<CSV
trade_date,transaction_type,exchange,symbol,lots,price,gross_amount,broker_fee,external_ref,notes
2026-10-01,deposit,,,,,50000000,0,DEP-1,Initial deposit
2026-10-02,beli,IDX,BBCA,10,9500,,14250,ORD-101,10 lot BBCA
2026-10-03,dividen,IDX,BBCA,,,125000,0,DIV-1,Cash dividend
CSV;

        $accounting = new PortfolioAccountingService(new FxConversionService());
        $importer = new BrokerCsvImporter($accounting, new FxConversionService());

        $first = $importer->import($user, $account, $csv, 'stockbit');
        $this->assertSame(3, $first['inserted']);
        $this->assertSame(0, $first['skipped_duplicates']);
        $this->assertEmpty($first['errors']);

        // 10 IDX lots = 1000 shares
        $holding = PortfolioHolding::query()->where('brokerage_account_id', $account->id)->where('instrument_id', $bbca->id)->firstOrFail();
        $this->assertSame('1000.000000', (string) $holding->quantity);

        // Second upload skips all 3 rows as duplicates
        $second = $importer->import($user, $account, $csv, 'stockbit');
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(3, $second['skipped_duplicates']);

        // Cash balance: 50,000,000 - (1000 * 9500 + 14,250) + 125,000 = 40,610,750.0000
        $reconciler = new PortfolioReconciliationService($accounting);
        $recMatch = $reconciler->reconcile(
            $user,
            $account,
            '2026-10-05',
            '40610750.0000',
            [['symbol' => 'BBCA', 'quantity' => '1000']],
        );
        $this->assertSame('matched', $recMatch->status);
        $this->assertSame('0.0000', (string) $recMatch->cash_difference);
    }
}
