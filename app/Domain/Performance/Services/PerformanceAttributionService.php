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
 * Multi-dimensional Portfolio Performance Attribution Engine.
 *
 * Decomposes Modified-Dietz portfolio return into individual Stocks (contributors
 * & detractors), Sectors, Countries, Brokerage Accounts, and Return Drivers
 * (Local Price Effect, Currency Translation FX Effect, Net Dividends, Transaction
 * Fees & Taxes, and Cash FX / Account Adjustments) with exact reconciliation.
 */
final class PerformanceAttributionService
{
    /**
     * @param  array<string, mixed>  $windowSeries
     * @param  array<string, mixed>  $twrResult
     * @return array<string, mixed>
     */
    public function attribute(
        User $user,
        Portfolio $portfolio,
        array $windowSeries,
        array $twrResult,
        ?int $accountId = null,
    ): array {
        $days = $windowSeries['days'] ?? [];
        if (empty($days) || ! ($windowSeries['has_data'] ?? false)) {
            return $this->emptyAttribution($twrResult['twr_total'] ?? null);
        }

        $targetCcy = strtoupper((string) $windowSeries['reporting_currency']);
        $startDate = (string) $windowSeries['start_date'];
        $endDate = (string) $windowSeries['end_date'];
        $beginEquity = (string) ($windowSeries['begin_equity'] ?? '0.0000');
        $endEquity = (string) ($windowSeries['end_equity'] ?? '0.0000');
        $netGainTotal = (string) ($twrResult['net_investment_gain_rep'] ?? '0.0000');

        $avgCapital = $this->computeModifiedDietzDenominator($windowSeries);
        $hasValidDenominator = $avgCapital !== null && Dec::cmp($avgCapital, '0') > 0;

        $accounts = BrokerageAccount::query()
            ->with('broker:id,code,name')
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->where('is_active', true)
            ->when($accountId !== null, fn ($q) => $q->where('id', $accountId))
            ->get()
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            return $this->emptyAttribution($twrResult['twr_total'] ?? null);
        }

        $txs = PortfolioTransaction::query()
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('brokerage_account_id', $accounts->keys()->all())
            ->whereNull('reversed_at')
            ->where('transaction_type', '<>', 'reversal')
            ->whereDate('trade_date', '<=', $endDate)
            ->orderBy('trade_date')
            ->orderBy('id')
            ->get();

        $instIds = $txs->pluck('instrument_id')->filter()->unique()->values()->all();
        $instruments = Instrument::query()
            ->with(['exchange:id,code', 'latestQuote:instrument_id,trade_date,close'])
            ->whereIn('id', empty($instIds) ? [-1] : $instIds)
            ->get(['id', 'exchange_id', 'symbol', 'name', 'sector', 'industry', 'country_code', 'currency'])
            ->keyBy('id');

        $pricesByInst = [];
        if (! empty($instIds)) {
            $firstDate = $txs->first()?->trade_date->toDateString() ?? $startDate;
            $lookback = CarbonImmutable::parse($firstDate, 'UTC')->subDays(14)->toDateString();
            $rows = InstrumentPriceDaily::query()
                ->whereIn('instrument_id', $instIds)
                ->whereDate('trade_date', '>=', $lookback)
                ->whereDate('trade_date', '<=', $endDate)
                ->orderBy('trade_date')
                ->get(['instrument_id', 'trade_date', 'close']);

            foreach ($rows as $r) {
                $pricesByInst[$r->instrument_id][$r->trade_date->toDateString()] = (string) $r->close;
            }
        }
        foreach ($instruments as $inst) {
            if ($inst->latestQuote) {
                $d = $inst->latestQuote->trade_date->toDateString();
                if ($d <= $endDate) {
                    $pricesByInst[$inst->id][$d] ??= (string) $inst->latestQuote->close;
                }
            }
        }

        $fxLookup = BatchFxLookup::load();

        // Pre-window date boundary: ending snapshot right before $startDate
        $preWindowEndDate = CarbonImmutable::parse($startDate, 'UTC')->subDay()->toDateString();

