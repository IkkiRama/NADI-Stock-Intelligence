<?php

namespace App\Domain\Portfolio\Services;

use App\Models\BrokerageAccount;
use App\Models\FxRateDaily;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\User;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

/**
 * Reconstructs daily Cost Basis vs Market Value & Portfolio Equity Curves
 * using bounded batch queries (shared-hosting safe, zero N+1 queries).
 */
final class PortfolioHistoryService
{
    /**
     * @return list<array{date: string, invested_cost: string, market_value: string, cash_balance: string, net_contributions: string, total_equity: string, unrealized_pnl: string}>
     */
    public function buildDailyHistory(
        User $user,
        Portfolio $portfolio,
        ?int $accountId = null,
        string $reportingCurrency = 'IDR',
        string $costBasisMethod = 'weighted_average',
        int $maxDays = 260,
    ): array {
        $targetCcy = strtoupper($reportingCurrency);
        $method = in_array($costBasisMethod, ['weighted_average', 'fifo'], true) ? $costBasisMethod : 'weighted_average';

        $accounts = BrokerageAccount::query()
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->where('is_active', true)
            ->when($accountId !== null, fn ($q) => $q->where('id', $accountId))
            ->get()
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            return [];
        }

        $txQuery = PortfolioTransaction::query()
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('brokerage_account_id', $accounts->keys()->all())
            ->whereNull('reversed_at')
            ->where('transaction_type', '<>', 'reversal')
            ->orderBy('trade_date')
            ->orderBy('id');

        $txs = $txQuery->get();
        if ($txs->isEmpty()) {
            return [];
        }

        $firstTxDate = $txs->first()->trade_date->toDateString();
        $instIds = $txs->pluck('instrument_id')->filter()->unique()->values()->all();

        $instMap = Instrument::query()
            ->with('latestQuote:instrument_id,trade_date,close')
            ->whereIn('id', empty($instIds) ? [-1] : $instIds)
            ->get(['id', 'currency'])
            ->keyBy('id');

        // Preload EOD prices into memory for all held/traded instruments
        $pricesByInst = [];
        $allPriceDates = [];
        if (! empty($instIds)) {
            $lookbackDate = CarbonImmutable::parse($firstTxDate, 'UTC')->subDays(14)->toDateString();
            $priceRows = InstrumentPriceDaily::query()
                ->whereIn('instrument_id', $instIds)
                ->whereDate('trade_date', '>=', $lookbackDate)
                ->orderBy('trade_date')
                ->get(['instrument_id', 'trade_date', 'close']);

            foreach ($priceRows as $pr) {
                $d = $pr->trade_date->toDateString();
                $pricesByInst[$pr->instrument_id][$d] = (string) $pr->close;
                $allPriceDates[$d] = true;
            }
        }

        foreach ($instMap as $inst) {
            if ($inst->latestQuote) {
                $d = $inst->latestQuote->trade_date->toDateString();
                $pricesByInst[$inst->id][$d] ??= (string) $inst->latestQuote->close;
                $allPriceDates[$d] = true;
            }
        }

        // Collect chronological date axis from firstTxDate through latest known date
        $endDate = now('UTC')->toDateString();
        if (! empty($allPriceDates)) {
            $maxPriceDate = max(array_keys($allPriceDates));
            if ($maxPriceDate > $endDate) {
                $endDate = $maxPriceDate;
            }
        }
        $lastTxDate = $txs->last()->trade_date->toDateString();
        if ($lastTxDate > $endDate) {
            $endDate = $lastTxDate;
        }

        $txByDate = [];
        $txDates = [];
        foreach ($txs as $tx) {
            $d = $tx->trade_date->toDateString();
            $txByDate[$d][] = $tx;
            $txDates[$d] = true;
        }

        $dateSet = array_unique(array_merge(array_keys($allPriceDates), array_keys($txDates)));
        sort($dateSet);
        $dateSet = array_values(array_filter($dateSet, fn (string $d): bool => $d >= $firstTxDate && $d <= $endDate));

        if (empty($dateSet)) {
            $dateSet = [$firstTxDate];
        }

        // Preload FX lookup table (falls back to earliest reference rate when historical FX predates demo FX seed)
        $fxLookup = $this->preloadFxRates();

        // Stateful per-account ledger replay
        $acctCash = [];
        $acctContrib = [];
        foreach ($accounts as $id => $acct) {
            $acctCash[$id] = '0.0000';
            $acctContrib[$id] = '0.0000';
        }

