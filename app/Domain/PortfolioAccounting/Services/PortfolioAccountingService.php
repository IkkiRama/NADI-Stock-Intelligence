<?php

namespace App\Domain\PortfolioAccounting\Services;

use App\Domain\MarketData\Services\FxConversionService;
use App\Models\BrokerageAccount;
use App\Models\Instrument;
use App\Models\PortfolioCostLot;
use App\Models\PortfolioHolding;
use App\Models\PortfolioTransaction;
use App\Models\User;
use App\Support\Math\Dec;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Auditable Double-Precision Decimal Portfolio Accounting Engine.
 *
 * Computes Weighted-Average and FIFO cost basis, cash balances, net contributions,
 * realized P&L, dividends, fees, corporate actions (splits/bonus/rights),
 * and cross-account transfers cleanly separated from deposits/withdrawals.
 */
final class PortfolioAccountingService
{
    public const TRANSACTION_TYPES = [
        'cash_deposit',
        'cash_withdrawal',
        'buy',
        'sell',
        'dividend_receipt',
        'dividend_tax',
        'fee',
        'cash_adjustment',
        'stock_transfer_out',
        'stock_transfer_in',
        'stock_split',
        'reverse_split',
        'bonus_shares',
        'rights_issue',
        'reversal',
    ];

    public function __construct(
        private readonly FxConversionService $fx,
    ) {}

