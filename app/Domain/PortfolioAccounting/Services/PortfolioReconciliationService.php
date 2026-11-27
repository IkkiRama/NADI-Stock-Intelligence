<?php

namespace App\Domain\PortfolioAccounting\Services;

use App\Models\BrokerageAccount;
use App\Models\Instrument;
use App\Models\PortfolioReconciliation;
use App\Models\User;
use App\Support\Math\Dec;
use DomainException;

final class PortfolioReconciliationService
{
    public function __construct(
        private readonly PortfolioAccountingService $accounting,
    ) {}

    /**
     * Compare statement cash & optional share quantities against NADI's computed ledger as of $statementDate.
     *
     * @param  list<array{symbol: string, quantity: string|float|int}>  $statementPositions
     */
    public function reconcile(
        User $user,
        BrokerageAccount $account,
        string $statementDate,
        string $statementCashBalance,
        array $statementPositions = [],
        ?string $notes = null,
        bool $createCashAdjustment = false,
    ): PortfolioReconciliation {
        if ($account->user_id !== $user->id) {
            throw new DomainException('Account does not belong to user.');
        }

        $snapshot = $this->accounting->computeSnapshot($account, $statementDate);
        $computedCash = (string) $snapshot['cash_balance'];
        $stmtCash = Dec::round($statementCashBalance, 4);
        $diff = Dec::sub($stmtCash, $computedCash, 4);

        $computedBySymbol = [];
        if (! empty($snapshot['holdings'])) {
            $symbols = Instrument::query()
                ->whereIn('id', array_keys($snapshot['holdings']))
                ->pluck('symbol', 'id');

            foreach ($snapshot['holdings'] as $id => $h) {
                $sym = strtoupper((string) ($symbols[$id] ?? ''));
                if ($sym !== '' && Dec::cmp($h['quantity'], '0') > 0) {
                    $computedBySymbol[$sym] = $h['quantity'];
                }
            }
        }

        $posDiffs = [];
        $allPositionsMatch = true;
        foreach ($statementPositions as $pos) {
            $sym = strtoupper(trim((string) ($pos['symbol'] ?? '')));
            if ($sym === '') {
                continue;
            }
            $stmtQty = Dec::round((string) ($pos['quantity'] ?? '0'), 6);
            $compQty = $computedBySymbol[$sym] ?? '0.000000';
            $qDiff = Dec::sub($stmtQty, $compQty, 6);
            $matched = Dec::cmp((string) abs((float) $qDiff), '0.0001') <= 0;
            if (! $matched) {
                $allPositionsMatch = false;
            }
            $posDiffs[] = [
                'symbol' => $sym,
                'statement_quantity' => $stmtQty,
                'computed_quantity' => $compQty,
                'difference' => $qDiff,
                'matched' => $matched,
            ];
        }

        $cashMatched = Dec::cmp((string) abs((float) $diff), '0.01') <= 0;

        if ($createCashAdjustment && ! $cashMatched) {
            $this->accounting->recordTransaction($user, $account, [
                'transaction_type' => 'cash_adjustment',
                'trade_date' => $statementDate,
                'gross_amount' => (string) abs((float) $diff),
                'net_cash_amount' => $diff,
                'transaction_currency' => $account->base_currency,
                'notes' => $notes ?: "Penyesuaian rekonsiliasi per {$statementDate}",
            ]);
        }

        $status = ($cashMatched && $allPositionsMatch) ? 'matched' : 'discrepancy';

        $account->forceFill(['last_reconciled_at' => now('UTC')])->save();

        return PortfolioReconciliation::query()->create([
            'user_id' => $user->id,
            'brokerage_account_id' => $account->id,
            'statement_date' => $statementDate,
            'statement_cash_balance' => $stmtCash,
            'computed_cash_balance' => $computedCash,
            'cash_difference' => $diff,
            'statement_positions' => $posDiffs,
            'status' => $status,
            'notes' => $notes,
            'created_at' => now('UTC'),
        ]);
    }
}
