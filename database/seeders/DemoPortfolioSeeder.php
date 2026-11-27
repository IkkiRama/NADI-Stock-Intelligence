<?php

namespace Database\Seeders;

use App\Domain\PortfolioAccounting\Services\PortfolioAccountingService;
use App\Models\Broker;
use App\Models\BrokerageAccount;
use App\Models\Instrument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoPortfolioSeeder extends Seeder
{
    public function run(PortfolioAccountingService $accounting): void
    {
        if (! config('nadi.market_data.demo.enabled', true)) {
            return;
        }

        $admin = User::query()->where('email', 'admin@nadi.test')->first();
        if (! $admin) {
            return;
        }

        $portfolio = $admin->ensureDefaultPortfolio();
        if (BrokerageAccount::query()->where('user_id', $admin->id)->exists()) {
            return;
        }

        $stockbit = Broker::query()->where('code', 'STOCKBIT')->first();
        $ibkr = Broker::query()->where('code', 'IBKR')->first();

        $bbca = Instrument::query()->where('symbol', 'BBCA')->first();
        $tlkm = Instrument::query()->where('symbol', 'TLKM')->first();
        $msft = Instrument::query()->where('symbol', 'MSFT')->first();
        $nvda = Instrument::query()->where('symbol', 'NVDA')->first();

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $dDeposit = $today->subDays(60)->toDateString();
        $dBuy1 = $today->subDays(52)->toDateString();
        $dBuy2 = $today->subDays(35)->toDateString();
        $dSell = $today->subDays(14)->toDateString();
        $dDiv = $today->subDays(7)->toDateString();

        // Account 1: IDX IDR (Stockbit Demo)
        $idxAcct = BrokerageAccount::query()->create([
            'user_id' => $admin->id,
            'portfolio_id' => $portfolio->id,
            'broker_id' => $stockbit?->id,
            'account_name' => 'Stockbit Reguler (Demo)',
            'account_label' => 'IDX-IDR',
            'base_currency' => 'IDR',
            'cost_basis_method' => 'weighted_average',
            'is_demo' => true,
        ]);

        $accounting->recordTransaction($admin, $idxAcct, [
            'transaction_type' => 'cash_deposit',
            'trade_date' => $dDeposit,
            'gross_amount' => '150000000',
            'transaction_currency' => 'IDR',
            'notes' => 'Demo setor modal awal RDN IDR',
            'is_demo' => true,
        ]);

        if ($bbca) {
            $closeBbca = $bbca->latestQuote ? (float) $bbca->latestQuote->close : 9500.0;
            $accounting->recordTransaction($admin, $idxAcct, [
                'instrument_id' => $bbca->id,
                'transaction_type' => 'buy',
                'trade_date' => $dBuy1,
                'quantity' => '4000',
                'price' => number_format($closeBbca * 0.94, 0, '.', ''),
                'broker_fee' => '55000',
                'transaction_currency' => 'IDR',
                'notes' => 'Demo pembelian tahap 1 BBCA (40 lot)',
                'is_demo' => true,
            ]);
            $accounting->recordTransaction($admin, $idxAcct, [
                'instrument_id' => $bbca->id,
                'transaction_type' => 'buy',
                'trade_date' => $dBuy2,
                'quantity' => '2000',
                'price' => number_format($closeBbca * 0.98, 0, '.', ''),
                'broker_fee' => '28000',
                'transaction_currency' => 'IDR',
                'notes' => 'Demo akumulasi BBCA (20 lot)',
                'is_demo' => true,
            ]);
            $accounting->recordTransaction($admin, $idxAcct, [
                'instrument_id' => $bbca->id,
                'transaction_type' => 'sell',
                'trade_date' => $dSell,
                'quantity' => '1000',
                'price' => number_format($closeBbca * 1.03, 0, '.', ''),
                'broker_fee' => '24000',
                'transaction_currency' => 'IDR',
                'notes' => 'Demo realisasi sebagian BBCA (10 lot)',
                'is_demo' => true,
            ]);
            $accounting->recordTransaction($admin, $idxAcct, [
                'instrument_id' => $bbca->id,
                'transaction_type' => 'dividend_receipt',
                'trade_date' => $dDiv,
                'gross_amount' => '1200000',
                'tax_amount' => '0',
                'transaction_currency' => 'IDR',
                'notes' => 'Demo penerimaan dividen tunai BBCA',
                'is_demo' => true,
            ]);
        }

        if ($tlkm) {
            $closeTlkm = $tlkm->latestQuote ? (float) $tlkm->latestQuote->close : 3000.0;
            $accounting->recordTransaction($admin, $idxAcct, [
                'instrument_id' => $tlkm->id,
                'transaction_type' => 'buy',
                'trade_date' => $dBuy2,
                'quantity' => '5000',
                'price' => number_format($closeTlkm * 0.97, 0, '.', ''),
                'broker_fee' => '22000',
                'transaction_currency' => 'IDR',
                'notes' => 'Demo pembelian TLKM (50 lot)',
                'is_demo' => true,
            ]);
        }

        // Account 2: Global USD (IBKR Demo)
        $usdAcct = BrokerageAccount::query()->create([
            'user_id' => $admin->id,
            'portfolio_id' => $portfolio->id,
            'broker_id' => $ibkr?->id,
            'account_name' => 'Interactive Brokers (Demo)',
            'account_label' => 'US-USD',
            'base_currency' => 'USD',
            'cost_basis_method' => 'fifo',
            'is_demo' => true,
        ]);

        $accounting->recordTransaction($admin, $usdAcct, [
            'transaction_type' => 'cash_deposit',
            'trade_date' => $dDeposit,
            'gross_amount' => '15000',
            'transaction_currency' => 'USD',
            'notes' => 'Demo initial USD funding',
            'is_demo' => true,
        ]);

        if ($msft) {
            $closeMsft = $msft->latestQuote ? (float) $msft->latestQuote->close : 410.0;
            $accounting->recordTransaction($admin, $usdAcct, [
                'instrument_id' => $msft->id,
                'transaction_type' => 'buy',
                'trade_date' => $dBuy1,
                'quantity' => '12',
                'price' => number_format($closeMsft * 0.95, 2, '.', ''),
                'broker_fee' => '2.50',
                'transaction_currency' => 'USD',
                'notes' => 'Demo MSFT core position',
                'is_demo' => true,
            ]);
        }

        if ($nvda) {
            $closeNvda = $nvda->latestQuote ? (float) $nvda->latestQuote->close : 120.0;
            $accounting->recordTransaction($admin, $usdAcct, [
                'instrument_id' => $nvda->id,
                'transaction_type' => 'buy',
                'trade_date' => $dBuy2,
                'quantity' => '25',
                'price' => number_format($closeNvda * 0.96, 2, '.', ''),
                'broker_fee' => '2.00',
                'transaction_currency' => 'USD',
                'notes' => 'Demo NVDA position',
                'is_demo' => true,
            ]);
        }
    }
}