    /**
     * Record a ledger transaction and atomically recompute account holdings & cost lots.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordTransaction(User $user, BrokerageAccount $account, array $data): PortfolioTransaction
    {
        if ($account->user_id !== $user->id) {
            throw new DomainException('Account does not belong to user.');
        }

        $type = (string) ($data['transaction_type'] ?? '');
        if (! in_array($type, self::TRANSACTION_TYPES, true) || $type === 'reversal') {
            throw new DomainException("Unsupported transaction type: {$type}");
        }

        $tradeDate = substr((string) ($data['trade_date'] ?? now('UTC')->toDateString()), 0, 10);
        $instrumentId = isset($data['instrument_id']) && $data['instrument_id'] ? (int) $data['instrument_id'] : null;
        $instrument = $instrumentId ? Instrument::query()->findOrFail($instrumentId) : null;

        $txCurrency = strtoupper((string) ($data['transaction_currency'] ?? $instrument?->currency ?? $account->base_currency));
        $acctCurrency = strtoupper($account->base_currency);

        $fxRate = isset($data['fx_rate_to_account']) && is_numeric($data['fx_rate_to_account']) && Dec::cmp((string) $data['fx_rate_to_account'], '0') > 0
            ? Dec::round((string) $data['fx_rate_to_account'], 8)
            : ($this->fx->rate($txCurrency, $acctCurrency, $tradeDate) ?? '1.00000000');

        $qty = isset($data['quantity']) && $data['quantity'] !== null && $data['quantity'] !== ''
            ? Dec::round((string) $data['quantity'], 6)
            : null;
        $price = isset($data['price']) && $data['price'] !== null && $data['price'] !== ''
            ? Dec::round((string) $data['price'], 6)
            : null;

        $brokerFee = Dec::round((string) ($data['broker_fee'] ?? '0'), 4);
        $exchangeFee = Dec::round((string) ($data['exchange_fee'] ?? '0'), 4);
        $taxAmount = Dec::round((string) ($data['tax_amount'] ?? '0'), 4);
        $totalCharges = Dec::add(Dec::add($brokerFee, $exchangeFee, 4), $taxAmount, 4);

        $grossAmount = isset($data['gross_amount']) && $data['gross_amount'] !== null && $data['gross_amount'] !== ''
            ? Dec::round((string) $data['gross_amount'], 4)
            : '0.0000';

        $netCash = match ($type) {
            'cash_deposit' => ($grossAmount = Dec::round((string) abs((float) ($data['gross_amount'] ?? $data['net_cash_amount'] ?? 0)), 4)),
            'cash_withdrawal' => Dec::mul(($grossAmount = Dec::round((string) abs((float) ($data['gross_amount'] ?? $data['net_cash_amount'] ?? 0)), 4)), '-1', 4),
            'buy', 'rights_issue' => (function () use ($qty, $price, $totalCharges, &$grossAmount): string {
                if ($qty === null || Dec::cmp($qty, '0') <= 0 || $price === null || Dec::cmp($price, '0') < 0) {
                    throw new DomainException('Buy/rights transaction requires positive quantity and valid price.');
                }
                $grossAmount = Dec::mul($qty, $price, 4);

                return Dec::mul(Dec::add($grossAmount, $totalCharges, 4), '-1', 4);
            })(),
            'sell' => (function () use ($qty, $price, $totalCharges, &$grossAmount): string {
                if ($qty === null || Dec::cmp($qty, '0') <= 0 || $price === null || Dec::cmp($price, '0') < 0) {
                    throw new DomainException('Sell transaction requires positive quantity and valid price.');
                }
                $grossAmount = Dec::mul($qty, $price, 4);

                return Dec::sub($grossAmount, $totalCharges, 4);
            })(),
            'dividend_receipt' => Dec::sub($grossAmount, $totalCharges, 4),
            'dividend_tax', 'fee' => (function () use ($grossAmount, $totalCharges, $data): string {
                $amt = Dec::cmp($grossAmount, '0') > 0
                    ? $grossAmount
                    : Dec::round((string) abs((float) ($data['net_cash_amount'] ?? $totalCharges)), 4);

                return Dec::mul($amt, '-1', 4);
            })(),
            'cash_adjustment' => Dec::round((string) ($data['net_cash_amount'] ?? $grossAmount), 4),
            default => '0.0000',
        };

        return DB::transaction(function () use ($user, $account, $instrument, $type, $tradeDate, $data, $qty, $price, $grossAmount, $brokerFee, $exchangeFee, $taxAmount, $netCash, $txCurrency, $fxRate): PortfolioTransaction {
            $tx = PortfolioTransaction::query()->create([
                'user_id' => $user->id,
                'portfolio_id' => $account->portfolio_id,
                'brokerage_account_id' => $account->id,
                'instrument_id' => $instrument?->id,
                'counterparty_account_id' => $data['counterparty_account_id'] ?? null,
                'transaction_type' => $type,
                'trade_date' => $tradeDate,
                'settlement_date' => $data['settlement_date'] ?? null,
                'quantity' => $qty,
                'price' => $price,
                'gross_amount' => $grossAmount,
                'broker_fee' => $brokerFee,
                'exchange_fee' => $exchangeFee,
                'tax_amount' => $taxAmount,
                'net_cash_amount' => $netCash,
                'transaction_currency' => $txCurrency,
                'fx_rate_to_account' => $fxRate,
                'ratio_numerator' => isset($data['ratio_numerator']) ? Dec::round((string) $data['ratio_numerator'], 6) : null,
                'ratio_denominator' => isset($data['ratio_denominator']) ? Dec::round((string) $data['ratio_denominator'], 6) : null,
                'import_source' => (string) ($data['import_source'] ?? 'manual'),
                'external_ref' => $data['external_ref'] ?? null,
                'dedupe_hash' => $data['dedupe_hash'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_demo' => (bool) ($data['is_demo'] ?? $account->is_demo),
            ]);

            $this->recomputeAccount($account);

            return $tx;
        });
    }

    /**
     * Transfer shares between two user-owned accounts without creating artificial realized P&L.
     *
     * @return array{out: PortfolioTransaction, in: PortfolioTransaction}
     */
    public function recordTransfer(
        User $user,
        BrokerageAccount $fromAccount,
        BrokerageAccount $toAccount,
        Instrument $instrument,
        string $tradeDate,
        string $quantity,
        ?string $notes = null,
    ): array {
        if ($fromAccount->user_id !== $user->id || $toAccount->user_id !== $user->id) {
            throw new DomainException('Both accounts must belong to the authenticated user.');
        }
        if ($fromAccount->id === $toAccount->id) {
            throw new DomainException('Source and destination accounts must differ.');
        }

        $qtyDec = Dec::round($quantity, 6);
        if (Dec::cmp($qtyDec, '0') <= 0) {
            throw new DomainException('Transfer quantity must be positive.');
        }

        return DB::transaction(function () use ($user, $fromAccount, $toAccount, $instrument, $tradeDate, $qtyDec, $notes): array {
            $snapshot = $this->computeSnapshot($fromAccount, null);
            $holding = $snapshot['holdings'][$instrument->id] ?? null;

            if (! $holding || Dec::cmp($holding['quantity'], $qtyDec) < 0) {
                throw new DomainException("Insufficient {$instrument->symbol} quantity in source account.");
            }

            $carryUnitCost = $holding['avg_cost_per_share'];
            $txCurrency = $instrument->currency;

            $outTx = PortfolioTransaction::query()->create([
                'user_id' => $user->id,
                'portfolio_id' => $fromAccount->portfolio_id,
                'brokerage_account_id' => $fromAccount->id,
                'instrument_id' => $instrument->id,
                'counterparty_account_id' => $toAccount->id,
                'transaction_type' => 'stock_transfer_out',
                'trade_date' => $tradeDate,
                'quantity' => $qtyDec,
                'price' => $carryUnitCost,
                'gross_amount' => Dec::mul($qtyDec, $carryUnitCost, 4),
                'net_cash_amount' => '0.0000',
                'transaction_currency' => $txCurrency,
                'fx_rate_to_account' => $this->fx->rate($txCurrency, $fromAccount->base_currency, $tradeDate) ?? '1.00000000',
                'import_source' => 'manual',
                'notes' => $notes ?: "Transfer ke {$toAccount->account_name}",
                'is_demo' => (bool) $fromAccount->is_demo,
            ]);

            $inTx = PortfolioTransaction::query()->create([
                'user_id' => $user->id,
                'portfolio_id' => $toAccount->portfolio_id,
                'brokerage_account_id' => $toAccount->id,
                'instrument_id' => $instrument->id,
                'counterparty_account_id' => $fromAccount->id,
                'transaction_type' => 'stock_transfer_in',
                'trade_date' => $tradeDate,
                'quantity' => $qtyDec,
                'price' => $carryUnitCost,
                'gross_amount' => Dec::mul($qtyDec, $carryUnitCost, 4),
                'net_cash_amount' => '0.0000',
                'transaction_currency' => $txCurrency,
                'fx_rate_to_account' => $this->fx->rate($txCurrency, $toAccount->base_currency, $tradeDate) ?? '1.00000000',
                'import_source' => 'manual',
                'notes' => $notes ?: "Transfer dari {$fromAccount->account_name}",
                'is_demo' => (bool) $toAccount->is_demo,
            ]);

            $this->recomputeAccount($fromAccount);
            $this->recomputeAccount($toAccount);

            return ['out' => $outTx, 'in' => $inTx];
        });
    }

