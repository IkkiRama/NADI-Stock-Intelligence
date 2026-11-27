<?php

namespace App\Domain\Performance\Services;

use App\Domain\MarketData\Services\BatchFxLookup;
use App\Models\BrokerageAccount;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\User;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

/**
 * Reconstructs daily portfolio equity, external cash flows, and subperiod states
 * from inception using bounded batch queries (shared-hosting safe, zero N+1).
 */
final class PortfolioNavSeriesService
{
    public const VALID_RANGES = ['1M', '3M', '6M', 'YTD', '1Y', 'ALL', 'CUSTOM'];

    /**
     * Resolve clamped reporting window [start_date, end_date].
     *
     * @return array{range: string, start_date: string, end_date: string}
     */
    public function resolveRangeWindow(
        string $range,
        ?string $customStart,
        ?string $customEnd,
        string $inceptionDate,
        string $asOfDate,
    ): array {
        $normalized = strtoupper(trim($range));
        if (! in_array($normalized, self::VALID_RANGES, true)) {
            $normalized = '1Y';
        }

        $asOf = CarbonImmutable::parse($asOfDate, 'UTC')->startOfDay();
        $inception = CarbonImmutable::parse($inceptionDate, 'UTC')->startOfDay();
        if ($asOf->lt($inception)) {
            $asOf = $inception;
        }

        $end = $asOf;
        if ($normalized === 'CUSTOM' && $customEnd !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customEnd) === 1) {
            $parsedEnd = CarbonImmutable::parse($customEnd, 'UTC')->startOfDay();
            if ($parsedEnd->lt($end) && $parsedEnd->gte($inception)) {
                $end = $parsedEnd;
            }
        }