        /** @var array<int, array<int, array<string, mixed>>> $positions */
        $positions = [];
        $lastKnownPrice = [];

        $series = [];
        foreach ($dateSet as $date) {
            foreach ($txByDate[$date] ?? [] as $tx) {
                $acctId = $tx->brokerage_account_id;
                $acct = $accounts->get($acctId);
                if (! $acct) {
                    continue;
                }

                $fx = (string) ($tx->fx_rate_to_account ?: '1.00000000');
                $netCashAcct = Dec::mul((string) $tx->net_cash_amount, $fx, 4);
                $chargesTx = Dec::add(Dec::add((string) $tx->broker_fee, (string) $tx->exchange_fee, 4), (string) $tx->tax_amount, 4);
                $instId = $tx->instrument_id;

                if ($instId !== null && ! isset($positions[$acctId][$instId])) {
                    $positions[$acctId][$instId] = [
                        'currency' => $instMap->get($instId)?->currency ?: $tx->transaction_currency,
                        'quantity' => '0.000000',
                        'total_cost_avg' => '0.0000',
                        'lots' => [],
                    ];
                }

                switch ($tx->transaction_type) {
                    case 'cash_deposit':
                    case 'cash_withdrawal':
                        $acctCash[$acctId] = Dec::add($acctCash[$acctId], $netCashAcct, 4);
                        $acctContrib[$acctId] = Dec::add($acctContrib[$acctId], $netCashAcct, 4);
                        break;

                    case 'cash_adjustment':
                    case 'fee':
                    case 'dividend_receipt':
                    case 'dividend_tax':
                        $acctCash[$acctId] = Dec::add($acctCash[$acctId], $netCashAcct, 4);
                        break;

                    case 'buy':
                    case 'rights_issue':
                        $qty = (string) ($tx->quantity ?? '0');
                        $price = (string) ($tx->price ?? '0');
                        $gross = Dec::cmp((string) $tx->gross_amount, '0') > 0 ? (string) $tx->gross_amount : Dec::mul($qty, $price, 4);
                        $acqCost = Dec::add($gross, $chargesTx, 4);

                        $acctCash[$acctId] = Dec::add($acctCash[$acctId], $netCashAcct, 4);
                        $pos = &$positions[$acctId][$instId];
                        $pos['quantity'] = Dec::add($pos['quantity'], $qty, 6);
                        $pos['total_cost_avg'] = Dec::add($pos['total_cost_avg'], $acqCost, 4);
                        $pos['lots'][] = ['qty' => Dec::round($qty, 6), 'cost' => $acqCost];
                        unset($pos);
                        if (Dec::cmp($price, '0') > 0) {
                            $lastKnownPrice[$instId] = $price;
                        }
                        break;

                    case 'sell':
                        $sellQty = (string) ($tx->quantity ?? '0');
                        $price = (string) ($tx->price ?? '0');
                        $acctCash[$acctId] = Dec::add($acctCash[$acctId], $netCashAcct, 4);
                        $pos = &$positions[$acctId][$instId];
                        $held = $pos['quantity'];
                        if (Dec::cmp($held, '0') > 0) {
                            if (Dec::cmp($sellQty, $held) >= 0) {
                                $pos['quantity'] = '0.000000';
                                $pos['total_cost_avg'] = '0.0000';
                                $pos['lots'] = [];
                            } else {
                                $frac = Dec::div($sellQty, $held, 12) ?? '0';
                                $relieved = Dec::mul($pos['total_cost_avg'], $frac, 4);
                                $pos['quantity'] = Dec::sub($held, $sellQty, 6);
                                $pos['total_cost_avg'] = Dec::sub($pos['total_cost_avg'], $relieved, 4);
                                $this->consumeLots($pos['lots'], $sellQty);
                            }
                        }
                        unset($pos);
                        if (Dec::cmp($price, '0') > 0) {
                            $lastKnownPrice[$instId] = $price;
                        }
                        break;

                    case 'stock_transfer_out':
                        $outQty = (string) ($tx->quantity ?? '0');
                        $pos = &$positions[$acctId][$instId];
                        $held = $pos['quantity'];
                        $relieved = '0.0000';
                        if (Dec::cmp($held, '0') > 0) {
                            if (Dec::cmp($outQty, $held) >= 0) {
                                $relieved = $pos['total_cost_avg'];
                                $pos['quantity'] = '0.000000';
                                $pos['total_cost_avg'] = '0.0000';
                                $pos['lots'] = [];
                            } else {
                                $frac = Dec::div($outQty, $held, 12) ?? '0';
                                $relieved = Dec::mul($pos['total_cost_avg'], $frac, 4);
                                $pos['quantity'] = Dec::sub($held, $outQty, 6);
                                $pos['total_cost_avg'] = Dec::sub($pos['total_cost_avg'], $relieved, 4);
                                $this->consumeLots($pos['lots'], $outQty);
                            }
                        }
                        unset($pos);
                        $acctContrib[$acctId] = Dec::sub($acctContrib[$acctId], Dec::mul($relieved, $fx, 4), 4);
                        break;

                    case 'stock_transfer_in':
                        $inQty = (string) ($tx->quantity ?? '0');
                        $unitCost = (string) ($tx->price ?? '0');
                        $cost = Dec::mul($inQty, $unitCost, 4);
                        $pos = &$positions[$acctId][$instId];
                        $pos['quantity'] = Dec::add($pos['quantity'], $inQty, 6);
                        $pos['total_cost_avg'] = Dec::add($pos['total_cost_avg'], $cost, 4);
                        $pos['lots'][] = ['qty' => Dec::round($inQty, 6), 'cost' => $cost];
                        unset($pos);
                        $acctContrib[$acctId] = Dec::add($acctContrib[$acctId], Dec::mul($cost, $fx, 4), 4);
                        break;

                    case 'stock_split':
                    case 'reverse_split':
                        $pos = &$positions[$acctId][$instId];
                        if (Dec::cmp($pos['quantity'], '0') > 0) {
                            $factor = Dec::div((string) ($tx->ratio_numerator ?? '1'), (string) ($tx->ratio_denominator ?? '1'), 12) ?? '1';
                            $pos['quantity'] = Dec::mul($pos['quantity'], $factor, 6);
                            foreach ($pos['lots'] as &$lot) {
                                $lot['qty'] = Dec::mul($lot['qty'], $factor, 6);
                            }
                            unset($lot);
                        }
                        unset($pos);
                        break;

                    case 'bonus_shares':
                        $pos = &$positions[$acctId][$instId];
                        $held = $pos['quantity'];
                        $bonus = (string) ($tx->quantity ?? '0');
                        if (Dec::cmp($held, '0') > 0 && Dec::cmp($bonus, '0') > 0) {
                            $newQty = Dec::add($held, $bonus, 6);
                            $factor = Dec::div($newQty, $held, 12) ?? '1';
                            $pos['quantity'] = $newQty;
                            foreach ($pos['lots'] as &$lot) {
                                $lot['qty'] = Dec::mul($lot['qty'], $factor, 6);
                            }
                            unset($lot);
                        }
                        unset($pos);
                        break;
                }
            }

            // Refresh EOD prices known as of $date
            foreach ($pricesByInst as $instId => $map) {
                if (isset($map[$date])) {
                    $lastKnownPrice[$instId] = $map[$date];
                }
            }

            // Value holdings and cash in $targetCcy on $date
            $dayCash = '0.0000';
            $dayContrib = '0.0000';
            foreach ($accounts as $acctId => $acct) {
                $fxAcct = $this->resolveFx($fxLookup, strtoupper($acct->base_currency), $targetCcy, $date) ?? '0';
                $dayCash = Dec::add($dayCash, Dec::mul($acctCash[$acctId], $fxAcct, 4), 4);
                $dayContrib = Dec::add($dayContrib, Dec::mul($acctContrib[$acctId], $fxAcct, 4), 4);
            }

            $dayInvestedCost = '0.0000';
            $dayMarketValue = '0.0000';
            foreach ($positions as $acctId => $instPositions) {
                foreach ($instPositions as $instId => $pos) {
                    if (Dec::cmp($pos['quantity'], '0') <= 0) {
                        continue;
                    }
                    $fxInst = $this->resolveFx($fxLookup, strtoupper((string) $pos['currency']), $targetCcy, $date) ?? '0';
                    $costLocal = $method === 'fifo'
                        ? $this->sumLotCost($pos['lots'])
                        : $pos['total_cost_avg'];

                    $px = $lastKnownPrice[$instId] ?? null;
                    $mvLocal = $px !== null ? Dec::mul($pos['quantity'], $px, 4) : $costLocal;

                    $dayInvestedCost = Dec::add($dayInvestedCost, Dec::mul($costLocal, $fxInst, 4), 4);
                    $dayMarketValue = Dec::add($dayMarketValue, Dec::mul($mvLocal, $fxInst, 4), 4);
                }
            }

            $dayTotalEquity = Dec::add($dayMarketValue, $dayCash, 4);
            $dayUnrealized = Dec::sub($dayMarketValue, $dayInvestedCost, 4);

            $series[] = [
                'date' => $date,
                'invested_cost' => $dayInvestedCost,
                'market_value' => $dayMarketValue,
                'cash_balance' => $dayCash,
                'net_contributions' => $dayContrib,
                'total_equity' => $dayTotalEquity,
                'unrealized_pnl' => $dayUnrealized,
            ];
        }