    /**
     * Reverse a transaction immutably (never silently deletes financial records) and recompute.
     */
    public function reverseTransaction(User $user, PortfolioTransaction $tx, string $reason): PortfolioTransaction
    {
        if ($tx->user_id !== $user->id) {
            throw new DomainException('Transaction does not belong to user.');
        }
        if ($tx->reversed_at !== null || $tx->transaction_type === 'reversal') {
            throw new DomainException('Transaction is already reversed.');
        }

        return DB::transaction(function () use ($user, $tx, $reason): PortfolioTransaction {
            $now = now('UTC');
            $tx->forceFill([
                'reversed_at' => $now,
                'reversal_reason' => mb_substr($reason, 0, 255),
            ])->save();

            $auditReversal = PortfolioTransaction::query()->create([
                'user_id' => $user->id,
                'portfolio_id' => $tx->portfolio_id,
                'brokerage_account_id' => $tx->brokerage_account_id,
                'instrument_id' => $tx->instrument_id,
                'reversal_of_id' => $tx->id,
                'transaction_type' => 'reversal',
                'trade_date' => $tx->trade_date->toDateString(),
                'quantity' => $tx->quantity,
                'price' => $tx->price,
                'gross_amount' => $tx->gross_amount,
                'net_cash_amount' => Dec::mul((string) $tx->net_cash_amount, '-1', 4),
                'transaction_currency' => $tx->transaction_currency,
                'fx_rate_to_account' => $tx->fx_rate_to_account,
                'import_source' => 'reversal',
                'reversal_reason' => mb_substr($reason, 0, 255),
                'notes' => "Reversal #{$tx->id}: {$reason}",
                'is_demo' => (bool) $tx->is_demo,
            ]);

            $this->recomputeAccount($tx->account);

            return $auditReversal;
        });
    }

