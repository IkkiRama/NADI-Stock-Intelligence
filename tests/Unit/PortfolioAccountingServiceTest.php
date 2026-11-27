<?php

namespace Tests\Unit;

use App\Domain\MarketData\Services\FxConversionService;
use App\Domain\PortfolioAccounting\Services\PortfolioAccountingService;
use App\Models\BrokerageAccount;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\PortfolioHolding;
use App\Models\PortfolioTransaction;
use App\Models\User;
use App\Support\Math\Dec;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioAccountingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createUserAndAccount(string $name = 'IDX Test'): array
    {
        $this->seed([RoleSeeder::class, ExchangeSeeder::class]);
        $user = User::factory()->create();
        $portfolio = $user->ensureDefaultPortfolio();

        $account = BrokerageAccount::query()->create([
            'user_id' => $user->id,
            'portfolio_id' => $portfolio->id,
            'account_name' => $name,
            'base_currency' => 'IDR',
            'cost_basis_method' => 'weighted_average',
        ]);

        $idx = Exchange::query()->where('code', 'IDX')->firstOrFail();
        $inst = Instrument::query()->create([
            'exchange_id' => $idx->id,
            'symbol' => 'BBCA',
            'name' => 'Bank Central Asia Tbk',
            'currency' => 'IDR',
            'country_code' => 'ID',
            'lot_size' => 100,
            'is_active' => true,
        ]);

        return [$user, $account, $inst];
    }

    public function test_weighted_average_fifo_fees_dividends_splits_transfers_and_conservation_invariant(): void
    {
        [$user, $acct1, $bbca] = $this->createUserAndAccount('Broker A');
        $acct2 = BrokerageAccount::query()->create([
            'user_id' => $user->id,
            'portfolio_id' => $acct1->portfolio_id,
            'account_name' => 'Broker B',
            'base_currency' => 'IDR',
            'cost_basis_method' => 'fifo',
        ]);

        $svc = new PortfolioAccountingService(new FxConversionService());

        // 1. Deposit 100,000,000 -> affects cash & net_contributions only, zero P&L
        $svc->recordTransaction($user, $acct1, [
            'transaction_type' => 'cash_deposit',
            'trade_date' => '2026-10-01',
            'gross_amount' => '100000000',
        ]);
        $acct1->refresh();
        $this->assertSame('100000000.0000', (string) $acct1->cash_balance);
        $this->assertSame('100000000.0000', (string) $acct1->net_contributions);
        $this->assertSame('0.0000', (string) $acct1->realized_pnl_avg);

        // 2. Lot 1: Buy 1000 @ 9000 + fee 15000 => total cost 9,015,000 (unit 9015)
        $svc->recordTransaction($user, $acct1, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'buy',
            'trade_date' => '2026-10-02',
            'quantity' => '1000',
            'price' => '9000',
            'broker_fee' => '15000',
        ]);

        // 3. Lot 2: Buy 1000 @ 10000 + fee 15000 => total cost 10,015,000 (unit 10015)
        // Total cost = 19,030,000 for 2000 shares => Avg = 9515
        $svc->recordTransaction($user, $acct1, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'buy',
            'trade_date' => '2026-10-05',
            'quantity' => '1000',
            'price' => '10000',
            'broker_fee' => '15000',
        ]);

        // 4. Sell 1500 @ 11000 - fee 25000 => net proceeds = 16,500,000 - 25,000 = 16,475,000
        // Avg relieved cost = 19,030,000 * 0.75 = 14,272,500 => realized_avg = +2,202,500, remaining_avg = 4,757,500
        // FIFO relieved cost = 9,015,000 + (10,015,000 * 0.5) = 14,022,500 => realized_fifo = +2,452,500, remaining_fifo = 5,007,500
        // Conservation check: realized_avg + remaining_avg (when valued) vs realized_fifo + remaining_fifo:
        // Total net proceeds + remaining cost invariant: realized_avg - remaining_cost_avg == realized_fifo - remaining_cost_fifo?
        // Actually realized_avg + (MV - remaining_avg) == realized_fifo + (MV - remaining_fifo) == netProceeds + MV - totalInitialCost!
        $svc->recordTransaction($user, $acct1, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'sell',
            'trade_date' => '2026-10-10',
            'quantity' => '1500',
            'price' => '11000',
            'broker_fee' => '25000',
        ]);

        $acct1->refresh();
        $h = PortfolioHolding::query()->where('brokerage_account_id', $acct1->id)->firstOrFail();

        $this->assertSame('500.000000', (string) $h->quantity);
        $this->assertSame('2202500.0000', (string) $acct1->realized_pnl_avg);
        $this->assertSame('2452500.0000', (string) $acct1->realized_pnl_fifo);
        $this->assertSame('4757500.0000', (string) $h->total_cost_avg);
        $this->assertSame('5007500.0000', (string) $h->total_cost_fifo);

        // Conservation invariant at any market price (e.g., 12,000 * 500 = 6,000,000):
        $mv = '6000000.0000';
        $totalAvg = Dec::add((string) $acct1->realized_pnl_avg, Dec::sub($mv, (string) $h->total_cost_avg, 4), 4);
        $totalFifo = Dec::add((string) $acct1->realized_pnl_fifo, Dec::sub($mv, (string) $h->total_cost_fifo, 4), 4);
        $this->assertSame($totalAvg, $totalFifo);

        // 5. Stock split 2:1 -> qty becomes 1000, total_cost_avg stays 4,757,500
        $svc->recordTransaction($user, $acct1, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'stock_split',
            'trade_date' => '2026-10-12',
            'ratio_numerator' => '2',
            'ratio_denominator' => '1',
        ]);
        $h->refresh();
        $this->assertSame('1000.000000', (string) $h->quantity);
        $this->assertSame('4757500.0000', (string) $h->total_cost_avg);

        // 6. Dividend receipt 500,000 gross - 50,000 tax => +450,000 net cash & dividends
        $svc->recordTransaction($user, $acct1, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'dividend_receipt',
            'trade_date' => '2026-10-15',
            'gross_amount' => '500000',
            'tax_amount' => '50000',
        ]);
        $acct1->refresh();
        $this->assertSame('450000.0000', (string) $acct1->dividends_received);

        // 7. Transfer 400 shares from Broker A to Broker B -> no artificial realized P&L
        $realizedBeforeTransfer = (string) $acct1->realized_pnl_avg;
        $svc->recordTransfer($user, $acct1, $acct2, $bbca, '2026-10-20', '400');

        $acct1->refresh();
        $acct2->refresh();
        $h1 = PortfolioHolding::query()->where('brokerage_account_id', $acct1->id)->firstOrFail();
        $h2 = PortfolioHolding::query()->where('brokerage_account_id', $acct2->id)->firstOrFail();

        $this->assertSame($realizedBeforeTransfer, (string) $acct1->realized_pnl_avg);
        $this->assertSame('600.000000', (string) $h1->quantity);
        $this->assertSame('400.000000', (string) $h2->quantity);
        $this->assertSame('4757500.0000', Dec::add((string) $h1->total_cost_avg, (string) $h2->total_cost_avg, 4));
    }

    public function test_oversell_throws_and_immutable_reversal_blocks_dependent_sell_or_restores_state(): void
    {
        [$user, $acct, $bbca] = $this->createUserAndAccount('Guard Test');
        $svc = new PortfolioAccountingService(new FxConversionService());

        $svc->recordTransaction($user, $acct, [
            'transaction_type' => 'cash_deposit',
            'trade_date' => '2026-10-01',
            'gross_amount' => '50000000',
        ]);
        $buyTx = $svc->recordTransaction($user, $acct, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'buy',
            'trade_date' => '2026-10-02',
            'quantity' => '500',
            'price' => '10000',
        ]);
        $sellTx = $svc->recordTransaction($user, $acct, [
            'instrument_id' => $bbca->id,
            'transaction_type' => 'sell',
            'trade_date' => '2026-10-03',
            'quantity' => '200',
            'price' => '11000',
        ]);

        // Oversell blocked
        $caughtOversell = false;
        try {
            $svc->recordTransaction($user, $acct, [
                'instrument_id' => $bbca->id,
                'transaction_type' => 'sell',
                'trade_date' => '2026-10-04',
                'quantity' => '400',
                'price' => '11000',
            ]);
        } catch (DomainException) {
            $caughtOversell = true;
        }
        $this->assertTrue($caughtOversell);

        // Reversing BUY while dependent SELL is active must throw and roll back
        $caughtDependent = false;
        try {
            $svc->reverseTransaction($user, $buyTx, 'Salah input');
        } catch (DomainException) {
            $caughtDependent = true;
        }
        $this->assertTrue($caughtDependent);
        $this->assertNull($buyTx->fresh()->reversed_at);

        // Reversing SELL first restores holding back to 500 shares and creates audit reversal entry
        $auditRev = $svc->reverseTransaction($user, $sellTx, 'Koreksi penjualan');
        $this->assertSame('reversal', $auditRev->transaction_type);
        $this->assertNotNull($sellTx->fresh()->reversed_at);
        $this->assertSame(
            '500.000000',
            (string) PortfolioHolding::query()->where('brokerage_account_id', $acct->id)->firstOrFail()->quantity
        );
        $this->assertSame(4, PortfolioTransaction::query()->where('brokerage_account_id', $acct->id)->count());
    }
}