        // Replay pre-window (< startDate) shares and intra-window flows per (account_id, instrument_id)
        /** @var array<int, array<int, string>> $preQty */
        $preQty = [];
        /** @var array<int, array<int, string>> $endQty */
        $endQty = [];
        /** @var array<int, array<int, array<string, string>>> $windowFlows */
        $windowFlows = [];
        /** @var array<int, string> $acctPreCash */
        $acctPreCash = [];
        /** @var array<int, string> $acctEndCash */
        $acctEndCash = [];
        /** @var array<int, string> $acctExtFlowRep */
        $acctExtFlowRep = [];

        foreach ($accounts as $aid => $acct) {
            $acctPreCash[$aid] = '0.0000';
            $acctEndCash[$aid] = '0.0000';
            $acctExtFlowRep[$aid] = '0.0000';
        }

        $lastPriceBeforeWindow = [];
        $lastPriceAtEnd = [];

        // Initialize fallback prices sorted chronologically
        foreach ($pricesByInst as $instId => $map) {
            foreach ($map as $d => $close) {
                if ($d <= $preWindowEndDate) {
                    $lastPriceBeforeWindow[$instId] = $close;
                }
                if ($d <= $endDate) {
                    $lastPriceAtEnd[$instId] = $close;
                }
            }
        }