    /**
     * Recompute and persist holdings, open FIFO lots, and account cash/P&L totals.
     *
     * @return array<string, mixed>
     */
    public function recomputeAccount(BrokerageAccount $account): array
    {
        $snapshot = $this->computeSnapshot($account, null);
        $now = now('UTC');

        return DB::transaction(function () use ($account, $snapshot, $now): array {
            $account->forceFill([
                'cash_balance' => $snapshot['cash_balance'],
                'net_contributions' => $snapshot['net_contributions'],
                'realized_pnl_avg' => $snapshot['realized_pnl_avg'],
                'realized_pnl_fifo' => $snapshot['realized_pnl_fifo'],
                'dividends_received' => $snapshot['dividends_received'],
                'fees_paid' => $snapshot['fees_paid'],
            ])->save();

            $activeInstIds = [];
            PortfolioCostLot::query()->where('brokerage_account_id', $account->id)->delete();

            foreach ($snapshot['holdings'] as $instId => $h) {
                if (Dec::cmp($h['quantity'], '0') <= 0) {
                    continue;
                }
                $activeInstIds[] = $instId;

                PortfolioHolding::query()->updateOrCreate(
                    [
                        'brokerage_account_id' => $account->id,
                        'instrument_id' => $instId,
                    ],
                    [
                        'user_id' => $account->user_id,
                        'portfolio_id' => $account->portfolio_id,
                        'quantity' => $h['quantity'],
                        'currency' => $h['currency'],
                        'avg_cost_per_share' => $h['avg_cost_per_share'],
                        'total_cost_avg' => $h['total_cost_avg'],
                        'fifo_cost_per_share' => $h['fifo_cost_per_share'],
                        'total_cost_fifo' => $h['total_cost_fifo'],
                        'realized_pnl_avg' => $h['realized_pnl_avg'],
                        'realized_pnl_fifo' => $h['realized_pnl_fifo'],
                        'dividends_received' => $h['dividends_received'],
                        'is_demo' => (bool) $account->is_demo,
                        'updated_at' => $now,
                    ]
                );

                foreach ($h['lots'] as $lot) {
                    if (Dec::cmp($lot['remaining_quantity'], '0') <= 0) {
                        continue;
                    }
                    PortfolioCostLot::query()->create([
                        'brokerage_account_id' => $account->id,
                        'instrument_id' => $instId,
                        'source_transaction_id' => $lot['source_transaction_id'],
                        'acquired_date' => $lot['acquired_date'],
                        'original_quantity' => $lot['original_quantity'],
                        'remaining_quantity' => $lot['remaining_quantity'],
                        'unit_cost' => $lot['unit_cost'],
                        'total_cost' => $lot['total_cost'],
                        'currency' => $h['currency'],
                        'created_at' => $now,
                    ]);
                }
            }

            $staleHoldings = PortfolioHolding::query()->where('brokerage_account_id', $account->id);
            if (! empty($activeInstIds)) {
                $staleHoldings->whereNotIn('instrument_id', $activeInstIds);
            }
            $staleHoldings->delete();

            return $snapshot;
        });
    }