        if (count($series) > $maxDays) {
            $series = array_slice($series, -$maxDays);
        }

        return $series;
    }

    /**
     * @param  list<array{qty: string, cost: string}>  $lots
     */
    private function consumeLots(array &$lots, string $qty): void
    {
        $rem = $qty;
        foreach ($lots as &$lot) {
            if (Dec::cmp($rem, '0') <= 0) {
                break;
            }
            if (Dec::cmp($lot['qty'], '0') <= 0) {
                continue;
            }
            if (Dec::cmp($lot['qty'], $rem) <= 0) {
                $rem = Dec::sub($rem, $lot['qty'], 6);
                $lot['qty'] = '0.000000';
                $lot['cost'] = '0.0000';
            } else {
                $frac = Dec::div($rem, $lot['qty'], 12) ?? '0';
                $costRel = Dec::mul($lot['cost'], $frac, 4);
                $lot['qty'] = Dec::sub($lot['qty'], $rem, 6);
                $lot['cost'] = Dec::sub($lot['cost'], $costRel, 4);
                $rem = '0.000000';
            }
        }
        unset($lot);
    }

    /**
     * @param  list<array{qty: string, cost: string}>  $lots
     */
    private function sumLotCost(array $lots): string
    {
        $sum = '0.0000';
        foreach ($lots as $lot) {
            if (Dec::cmp($lot['qty'], '0') > 0) {
                $sum = Dec::add($sum, $lot['cost'], 4);
            }
        }

        return $sum;
    }

    /**
     * @return array<string, list<array{date: string, rate: string}>>
     */
    private function preloadFxRates(): array
    {
        $map = [];
        $rows = FxRateDaily::query()
            ->orderBy('rate_date')
            ->get(['base_currency', 'quote_currency', 'rate_date', 'rate']);

        foreach ($rows as $r) {
            $key = strtoupper($r->base_currency).':'.strtoupper($r->quote_currency);
            $map[$key][] = [
                'date' => $r->rate_date->toDateString(),
                'rate' => (string) $r->rate,
            ];
        }

        return $map;
    }

    /**
     * @param  array<string, list<array{date: string, rate: string}>>  $map
     */
    private function resolveFx(array $map, string $from, string $to, string $date): ?string
    {
        if ($from === $to) {
            return '1.00000000';
        }

        $direct = $this->findPairAsOf($map, "{$from}:{$to}", $date);
        if ($direct !== null && Dec::cmp($direct, '0') > 0) {
            return $direct;
        }

        $inv = $this->findPairAsOf($map, "{$to}:{$from}", $date);
        if ($inv !== null && Dec::cmp($inv, '0') > 0) {
            return Dec::div('1', $inv, 8);
        }

        if ($from !== 'IDR' && $to !== 'IDR') {
            $fIdr = $this->resolveFx($map, $from, 'IDR', $date);
            $tIdr = $this->resolveFx($map, $to, 'IDR', $date);
            if ($fIdr !== null && $tIdr !== null && Dec::cmp($tIdr, '0') > 0) {
                return Dec::div($fIdr, $tIdr, 8);
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<array{date: string, rate: string}>>  $map
     */
    private function findPairAsOf(array $map, string $key, string $date): ?string
    {
        $entries = $map[$key] ?? null;
        if (empty($entries)) {
            return null;
        }

        $chosen = null;
        foreach ($entries as $e) {
            if ($e['date'] <= $date) {
                $chosen = $e['rate'];
            } else {
                break;
            }
        }

        return $chosen ?? $entries[0]['rate'];
    }
}