        foreach ($txs as $tx) {
            $aid = $tx->brokerage_account_id;
            $acct = $accounts->get($aid);
            if (! $acct) {
                continue;
            }

            $txDate = $tx->trade_date->toDateString();
            $inWindow = $txDate >= $startDate && $txDate <= $endDate;
            $fxToAcct = (string) ($tx->fx_rate_to_account ?: '1.00000000');
            $netCashAcct = Dec::mul((string) $tx->net_cash_amount, $fxToAcct, 4);
            $chargesTx = Dec::add(
                Dec::add((string) $tx->broker_fee, (string) $tx->exchange_fee, 4),
                (string) $tx->tax_amount,
                4,
            );
            $instId = $tx->instrument_id;
            $inst = $instId ? $instruments->get($instId) : null;
            $instCcy = strtoupper($inst?->currency ?: $tx->transaction_currency);
            $txCcy = strtoupper($tx->transaction_currency);

            $fxTxToRep = $fxLookup->resolve($txCcy, $targetCcy, $txDate) ?? '0';
            $fxInstToRep = $fxLookup->resolve($instCcy, $targetCcy, $txDate) ?? '0';
            $fxAcctToRep = $fxLookup->resolve(strtoupper($acct->base_currency), $targetCcy, $txDate) ?? '0';

            if (! $inWindow) {
                $acctPreCash[$aid] = Dec::add($acctPreCash[$aid], $netCashAcct, 4);
            }
            $acctEndCash[$aid] = Dec::add($acctEndCash[$aid], $netCashAcct, 4);

            if ($inWindow && in_array($tx->transaction_type, ['cash_deposit', 'cash_withdrawal'], true)) {
                $acctExtFlowRep[$aid] = Dec::add($acctExtFlowRep[$aid], Dec::mul($netCashAcct, $fxAcctToRep, 4), 4);
            }

            if ($instId === null) {
                continue;
            }

            $preQty[$aid][$instId] ??= '0.000000';
            $endQty[$aid][$instId] ??= '0.000000';
            $windowFlows[$aid][$instId] ??= [
                'buy_gross_loc' => '0.0000',
                'buy_gross_rep' => '0.0000',
                'sell_gross_loc' => '0.0000',
                'sell_gross_rep' => '0.0000',
                'transfer_net_loc' => '0.0000',
                'transfer_net_rep' => '0.0000',
                'dividend_rep' => '0.0000',
                'fee_tax_rep' => '0.0000',
                'first_fx' => $fxInstToRep,
            ];

            switch ($tx->transaction_type) {
                case 'buy':
                case 'rights_issue':
                    $qty = (string) ($tx->quantity ?? '0');
                    $price = (string) ($tx->price ?? '0');
                    $gross = Dec::cmp((string) $tx->gross_amount, '0') > 0
                        ? (string) $tx->gross_amount
                        : Dec::mul($qty, $price, 4);

                    if (! $inWindow) {
                        $preQty[$aid][$instId] = Dec::add($preQty[$aid][$instId], $qty, 6);
                        if (! isset($lastPriceBeforeWindow[$instId]) && Dec::cmp($price, '0') > 0) {
                            $lastPriceBeforeWindow[$instId] = $price;
                        }
                    } else {
                        $f = &$windowFlows[$aid][$instId];
                        $f['buy_gross_loc'] = Dec::add($f['buy_gross_loc'], $gross, 4);
                        $f['buy_gross_rep'] = Dec::add($f['buy_gross_rep'], Dec::mul($gross, $fxTxToRep, 4), 4);
                        $f['fee_tax_rep'] = Dec::sub($f['fee_tax_rep'], Dec::mul($chargesTx, $fxTxToRep, 4), 4);
                        unset($f);
                    }
                    $endQty[$aid][$instId] = Dec::add($endQty[$aid][$instId], $qty, 6);
                    if (! isset($lastPriceAtEnd[$instId]) && Dec::cmp($price, '0') > 0) {
                        $lastPriceAtEnd[$instId] = $price;
                    }
                    break;

                case 'sell':
                    $qty = (string) ($tx->quantity ?? '0');
                    $price = (string) ($tx->price ?? '0');
                    $gross = Dec::cmp((string) $tx->gross_amount, '0') > 0
                        ? (string) $tx->gross_amount
                        : Dec::mul($qty, $price, 4);

                    if (! $inWindow) {
                        $preQty[$aid][$instId] = Dec::max('0.000000', Dec::sub($preQty[$aid][$instId], $qty, 6));
                        if (! isset($lastPriceBeforeWindow[$instId]) && Dec::cmp($price, '0') > 0) {
                            $lastPriceBeforeWindow[$instId] = $price;
                        }
                    } else {
                        $f = &$windowFlows[$aid][$instId];
                        $f['sell_gross_loc'] = Dec::add($f['sell_gross_loc'], $gross, 4);
                        $f['sell_gross_rep'] = Dec::add($f['sell_gross_rep'], Dec::mul($gross, $fxTxToRep, 4), 4);
                        $f['fee_tax_rep'] = Dec::sub($f['fee_tax_rep'], Dec::mul($chargesTx, $fxTxToRep, 4), 4);
                        unset($f);
                    }
                    $endQty[$aid][$instId] = Dec::max('0.000000', Dec::sub($endQty[$aid][$instId], $qty, 6));
                    if (! isset($lastPriceAtEnd[$instId]) && Dec::cmp($price, '0') > 0) {
                        $lastPriceAtEnd[$instId] = $price;
                    }
                    break;

                case 'stock_transfer_in':
                    $qty = (string) ($tx->quantity ?? '0');
                    $pxRef = $this->priceOnOrBefore($pricesByInst[$instId] ?? [], $txDate)
                        ?? ($tx->price !== null ? (string) $tx->price : '0');
                    $valLoc = Dec::mul($qty, $pxRef, 4);
                    $valRep = Dec::mul($valLoc, $fxInstToRep, 4);

                    if (! $inWindow) {
                        $preQty[$aid][$instId] = Dec::add($preQty[$aid][$instId], $qty, 6);
                    } else {
                        $f = &$windowFlows[$aid][$instId];
                        $f['transfer_net_loc'] = Dec::add($f['transfer_net_loc'], $valLoc, 4);
                        $f['transfer_net_rep'] = Dec::add($f['transfer_net_rep'], $valRep, 4);
                        unset($f);
                        $isExternalIn = $tx->counterparty_account_id === null || ! $accounts->has($tx->counterparty_account_id);
                        if ($isExternalIn) {
                            $acctExtFlowRep[$aid] = Dec::add($acctExtFlowRep[$aid], $valRep, 4);
                        }
                    }
                    $endQty[$aid][$instId] = Dec::add($endQty[$aid][$instId], $qty, 6);
                    break;

                case 'stock_transfer_out':
                    $qty = (string) ($tx->quantity ?? '0');
                    $pxRef = $this->priceOnOrBefore($pricesByInst[$instId] ?? [], $txDate)
                        ?? ($tx->price !== null ? (string) $tx->price : '0');
                    $valLoc = Dec::mul($qty, $pxRef, 4);
                    $valRep = Dec::mul($valLoc, $fxInstToRep, 4);

                    if (! $inWindow) {
                        $preQty[$aid][$instId] = Dec::max('0.000000', Dec::sub($preQty[$aid][$instId], $qty, 6));
                    } else {
                        $f = &$windowFlows[$aid][$instId];
                        $f['transfer_net_loc'] = Dec::sub($f['transfer_net_loc'], $valLoc, 4);
                        $f['transfer_net_rep'] = Dec::sub($f['transfer_net_rep'], $valRep, 4);
                        unset($f);
                        $isExternalOut = $tx->counterparty_account_id === null || ! $accounts->has($tx->counterparty_account_id);
                        if ($isExternalOut) {
                            $acctExtFlowRep[$aid] = Dec::sub($acctExtFlowRep[$aid], $valRep, 4);
                        }
                    }
                    $endQty[$aid][$instId] = Dec::max('0.000000', Dec::sub($endQty[$aid][$instId], $qty, 6));
                    break;

                case 'stock_split':
                case 'reverse_split':
                    $factor = Dec::div((string) ($tx->ratio_numerator ?? '1'), (string) ($tx->ratio_denominator ?? '1'), 12) ?? '1';
                    if (! $inWindow) {
                        $preQty[$aid][$instId] = Dec::mul($preQty[$aid][$instId], $factor, 6);
                    }
                    $endQty[$aid][$instId] = Dec::mul($endQty[$aid][$instId], $factor, 6);
                    break;

                case 'bonus_shares':
                    $bonus = (string) ($tx->quantity ?? '0');
                    if (! $inWindow && Dec::cmp($preQty[$aid][$instId], '0') > 0) {
                        $preQty[$aid][$instId] = Dec::add($preQty[$aid][$instId], $bonus, 6);
                    }
                    if (Dec::cmp($endQty[$aid][$instId], '0') > 0) {
                        $endQty[$aid][$instId] = Dec::add($endQty[$aid][$instId], $bonus, 6);
                    }
                    break;

                case 'dividend_receipt':
                    if ($inWindow) {
                        $netDivRep = Dec::mul((string) $tx->net_cash_amount, $fxTxToRep, 4);
                        $windowFlows[$aid][$instId]['dividend_rep'] = Dec::add(
                            $windowFlows[$aid][$instId]['dividend_rep'],
                            $netDivRep,
                            4,
                        );
                    }
                    break;

                case 'dividend_tax':
                case 'fee':
                    if ($inWindow) {
                        $chargeRep = Dec::mul((string) $tx->net_cash_amount, $fxTxToRep, 4);
                        $windowFlows[$aid][$instId]['fee_tax_rep'] = Dec::add(
                            $windowFlows[$aid][$instId]['fee_tax_rep'],
                            $chargeRep,
                            4,
                        );
                    }
                    break;
            }
        }