    /**
     * Pure replay computation (read-only) optionally bounded up to $asOfDate.
     *
     * @return array<string, mixed>
     */
    public function computeSnapshot(BrokerageAccount $account, ?string $asOfDate = null): array
    {
        $query = PortfolioTransaction::query()
            ->with('instrument:id,symbol,currency')
            ->where('brokerage_account_id', $account->id)
            ->whereNull('reversed_at')
            ->where('transaction_type', '<>', 'reversal')
            ->orderBy('trade_date')
            ->orderBy('id');

        if ($asOfDate !== null) {
            $query->whereDate('trade_date', '<=', $asOfDate);
        }

        $txs = $query->get();

        $cashBalance = '0.0000';
        $netContributions = '0.0000';
        $realizedAcctAvg = '0.0000';
        $realizedAcctFifo = '0.0000';
        $dividendsAcct = '0.0000';
        $feesAcct = '0.0000';

        /** @var array<int, array<string, mixed>> $positions */
        $positions = [];

        foreach ($txs as $tx) {
            $type = $tx->transaction_type;
            $fx = (string) ($tx->fx_rate_to_account ?: '1.00000000');
            $netCashAcct = Dec::mul((string) $tx->net_cash_amount, $fx, 4);
            $chargesTx = Dec::add(Dec::add((string) $tx->broker_fee, (string) $tx->exchange_fee, 4), (string) $tx->tax_amount, 4);
            $chargesAcct = Dec::mul($chargesTx, $fx, 4);

            $instId = $tx->instrument_id;
            $instCurrency = $tx->instrument?->currency ?: $tx->transaction_currency;

            $ensurePos = function () use (&$positions, $instId, $instCurrency): void {
                if ($instId !== null && ! isset($positions[$instId])) {
                    $positions[$instId] = [
                        'instrument_id' => $instId,
                        'currency' => $instCurrency,
                        'quantity' => '0.000000',
                        'total_cost_avg' => '0.0000',
                        'avg_cost_per_share' => '0.000000',
                        'total_cost_fifo' => '0.0000',
                        'fifo_cost_per_share' => '0.000000',
                        'realized_pnl_avg' => '0.0000',
                        'realized_pnl_fifo' => '0.0000',
                        'dividends_received' => '0.0000',
                        'lots' => [],
                    ];
                }
            };

            switch ($type) {
                case 'cash_deposit':
                case 'cash_withdrawal':
                    $cashBalance = Dec::add($cashBalance, $netCashAcct, 4);
                    $netContributions = Dec::add($netContributions, $netCashAcct, 4);
                    break;

                case 'cash_adjustment':
                    $cashBalance = Dec::add($cashBalance, $netCashAcct, 4);
                    break;

                case 'fee':
                    $cashBalance = Dec::add($cashBalance, $netCashAcct, 4);
                    $feesAcct = Dec::add($feesAcct, Dec::round((string) abs((float) $netCashAcct), 4), 4);
                    break;

                case 'dividend_receipt':
                    $cashBalance = Dec::add($cashBalance, $netCashAcct, 4);
                    $dividendsAcct = Dec::add($dividendsAcct, $netCashAcct, 4);
                    if ($instId !== null) {
                        $ensurePos();
                        $positions[$instId]['dividends_received'] = Dec::add($positions[$instId]['dividends_received'], $netCashAcct, 4);
                    }
                    break;

                case 'dividend_tax':
                    $cashBalance = Dec::add($cashBalance, $netCashAcct, 4);
                    $dividendsAcct = Dec::add($dividendsAcct, $netCashAcct, 4);
                    if ($instId !== null) {
                        $ensurePos();
                        $positions[$instId]['dividends_received'] = Dec::add($positions[$instId]['dividends_received'], $netCashAcct, 4);
                    }
                    break;

                case 'buy':
                case 'rights_issue':
                    if ($instId === null) {
                        throw new DomainException('Buy/rights transaction requires an instrument.');
                    }
                    $ensurePos();
                    $qty = (string) ($tx->quantity ?? '0');
                    $price = (string) ($tx->price ?? '0');
                    $gross = Dec::cmp((string) $tx->gross_amount, '0') > 0 ? (string) $tx->gross_amount : Dec::mul($qty, $price, 4);
                    $acqCost = Dec::add($gross, $chargesTx, 4);

                    $cashBalance = Dec::add($cashBalance, $netCashAcct, 4);
                    $feesAcct = Dec::add($feesAcct, $chargesAcct, 4);

                    $newQty = Dec::add($positions[$instId]['quantity'], $qty, 6);
                    $newTotalAvg = Dec::add($positions[$instId]['total_cost_avg'], $acqCost, 4);
                    $positions[$instId]['quantity'] = $newQty;
                    $positions[$instId]['total_cost_avg'] = $newTotalAvg;
                    $positions[$instId]['avg_cost_per_share'] = Dec::cmp($newQty, '0') > 0
                        ? (Dec::div($newTotalAvg, $newQty, 6) ?? '0.000000')
                        : '0.000000';

                    $unitCost = Dec::div($acqCost, $qty, 6) ?? '0.000000';
                    $positions[$instId]['lots'][] = [
                        'source_transaction_id' => $tx->id,
                        'acquired_date' => $tx->trade_date->toDateString(),
                        'original_quantity' => Dec::round($qty, 6),
                        'remaining_quantity' => Dec::round($qty, 6),
                        'unit_cost' => $unitCost,
                        'total_cost' => $acqCost,
                    ];

                    $this->syncFifoTotals($positions[$instId]);
                    break;

                case 'sell':
                    if ($instId === null) {
                        throw new DomainException('Sell transaction requires an instrument.');
                    }
                    $ensurePos();
                    $sellQty = (string) ($tx->quantity ?? '0');
                    $heldQty = $positions[$instId]['quantity'];

                    if (Dec::cmp($sellQty, '0') <= 0 || Dec::cmp($sellQty, $heldQty) > 0) {
                        $sym = $tx->instrument?->symbol ?? "#{$instId}";
                        throw new DomainException("Insufficient {$sym} shares held ({$heldQty}) to sell {$sellQty}.");
                    }

                    $price = (string) ($tx->price ?? '0');
                    $gross = Dec::cmp((string) $tx->gross_amount, '0') > 0 ? (string) $tx->gross_amount : Dec::mul($sellQty, $price, 4);
                    $netProceeds = Dec::sub($gross, $chargesTx, 4);

                    $cashBalance = Dec::add($cashBalance, $netCashAcct, 4);
                    $feesAcct = Dec::add($feesAcct, $chargesAcct, 4);

                    // Weighted-Average relief
                    if (Dec::cmp($sellQty, $heldQty) === 0) {
                        $costRelievedAvg = $positions[$instId]['total_cost_avg'];
                        $newQty = '0.000000';
                        $newTotalAvg = '0.0000';
                    } else {
                        $fraction = Dec::div($sellQty, $heldQty, 12) ?? '0';
                        $costRelievedAvg = Dec::mul($positions[$instId]['total_cost_avg'], $fraction, 4);
                        $newQty = Dec::sub($heldQty, $sellQty, 6);
                        $newTotalAvg = Dec::sub($positions[$instId]['total_cost_avg'], $costRelievedAvg, 4);
                    }

                    $pnlAvgInst = Dec::sub($netProceeds, $costRelievedAvg, 4);
                    $positions[$instId]['quantity'] = $newQty;
                    $positions[$instId]['total_cost_avg'] = $newTotalAvg;
                    $positions[$instId]['avg_cost_per_share'] = Dec::cmp($newQty, '0') > 0
                        ? (Dec::div($newTotalAvg, $newQty, 6) ?? '0.000000')
                        : '0.000000';
                    $positions[$instId]['realized_pnl_avg'] = Dec::add($positions[$instId]['realized_pnl_avg'], $pnlAvgInst, 4);
                    $realizedAcctAvg = Dec::add($realizedAcctAvg, Dec::mul($pnlAvgInst, $fx, 4), 4);

                    // FIFO relief
                    $costRelievedFifo = $this->consumeFifoLots($positions[$instId]['lots'], $sellQty);
                    $pnlFifoInst = Dec::sub($netProceeds, $costRelievedFifo, 4);
                    $positions[$instId]['realized_pnl_fifo'] = Dec::add($positions[$instId]['realized_pnl_fifo'], $pnlFifoInst, 4);
                    $realizedAcctFifo = Dec::add($realizedAcctFifo, Dec::mul($pnlFifoInst, $fx, 4), 4);

                    $this->syncFifoTotals($positions[$instId]);
                    break;

                case 'stock_transfer_out':
                    if ($instId === null) {
                        throw new DomainException('Stock transfer out requires an instrument.');
                    }
                    $ensurePos();
                    $outQty = (string) ($tx->quantity ?? '0');
                    $heldQty = $positions[$instId]['quantity'];
                    if (Dec::cmp($outQty, '0') <= 0 || Dec::cmp($outQty, $heldQty) > 0) {
                        throw new DomainException('Insufficient shares for stock transfer out.');
                    }

                    if (Dec::cmp($outQty, $heldQty) === 0) {
                        $costRelievedAvg = $positions[$instId]['total_cost_avg'];
                        $newQty = '0.000000';
                        $newTotalAvg = '0.0000';
                    } else {
                        $fraction = Dec::div($outQty, $heldQty, 12) ?? '0';
                        $costRelievedAvg = Dec::mul($positions[$instId]['total_cost_avg'], $fraction, 4);
                        $newQty = Dec::sub($heldQty, $outQty, 6);
                        $newTotalAvg = Dec::sub($positions[$instId]['total_cost_avg'], $costRelievedAvg, 4);
                    }

                    $positions[$instId]['quantity'] = $newQty;
                    $positions[$instId]['total_cost_avg'] = $newTotalAvg;
                    $positions[$instId]['avg_cost_per_share'] = Dec::cmp($newQty, '0') > 0
                        ? (Dec::div($newTotalAvg, $newQty, 6) ?? '0.000000')
                        : '0.000000';

                    $this->consumeFifoLots($positions[$instId]['lots'], $outQty);
                    $this->syncFifoTotals($positions[$instId]);

                    $netContributions = Dec::sub($netContributions, Dec::mul($costRelievedAvg, $fx, 4), 4);
                    break;

                case 'stock_transfer_in':
                    if ($instId === null) {
                        throw new DomainException('Stock transfer in requires an instrument.');
                    }
                    $ensurePos();
                    $inQty = (string) ($tx->quantity ?? '0');
                    $unitCost = (string) ($tx->price ?? '0');
                    $transferredCost = Dec::mul($inQty, $unitCost, 4);

                    $newQty = Dec::add($positions[$instId]['quantity'], $inQty, 6);
                    $newTotalAvg = Dec::add($positions[$instId]['total_cost_avg'], $transferredCost, 4);
                    $positions[$instId]['quantity'] = $newQty;
                    $positions[$instId]['total_cost_avg'] = $newTotalAvg;
                    $positions[$instId]['avg_cost_per_share'] = Dec::cmp($newQty, '0') > 0
                        ? (Dec::div($newTotalAvg, $newQty, 6) ?? '0.000000')
                        : '0.000000';

                    $positions[$instId]['lots'][] = [
                        'source_transaction_id' => $tx->id,
                        'acquired_date' => $tx->trade_date->toDateString(),
                        'original_quantity' => Dec::round($inQty, 6),
                        'remaining_quantity' => Dec::round($inQty, 6),
                        'unit_cost' => Dec::round($unitCost, 6),
                        'total_cost' => $transferredCost,
                    ];

                    $this->syncFifoTotals($positions[$instId]);
                    $netContributions = Dec::add($netContributions, Dec::mul($transferredCost, $fx, 4), 4);
                    break;

                case 'stock_split':
                case 'reverse_split':
                    if ($instId === null) {
                        continue 2;
                    }
                    $ensurePos();
                    $heldQty = $positions[$instId]['quantity'];
                    if (Dec::cmp($heldQty, '0') <= 0) {
                        continue 2;
                    }
                    $num = (string) ($tx->ratio_numerator ?? '1');
                    $den = (string) ($tx->ratio_denominator ?? '1');
                    if (Dec::cmp($num, '0') <= 0 || Dec::cmp($den, '0') <= 0) {
                        throw new DomainException('Split ratio numerator and denominator must be positive.');
                    }
                    $factor = Dec::div($num, $den, 12) ?? '1';
                    $newQty = Dec::mul($heldQty, $factor, 6);
                    $positions[$instId]['quantity'] = $newQty;
                    $positions[$instId]['avg_cost_per_share'] = Dec::cmp($newQty, '0') > 0
                        ? (Dec::div($positions[$instId]['total_cost_avg'], $newQty, 6) ?? '0.000000')
                        : '0.000000';

                    foreach ($positions[$instId]['lots'] as &$lot) {
                        if (Dec::cmp($lot['remaining_quantity'], '0') <= 0) {
                            continue;
                        }
                        $lot['original_quantity'] = Dec::mul($lot['original_quantity'], $factor, 6);
                        $lot['remaining_quantity'] = Dec::mul($lot['remaining_quantity'], $factor, 6);
                        $lot['unit_cost'] = Dec::cmp($lot['remaining_quantity'], '0') > 0
                            ? (Dec::div($lot['total_cost'], $lot['remaining_quantity'], 6) ?? '0.000000')
                            : '0.000000';
                    }
                    unset($lot);
                    $this->syncFifoTotals($positions[$instId]);
                    break;

                case 'bonus_shares':
                    if ($instId === null) {
                        continue 2;
                    }
                    $ensurePos();
                    $heldQty = $positions[$instId]['quantity'];
                    $bonusQty = (string) ($tx->quantity ?? '0');
                    if (Dec::cmp($heldQty, '0') <= 0 || Dec::cmp($bonusQty, '0') <= 0) {
                        continue 2;
                    }
                    $newQty = Dec::add($heldQty, $bonusQty, 6);
                    $factor = Dec::div($newQty, $heldQty, 12) ?? '1';
                    $positions[$instId]['quantity'] = $newQty;
                    $positions[$instId]['avg_cost_per_share'] = Dec::div($positions[$instId]['total_cost_avg'], $newQty, 6) ?? '0.000000';

                    foreach ($positions[$instId]['lots'] as &$lot) {
                        if (Dec::cmp($lot['remaining_quantity'], '0') <= 0) {
                            continue;
                        }
                        $lot['original_quantity'] = Dec::mul($lot['original_quantity'], $factor, 6);
                        $lot['remaining_quantity'] = Dec::mul($lot['remaining_quantity'], $factor, 6);
                        $lot['unit_cost'] = Dec::cmp($lot['remaining_quantity'], '0') > 0
                            ? (Dec::div($lot['total_cost'], $lot['remaining_quantity'], 6) ?? '0.000000')
                            : '0.000000';
                    }
                    unset($lot);
                    $this->syncFifoTotals($positions[$instId]);
                    break;
            }
        }

        return [
            'cash_balance' => $cashBalance,
            'net_contributions' => $netContributions,
            'realized_pnl_avg' => $realizedAcctAvg,
            'realized_pnl_fifo' => $realizedAcctFifo,
            'dividends_received' => $dividendsAcct,
            'fees_paid' => $feesAcct,
            'holdings' => $positions,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lots
     */
    private function consumeFifoLots(array &$lots, string $qtyToConsume): string
    {
        $remainingToConsume = $qtyToConsume;
        $costRelieved = '0.0000';

        foreach ($lots as &$lot) {
            if (Dec::cmp($remainingToConsume, '0') <= 0) {
                break;
            }
            if (Dec::cmp($lot['remaining_quantity'], '0') <= 0) {
                continue;
            }

            if (Dec::cmp($lot['remaining_quantity'], $remainingToConsume) <= 0) {
                $consume = $lot['remaining_quantity'];
                $lotCost = $lot['total_cost'];
                $lot['remaining_quantity'] = '0.000000';
                $lot['total_cost'] = '0.0000';
                $costRelieved = Dec::add($costRelieved, $lotCost, 4);
                $remainingToConsume = Dec::sub($remainingToConsume, $consume, 6);
            } else {
                $fraction = Dec::div($remainingToConsume, $lot['remaining_quantity'], 12) ?? '0';
                $lotCost = Dec::mul($lot['total_cost'], $fraction, 4);
                $lot['remaining_quantity'] = Dec::sub($lot['remaining_quantity'], $remainingToConsume, 6);
                $lot['total_cost'] = Dec::sub($lot['total_cost'], $lotCost, 4);
                $costRelieved = Dec::add($costRelieved, $lotCost, 4);
                $remainingToConsume = '0.000000';
            }
        }
        unset($lot);

        return $costRelieved;
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function syncFifoTotals(array &$position): void
    {
        $totalQty = '0.000000';
        $totalCost = '0.0000';
        foreach ($position['lots'] as $lot) {
            if (Dec::cmp($lot['remaining_quantity'], '0') <= 0) {
                continue;
            }
            $totalQty = Dec::add($totalQty, $lot['remaining_quantity'], 6);
            $totalCost = Dec::add($totalCost, $lot['total_cost'], 4);
        }

        $position['total_cost_fifo'] = $totalCost;
        $position['fifo_cost_per_share'] = Dec::cmp($totalQty, '0') > 0
            ? (Dec::div($totalCost, $totalQty, 6) ?? '0.000000')
            : '0.000000';
    }
}