        $start = match ($normalized) {
            '1M' => $end->subMonthsNoOverflow(1),
            '3M' => $end->subMonthsNoOverflow(3),
            '6M' => $end->subMonthsNoOverflow(6),
            'YTD' => $end->startOfYear(),
            '1Y' => $end->subYearsNoOverflow(1),
            'ALL' => $inception,
            'CUSTOM' => ($customStart !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customStart) === 1)
                ? CarbonImmutable::parse($customStart, 'UTC')->startOfDay()
                : $inception,
        };

        if ($start->lt($inception)) {
            $start = $inception;
        }
        if ($start->gt($end)) {
            $start = $end;
        }

        return [
            'range' => $normalized,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ];
    }

    /**
     * Reconstruct full ledger NAV timeline and return sliced reporting window.
     *
     * @return array<string, mixed>
     */
    public function buildWindowSeries(
        User $user,
        Portfolio $portfolio,
        ?int $accountId = null,
        string $reportingCurrency = 'IDR',
        string $costBasisMethod = 'weighted_average',
        string $range = '1Y',
        ?string $customStart = null,
        ?string $customEnd = null,
    ): array {
        $targetCcy = strtoupper($reportingCurrency ?: $portfolio->reporting_currency ?: 'IDR');
        $method = in_array($costBasisMethod, ['weighted_average', 'fifo'], true)
            ? $costBasisMethod
            : ($portfolio->cost_basis_method ?: 'weighted_average');

        $accounts = BrokerageAccount::query()
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->where('is_active', true)
            ->when($accountId !== null, fn ($q) => $q->where('id', $accountId))
            ->get()
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            return $this->emptyResult($targetCcy, $method, $range);
        }

        $txs = PortfolioTransaction::query()
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('brokerage_account_id', $accounts->keys()->all())
            ->whereNull('reversed_at')
            ->where('transaction_type', '<>', 'reversal')
            ->orderBy('trade_date')
            ->orderBy('id')
            ->get();

        if ($txs->isEmpty()) {
            return $this->emptyResult($targetCcy, $method, $range);
        }

        $firstTxDate = $txs->first()->trade_date->toDateString();
        $instIds = $txs->pluck('instrument_id')->filter()->unique()->values()->all();

        $instMap = Instrument::query()
            ->with('latestQuote:instrument_id,trade_date,close,is_demo')
            ->whereIn('id', empty($instIds) ? [-1] : $instIds)
            ->get(['id', 'symbol', 'currency'])
            ->keyBy('id');

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

        $anyDemo = (bool) $accounts->contains(fn (BrokerageAccount $a): bool => (bool) $a->is_demo);
        foreach ($instMap as $inst) {
            if ($inst->latestQuote) {
                $d = $inst->latestQuote->trade_date->toDateString();
                $pricesByInst[$inst->id][$d] ??= (string) $inst->latestQuote->close;
                $allPriceDates[$d] = true;
                $anyDemo = $anyDemo || (bool) $inst->latestQuote->is_demo;
            }
        }

        $txByDate = [];
        $txDates = [];
        foreach ($txs as $tx) {
            $d = $tx->trade_date->toDateString();
            $txByDate[$d][] = $tx;
            $txDates[$d] = true;
            $anyDemo = $anyDemo || (bool) $tx->is_demo;
        }

        $dateSet = array_unique(array_merge(array_keys($allPriceDates), array_keys($txDates)));
        sort($dateSet);
        $maxDate = ! empty($dateSet) ? end($dateSet) : $firstTxDate;
        $dateSet = array_values(array_filter(
            $dateSet,
            fn (string $d): bool => $d >= $firstTxDate && $d <= $maxDate,
        ));

        if (empty($dateSet)) {
            $dateSet = [$firstTxDate];
        }

        $asOfDate = end($dateSet);
        $window = $this->resolveRangeWindow($range, $customStart, $customEnd, $firstTxDate, $asOfDate);

        $fxLookup = BatchFxLookup::load();

        $acctCash = [];
        $acctContrib = [];
        foreach ($accounts as $id => $acct) {
            $acctCash[$id] = '0.0000';
            $acctContrib[$id] = '0.0000';
        }

        /** @var array<int, array<int, array<string, mixed>>> $positions */
        $positions = [];
        $lastKnownPrice = [];
        $missingPriceSymbols = [];
        $missingFx = [];

        $prevEquity = '0.0000';
        $allDays = [];

        foreach ($dateSet as $date) {
            $dayInflowRep = '0.0000';
            $dayOutflowRep = '0.0000';

            // Refresh EOD close first so external share transfers can reference day's close if needed
            foreach ($pricesByInst as $instId => $map) {
                if (isset($map[$date])) {
                    $lastKnownPrice[$instId] = $map[$date];
                }
            }

            foreach ($txByDate[$date] ?? [] as $tx) {
                $acctId = $tx->brokerage_account_id;
                $acct = $accounts->get($acctId);
                if (! $acct) {
                    continue;
                }

                $fx = (string) ($tx->fx_rate_to_account ?: '1.00000000');
                $netCashAcct = Dec::mul((string) $tx->net_cash_amount, $fx, 4);
                $chargesTx = Dec::add(
                    Dec::add((string) $tx->broker_fee, (string) $tx->exchange_fee, 4),
                    (string) $tx->tax_amount,
                    4,
                );
                $instId = $tx->instrument_id;

                if ($instId !== null && ! isset($positions[$acctId][$instId])) {
                    $positions[$acctId][$instId] = [
                        'currency' => $instMap->get($instId)?->currency ?: $tx->transaction_currency,
                        'quantity' => '0.000000',
                        'total_cost_avg' => '0.0000',
                        'lots' => [],
                    ];
                }

                $fxAcctToRep = $fxLookup->resolve(strtoupper($acct->base_currency), $targetCcy, $date);
                if ($fxAcctToRep === null) {
                    $missingFx[] = strtoupper($acct->base_currency)."/{$targetCcy}";
                    $fxAcctToRep = '0';
                }

                switch ($tx->transaction_type) {
                    case 'cash_deposit':
                        $acctCash[$acctId] = Dec::add($acctCash[$acctId], $netCashAcct, 4);
                        $acctContrib[$acctId] = Dec::add($acctContrib[$acctId], $netCashAcct, 4);
                        $flowRep = Dec::mul($netCashAcct, $fxAcctToRep, 4);
                        if (Dec::cmp($flowRep, '0') >= 0) {
                            $dayInflowRep = Dec::add($dayInflowRep, $flowRep, 4);
                        } else {
                            $dayOutflowRep = Dec::add($dayOutflowRep, $flowRep, 4);
                        }
                        break;

                    case 'cash_withdrawal':
                        $acctCash[$acctId] = Dec::add($acctCash[$acctId], $netCashAcct, 4);
                        $acctContrib[$acctId] = Dec::add($acctContrib[$acctId], $netCashAcct, 4);
                        $flowRep = Dec::mul($netCashAcct, $fxAcctToRep, 4);
                        if (Dec::cmp($flowRep, '0') <= 0) {
                            $dayOutflowRep = Dec::add($dayOutflowRep, $flowRep, 4);
                        } else {
                            $dayInflowRep = Dec::add($dayInflowRep, $flowRep, 4);
                        }
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
                        $gross = Dec::cmp((string) $tx->gross_amount, '0') > 0
                            ? (string) $tx->gross_amount
                            : Dec::mul($qty, $price, 4);
                        $acqCost = Dec::add($gross, $chargesTx, 4);

                        $acctCash[$acctId] = Dec::add($acctCash[$acctId], $netCashAcct, 4);
                        $pos = &$positions[$acctId][$instId];
                        $pos['quantity'] = Dec::add($pos['quantity'], $qty, 6);
                        $pos['total_cost_avg'] = Dec::add($pos['total_cost_avg'], $acqCost, 4);
                        $pos['lots'][] = ['qty' => Dec::round($qty, 6), 'cost' => $acqCost];
                        unset($pos);
                        if (! isset($lastKnownPrice[$instId]) && Dec::cmp($price, '0') > 0) {
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
                        if (! isset($lastKnownPrice[$instId]) && Dec::cmp($price, '0') > 0) {
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
                        $instCcy = strtoupper((string) $pos['currency']);
                        unset($pos);
                        $acctContrib[$acctId] = Dec::sub($acctContrib[$acctId], Dec::mul($relieved, $fx, 4), 4);

                        $isExternalOut = $tx->counterparty_account_id === null || ! $accounts->has($tx->counterparty_account_id);
                        if ($isExternalOut && Dec::cmp($outQty, '0') > 0) {
                            $unitRef = $lastKnownPrice[$instId]
                                ?? ($tx->price !== null && Dec::cmp((string) $tx->price, '0') > 0 ? (string) $tx->price : Dec::div($relieved, $outQty, 6))
                                ?? '0';
                            $fxInst = $fxLookup->resolve($instCcy, $targetCcy, $date) ?? '0';
                            $outValRep = Dec::mul(Dec::mul($outQty, $unitRef, 4), $fxInst, 4);
                            $dayOutflowRep = Dec::sub($dayOutflowRep, $outValRep, 4);
                        }
                        break;

                    case 'stock_transfer_in':
                        $inQty = (string) ($tx->quantity ?? '0');
                        $unitCost = (string) ($tx->price ?? '0');
                        $cost = Dec::mul($inQty, $unitCost, 4);
                        $pos = &$positions[$acctId][$instId];
                        $pos['quantity'] = Dec::add($pos['quantity'], $inQty, 6);
                        $pos['total_cost_avg'] = Dec::add($pos['total_cost_avg'], $cost, 4);
                        $pos['lots'][] = ['qty' => Dec::round($inQty, 6), 'cost' => $cost];
                        $instCcy = strtoupper((string) $pos['currency']);
                        unset($pos);
                        $acctContrib[$acctId] = Dec::add($acctContrib[$acctId], Dec::mul($cost, $fx, 4), 4);
                        if (! isset($lastKnownPrice[$instId]) && Dec::cmp($unitCost, '0') > 0) {
                            $lastKnownPrice[$instId] = $unitCost;
                        }

                        $isExternalIn = $tx->counterparty_account_id === null || ! $accounts->has($tx->counterparty_account_id);
                        if ($isExternalIn && Dec::cmp($inQty, '0') > 0) {
                            $unitRef = $lastKnownPrice[$instId] ?? $unitCost;
                            $fxInst = $fxLookup->resolve($instCcy, $targetCcy, $date) ?? '0';
                            $inValRep = Dec::mul(Dec::mul($inQty, $unitRef, 4), $fxInst, 4);
                            $dayInflowRep = Dec::add($dayInflowRep, $inValRep, 4);
                        }
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

            $dayCash = '0.0000';
            $dayContrib = '0.0000';
            foreach ($accounts as $acctId => $acct) {
                $fxAcct = $fxLookup->resolve(strtoupper($acct->base_currency), $targetCcy, $date);
                if ($fxAcct === null) {
                    $missingFx[] = strtoupper($acct->base_currency)."/{$targetCcy}";
                    $fxAcct = '0';
                }
                $dayCash = Dec::add($dayCash, Dec::mul($acctCash[$acctId], $fxAcct, 4), 4);
                $dayContrib = Dec::add($dayContrib, Dec::mul($acctContrib[$acctId], $fxAcct, 4), 4);
            }

            $dayInvestedCost = '0.0000';
            $dayMarketValue = '0.0000';
            foreach ($positions as $instPositions) {
                foreach ($instPositions as $instId => $pos) {
                    if (Dec::cmp($pos['quantity'], '0') <= 0) {
                        continue;
                    }
                    $instCcy = strtoupper((string) $pos['currency']);
                    $fxInst = $fxLookup->resolve($instCcy, $targetCcy, $date);
                    if ($fxInst === null) {
                        $missingFx[] = "{$instCcy}/{$targetCcy}";
                        $fxInst = '0';
                    }

                    $costLocal = $method === 'fifo'
                        ? $this->sumLotCost($pos['lots'])
                        : $pos['total_cost_avg'];

                    $px = $lastKnownPrice[$instId] ?? null;
                    if ($px === null && isset($instMap[$instId])) {
                        $missingPriceSymbols[] = $instMap[$instId]->symbol;
                    }
                    $mvLocal = $px !== null ? Dec::mul($pos['quantity'], $px, 4) : $costLocal;

                    $dayInvestedCost = Dec::add($dayInvestedCost, Dec::mul($costLocal, $fxInst, 4), 4);
                    $dayMarketValue = Dec::add($dayMarketValue, Dec::mul($mvLocal, $fxInst, 4), 4);
                }
            }

            $dayEndEquity = Dec::add($dayMarketValue, $dayCash, 4);
            $extFlow = Dec::add($dayInflowRep, $dayOutflowRep, 4);

            $allDays[] = [
                'date' => $date,
                'begin_equity' => $prevEquity,
                'inflow_rep' => $dayInflowRep,
                'outflow_rep' => $dayOutflowRep,
                'external_cash_flow' => $extFlow,
                'end_equity' => $dayEndEquity,
                'market_value' => $dayMarketValue,
                'cash_balance' => $dayCash,
                'invested_cost' => $dayInvestedCost,
                'net_contributions' => $dayContrib,
            ];

            $prevEquity = $dayEndEquity;
        }

        $windowBeginEquity = '0.0000';
        $windowDays = [];
        foreach ($allDays as $day) {
            if ($day['date'] < $window['start_date']) {
                $windowBeginEquity = $day['end_equity'];
                continue;
            }
            if ($day['date'] <= $window['end_date']) {
                $windowDays[] = $day;
            }
        }

        $windowEndEquity = ! empty($windowDays) ? end($windowDays)['end_equity'] : $windowBeginEquity;

        return [
            'has_data' => ! empty($windowDays),
            'inception_date' => $firstTxDate,
            'start_date' => $window['start_date'],
            'end_date' => $window['end_date'],
            'range' => $window['range'],
            'reporting_currency' => $targetCcy,
            'cost_basis_method' => $method,
            'begin_equity' => $windowBeginEquity,
            'end_equity' => $windowEndEquity,
            'days' => $windowDays,
            'missing_price_symbols' => array_values(array_unique($missingPriceSymbols)),
            'missing_fx' => array_values(array_unique($missingFx)),
            'is_demo' => $anyDemo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $targetCcy, string $method, string $range): array
    {
        return [
            'has_data' => false,
            'inception_date' => null,
            'start_date' => null,
            'end_date' => null,
            'range' => strtoupper($range ?: '1Y'),
            'reporting_currency' => $targetCcy,
            'cost_basis_method' => $method,
            'begin_equity' => '0.0000',
            'end_equity' => '0.0000',
            'days' => [],
            'missing_price_symbols' => [],
            'missing_fx' => [],
            'is_demo' => false,
        ];
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
}