        // Aggregate attribution per instrument and per brokerage account
        $stockAgg = [];
        $acctAgg = [];
        foreach ($accounts as $aid => $acct) {
            $fxAcctBegin = $fxLookup->resolve(strtoupper($acct->base_currency), $targetCcy, $preWindowEndDate) ?? '0';
            $fxAcctEnd = $fxLookup->resolve(strtoupper($acct->base_currency), $targetCcy, $endDate) ?? '0';

            $acctAgg[$aid] = [
                'account_id' => $aid,
                'label' => $acct->account_name,
                'broker_code' => $acct->broker?->code ?? 'MANUAL',
                'base_currency' => $acct->base_currency,
                'begin_cash_rep' => Dec::mul($acctPreCash[$aid], $fxAcctBegin, 4),
                'end_cash_rep' => Dec::mul($acctEndCash[$aid], $fxAcctEnd, 4),
                'begin_equity_rep' => Dec::mul($acctPreCash[$aid], $fxAcctBegin, 4),
                'end_equity_rep' => Dec::mul($acctEndCash[$aid], $fxAcctEnd, 4),
                'ext_flow_rep' => $acctExtFlowRep[$aid],
                'holdings_pnl_rep' => '0.0000',
                'local_price_effect_rep' => '0.0000',
                'fx_effect_rep' => '0.0000',
                'dividend_rep' => '0.0000',
                'fee_tax_rep' => '0.0000',
            ];
        }

        $totalLocalPriceRep = '0.0000';
        $totalFxEffectRep = '0.0000';
        $totalDividendRep = '0.0000';
        $totalFeeTaxRep = '0.0000';
        $totalHoldingsPnlRep = '0.0000';

        foreach ($accounts as $aid => $acct) {
            $pairInstIds = array_unique(array_merge(
                array_keys($preQty[$aid] ?? []),
                array_keys($endQty[$aid] ?? []),
                array_keys($windowFlows[$aid] ?? []),
            ));

            foreach ($pairInstIds as $instId) {
                $inst = $instruments->get($instId);
                if (! $inst) {
                    continue;
                }
                $qBegin = $preQty[$aid][$instId] ?? '0.000000';
                $qEnd = $endQty[$aid][$instId] ?? '0.000000';
                $f = $windowFlows[$aid][$instId] ?? [
                    'buy_gross_loc' => '0.0000',
                    'buy_gross_rep' => '0.0000',
                    'sell_gross_loc' => '0.0000',
                    'sell_gross_rep' => '0.0000',
                    'transfer_net_loc' => '0.0000',
                    'transfer_net_rep' => '0.0000',
                    'dividend_rep' => '0.0000',
                    'fee_tax_rep' => '0.0000',
                    'first_fx' => '1.00000000',
                ];

                $hasActivity = Dec::cmp($qBegin, '0') > 0
                    || Dec::cmp($qEnd, '0') > 0
                    || Dec::cmp($f['buy_gross_loc'], '0') !== 0
                    || Dec::cmp($f['sell_gross_loc'], '0') !== 0
                    || Dec::cmp($f['transfer_net_loc'], '0') !== 0
                    || Dec::cmp($f['dividend_rep'], '0') !== 0
                    || Dec::cmp($f['fee_tax_rep'], '0') !== 0;

                if (! $hasActivity) {
                    continue;
                }

                $instCcy = strtoupper($inst->currency);
                $fxBegin = $fxLookup->resolve($instCcy, $targetCcy, $preWindowEndDate) ?? $f['first_fx'];
                $fxEnd = $fxLookup->resolve($instCcy, $targetCcy, $endDate) ?? $fxBegin;

                $pxBegin = $lastPriceBeforeWindow[$instId] ?? '0';
                $pxEnd = $lastPriceAtEnd[$instId] ?? $pxBegin;

                $mvBeginLoc = Dec::mul($qBegin, $pxBegin, 4);
                $mvBeginRep = Dec::mul($mvBeginLoc, $fxBegin, 4);
                $mvEndLoc = Dec::mul($qEnd, $pxEnd, 4);
                $mvEndRep = Dec::mul($mvEndLoc, $fxEnd, 4);

                $acctAgg[$aid]['begin_equity_rep'] = Dec::add($acctAgg[$aid]['begin_equity_rep'], $mvBeginRep, 4);
                $acctAgg[$aid]['end_equity_rep'] = Dec::add($acctAgg[$aid]['end_equity_rep'], $mvEndRep, 4);

                // Economic capital gain in reporting currency: MV_end - MV_begin + Sells - Buys - TransfersIn
                $capPnlRep = Dec::sub(
                    Dec::add(Dec::sub($mvEndRep, $mvBeginRep, 4), $f['sell_gross_rep'], 4),
                    Dec::add($f['buy_gross_rep'], $f['transfer_net_rep'], 4),
                    4,
                );
                $capPnlLoc = Dec::sub(
                    Dec::add(Dec::sub($mvEndLoc, $mvBeginLoc, 4), $f['sell_gross_loc'], 4),
                    Dec::add($f['buy_gross_loc'], $f['transfer_net_loc'], 4),
                    4,
                );

                if ($instCcy === $targetCcy) {
                    $localEffectRep = $capPnlRep;
                    $fxEffectRep = '0.0000';
                } else {
                    $fxRef = Dec::cmp($qBegin, '0') > 0 ? $fxBegin : ($f['first_fx'] ?: $fxEnd);
                    $localEffectRep = Dec::mul($capPnlLoc, $fxRef, 4);
                    $fxEffectRep = Dec::sub($capPnlRep, $localEffectRep, 4);
                }

                $pairTotalPnl = Dec::add(Dec::add($capPnlRep, $f['dividend_rep'], 4), $f['fee_tax_rep'], 4);

                $totalLocalPriceRep = Dec::add($totalLocalPriceRep, $localEffectRep, 4);
                $totalFxEffectRep = Dec::add($totalFxEffectRep, $fxEffectRep, 4);
                $totalDividendRep = Dec::add($totalDividendRep, $f['dividend_rep'], 4);
                $totalFeeTaxRep = Dec::add($totalFeeTaxRep, $f['fee_tax_rep'], 4);
                $totalHoldingsPnlRep = Dec::add($totalHoldingsPnlRep, $pairTotalPnl, 4);

                $acctAgg[$aid]['holdings_pnl_rep'] = Dec::add($acctAgg[$aid]['holdings_pnl_rep'], $pairTotalPnl, 4);
                $acctAgg[$aid]['local_price_effect_rep'] = Dec::add($acctAgg[$aid]['local_price_effect_rep'], $localEffectRep, 4);
                $acctAgg[$aid]['fx_effect_rep'] = Dec::add($acctAgg[$aid]['fx_effect_rep'], $fxEffectRep, 4);
                $acctAgg[$aid]['dividend_rep'] = Dec::add($acctAgg[$aid]['dividend_rep'], $f['dividend_rep'], 4);
                $acctAgg[$aid]['fee_tax_rep'] = Dec::add($acctAgg[$aid]['fee_tax_rep'], $f['fee_tax_rep'], 4);

                if (! isset($stockAgg[$instId])) {
                    $stockAgg[$instId] = [
                        'instrument_id' => $inst->id,
                        'symbol' => $inst->symbol,
                        'name' => $inst->name,
                        'exchange_code' => $inst->exchange?->code ?? '',
                        'sector' => $inst->sector ?: 'Other',
                        'country_code' => $inst->country_code ?: 'OTHER',
                        'currency' => $instCcy,
                        'end_market_value_rep' => '0.0000',
                        'local_price_effect_rep' => '0.0000',
                        'fx_effect_rep' => '0.0000',
                        'dividend_rep' => '0.0000',
                        'fee_tax_rep' => '0.0000',
                        'total_pnl_rep' => '0.0000',
                    ];
                }

                $s = &$stockAgg[$instId];
                $s['end_market_value_rep'] = Dec::add($s['end_market_value_rep'], $mvEndRep, 4);
                $s['local_price_effect_rep'] = Dec::add($s['local_price_effect_rep'], $localEffectRep, 4);
                $s['fx_effect_rep'] = Dec::add($s['fx_effect_rep'], $fxEffectRep, 4);
                $s['dividend_rep'] = Dec::add($s['dividend_rep'], $f['dividend_rep'], 4);
                $s['fee_tax_rep'] = Dec::add($s['fee_tax_rep'], $f['fee_tax_rep'], 4);
                $s['total_pnl_rep'] = Dec::add($s['total_pnl_rep'], $pairTotalPnl, 4);
                unset($s);
            }
        }

        // Exact cash FX translation & non-instrument fee/adjustment residual reconciling sum to $netGainTotal
        $cashFxAndAdjustmentsRep = Dec::sub($netGainTotal, $totalHoldingsPnlRep, 4);

        $pct = fn (string $pnl): ?string => $hasValidDenominator ? Dec::div($pnl, $avgCapital, 8) : null;

        $byStock = [];
        foreach ($stockAgg as $s) {
            $s['contribution_pct'] = $pct($s['total_pnl_rep']);
            $s['local_price_pct'] = $pct($s['local_price_effect_rep']);
            $s['fx_effect_pct'] = $pct($s['fx_effect_rep']);
            $s['dividend_pct'] = $pct($s['dividend_rep']);
            $s['fee_tax_pct'] = $pct($s['fee_tax_rep']);
            $byStock[] = $s;
        }
        usort($byStock, fn ($a, $b) => Dec::cmp($b['total_pnl_rep'], $a['total_pnl_rep']));

        $topContributors = array_values(array_slice(
            array_filter($byStock, fn ($row) => Dec::cmp($row['total_pnl_rep'], '0') > 0),
            0,
            5,
        ));
        $detractors = array_values(array_filter($byStock, fn ($row) => Dec::cmp($row['total_pnl_rep'], '0') < 0));
        usort($detractors, fn ($a, $b) => Dec::cmp($a['total_pnl_rep'], $b['total_pnl_rep']));
        $topDetractors = array_slice($detractors, 0, 5);

        $bySector = $this->groupStockAttribution($byStock, 'sector', $pct);
        $byCountry = $this->groupStockAttribution($byStock, 'country_code', $pct);

        $byBroker = [];
        foreach ($acctAgg as $a) {
            $acctTotalGain = Dec::sub(Dec::sub($a['end_equity_rep'], $a['begin_equity_rep'], 4), $a['ext_flow_rep'], 4);
            $acctCashOther = Dec::sub($acctTotalGain, $a['holdings_pnl_rep'], 4);
            $byBroker[] = [
                'key' => (string) $a['account_id'],
                'label' => $a['label'].' ('.$a['base_currency'].')',
                'broker_code' => $a['broker_code'],
                'local_price_effect_rep' => $a['local_price_effect_rep'],
                'fx_effect_rep' => $a['fx_effect_rep'],
                'dividend_rep' => $a['dividend_rep'],
                'fee_tax_rep' => $a['fee_tax_rep'],
                'cash_fx_and_adjustments_rep' => $acctCashOther,
                'total_pnl_rep' => $acctTotalGain,
                'contribution_pct' => $pct($acctTotalGain),
                'end_equity_rep' => $a['end_equity_rep'],
            ];
        }
        usort($byBroker, fn ($a, $b) => Dec::cmp($b['total_pnl_rep'], $a['total_pnl_rep']));

        $modifiedDietzReturn = $pct($netGainTotal);
        $twrTotal = $twrResult['twr_total'] ?? null;
        $geometricResidual = ($twrTotal !== null && $modifiedDietzReturn !== null)
            ? Dec::sub((string) $twrTotal, $modifiedDietzReturn, 8)
            : null;

        return [
            'has_data' => true,
            'by_stock' => $byStock,
            'top_contributors' => $topContributors,
            'top_detractors' => $topDetractors,
            'by_sector' => $bySector,
            'by_country' => $byCountry,
            'by_broker' => $byBroker,
            'components' => [
                'local_price_effect_rep' => $totalLocalPriceRep,
                'local_price_pct' => $pct($totalLocalPriceRep),
                'fx_effect_rep' => $totalFxEffectRep,
                'fx_effect_pct' => $pct($totalFxEffectRep),
                'dividend_rep' => $totalDividendRep,
                'dividend_pct' => $pct($totalDividendRep),
                'fee_tax_rep' => $totalFeeTaxRep,
                'fee_tax_pct' => $pct($totalFeeTaxRep),
                'cash_fx_and_adjustments_rep' => $cashFxAndAdjustmentsRep,
                'cash_fx_and_adjustments_pct' => $pct($cashFxAndAdjustmentsRep),
                'total_gain_rep' => $netGainTotal,
                'total_contribution_pct' => $modifiedDietzReturn,
            ],
            'reconciliation' => [
                'average_capital_rep' => $avgCapital,
                'begin_equity_rep' => $beginEquity,
                'end_equity_rep' => $endEquity,
                'total_gain_rep' => $netGainTotal,
                'modified_dietz_return' => $modifiedDietzReturn,
                'twr_return' => $twrTotal,
                'geometric_linking_residual' => $geometricResidual,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $byStock
     * @param  callable(string): ?string  $pct
     * @return list<array<string, mixed>>
     */
    private function groupStockAttribution(array $byStock, string $field, callable $pct): array
    {
        $buckets = [];
        foreach ($byStock as $s) {
            $key = (string) ($s[$field] ?: 'Other');
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'key' => $key,
                    'label' => $key,
                    'positions_count' => 0,
                    'end_market_value_rep' => '0.0000',
                    'local_price_effect_rep' => '0.0000',
                    'fx_effect_rep' => '0.0000',
                    'dividend_rep' => '0.0000',
                    'fee_tax_rep' => '0.0000',
                    'total_pnl_rep' => '0.0000',
                ];
            }

            $b = &$buckets[$key];
            $b['positions_count']++;
            $b['end_market_value_rep'] = Dec::add($b['end_market_value_rep'], (string) $s['end_market_value_rep'], 4);
            $b['local_price_effect_rep'] = Dec::add($b['local_price_effect_rep'], (string) $s['local_price_effect_rep'], 4);
            $b['fx_effect_rep'] = Dec::add($b['fx_effect_rep'], (string) $s['fx_effect_rep'], 4);
            $b['dividend_rep'] = Dec::add($b['dividend_rep'], (string) $s['dividend_rep'], 4);
            $b['fee_tax_rep'] = Dec::add($b['fee_tax_rep'], (string) $s['fee_tax_rep'], 4);
            $b['total_pnl_rep'] = Dec::add($b['total_pnl_rep'], (string) $s['total_pnl_rep'], 4);
            unset($b);
        }

        $rows = [];
        foreach ($buckets as $b) {
            $b['contribution_pct'] = $pct($b['total_pnl_rep']);
            $rows[] = $b;
        }
        usort($rows, fn ($a, $b) => Dec::cmp($b['total_pnl_rep'], $a['total_pnl_rep']));

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $windowSeries
     */
    private function computeModifiedDietzDenominator(array $windowSeries): ?string
    {
        $days = $windowSeries['days'] ?? [];
        if (empty($days)) {
            return null;
        }

        $startDate = (string) $windowSeries['start_date'];
        $endDate = (string) $windowSeries['end_date'];
        $beginEquity = (string) ($windowSeries['begin_equity'] ?? '0.0000');

        $startObj = CarbonImmutable::parse($startDate, 'UTC');
        $endObj = CarbonImmutable::parse($endDate, 'UTC');
        $totalDays = max(1, (int) $startObj->diffInDays($endObj) + 1);

        $weightedCf = '0.00000000';
        $maxEndEq = $beginEquity;
        foreach ($days as $day) {
            $inflow = (string) $day['inflow_rep'];
            $outflow = (string) $day['outflow_rep'];
            $offset = (int) $startObj->diffInDays(CarbonImmutable::parse((string) $day['date'], 'UTC'));
            $maxEndEq = Dec::max($maxEndEq, (string) $day['end_equity']);

            if (Dec::cmp($inflow, '0') !== 0) {
                $wIn = Dec::div((string) max(0, $totalDays - $offset), (string) $totalDays, 8) ?? '0';
                $weightedCf = Dec::add($weightedCf, Dec::mul($inflow, $wIn, 8), 8);
            }
            if (Dec::cmp($outflow, '0') !== 0) {
                $wOut = Dec::div((string) max(0, $totalDays - ($offset + 1)), (string) $totalDays, 8) ?? '0';
                $weightedCf = Dec::add($weightedCf, Dec::mul($outflow, $wOut, 8), 8);
            }
        }

        $avgCap = Dec::add($beginEquity, $weightedCf, 4);
        if (Dec::cmp($avgCap, '0') > 0) {
            return $avgCap;
        }

        return Dec::cmp($maxEndEq, '0') > 0 ? $maxEndEq : null;
    }

    /**
     * @param  array<string, string>  $map
     */
    private function priceOnOrBefore(array $map, string $date): ?string
    {
        $chosen = null;
        foreach ($map as $d => $close) {
            if ($d <= $date) {
                $chosen = $close;
            } else {
                break;
            }
        }

        return $chosen;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAttribution(?string $twrTotal): array
    {
        return [
            'has_data' => false,
            'by_stock' => [],
            'top_contributors' => [],
            'top_detractors' => [],
            'by_sector' => [],
            'by_country' => [],
            'by_broker' => [],
            'components' => [
                'local_price_effect_rep' => '0.0000',
                'local_price_pct' => null,
                'fx_effect_rep' => '0.0000',
                'fx_effect_pct' => null,
                'dividend_rep' => '0.0000',
                'dividend_pct' => null,
                'fee_tax_rep' => '0.0000',
                'fee_tax_pct' => null,
                'cash_fx_and_adjustments_rep' => '0.0000',
                'cash_fx_and_adjustments_pct' => null,
                'total_gain_rep' => '0.0000',
                'total_contribution_pct' => null,
            ],
            'reconciliation' => [
                'average_capital_rep' => null,
                'begin_equity_rep' => '0.0000',
                'end_equity_rep' => '0.0000',
                'total_gain_rep' => '0.0000',
                'modified_dietz_return' => null,
                'twr_return' => $twrTotal,
                'geometric_linking_residual' => null,
            ],
        ];
    }
}
