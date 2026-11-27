<?php

namespace App\Http\Controllers;

use App\Domain\MarketData\Services\CsvSanitizer;
use App\Domain\Portfolio\Services\PortfolioValuationService;
use App\Domain\PortfolioAccounting\Services\BrokerCsvImporter;
use App\Domain\PortfolioAccounting\Services\PortfolioAccountingService;
use App\Domain\PortfolioAccounting\Services\PortfolioReconciliationService;
use App\Models\Broker;
use App\Models\BrokerageAccount;
use App\Models\Instrument;
use App\Models\PortfolioReconciliation;
use App\Models\PortfolioTransaction;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortfolioController extends Controller
{
    public function index(Request $request, PortfolioValuationService $valuation): Response
    {
        $user = $request->user();
        $portfolio = $user->ensureDefaultPortfolio();

        $accountId = $request->query('account_id') ? (int) $request->query('account_id') : null;
        if ($accountId !== null) {
            $owned = BrokerageAccount::query()->where('id', $accountId)->where('user_id', $user->id)->exists();
            if (! $owned) {
                $accountId = null;
            }
        }

        $reportingCurrency = strtoupper((string) $request->query('currency', $portfolio->reporting_currency));
        if (! in_array($reportingCurrency, config('nadi.currencies', ['IDR', 'USD', 'SGD', 'CNY', 'HKD']), true)) {
            $reportingCurrency = $portfolio->reporting_currency ?: 'IDR';
        }

        $costBasisMethod = (string) $request->query('method', $portfolio->cost_basis_method);
        if (! in_array($costBasisMethod, ['weighted_average', 'fifo'], true)) {
            $costBasisMethod = $portfolio->cost_basis_method ?: 'weighted_average';
        }

        $data = $valuation->summarize($user, $portfolio, $accountId, $reportingCurrency, $costBasisMethod);

        $txQuery = PortfolioTransaction::query()
            ->with(['instrument.exchange:id,code', 'account:id,account_name'])
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->orderByDesc('trade_date')
            ->orderByDesc('id');

        if ($accountId) {
            $txQuery->where('brokerage_account_id', $accountId);
        }

        $transactions = $txQuery->limit(80)->get()->map(fn (PortfolioTransaction $tx): array => [
            'id' => $tx->id,
            'brokerage_account_id' => $tx->brokerage_account_id,
            'account_name' => $tx->account?->account_name ?? '',
            'symbol' => $tx->instrument?->symbol,
            'exchange_code' => $tx->instrument?->exchange?->code,
            'transaction_type' => $tx->transaction_type,
            'trade_date' => $tx->trade_date->toDateString(),
            'quantity' => $tx->quantity !== null ? (string) $tx->quantity : null,
            'price' => $tx->price !== null ? (string) $tx->price : null,
            'gross_amount' => (string) $tx->gross_amount,
            'broker_fee' => (string) $tx->broker_fee,
            'exchange_fee' => (string) $tx->exchange_fee,
            'tax_amount' => (string) $tx->tax_amount,
            'net_cash_amount' => (string) $tx->net_cash_amount,
            'transaction_currency' => $tx->transaction_currency,
            'import_source' => $tx->import_source,
            'notes' => $tx->notes,
            'reversed_at' => $tx->reversed_at?->toIso8601String(),
            'reversal_reason' => $tx->reversal_reason,
            'is_demo' => (bool) $tx->is_demo,
        ])->all();

        $reconciliations = PortfolioReconciliation::query()
            ->with('account:id,account_name,base_currency')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn ($r): array => [
                'id' => $r->id,
                'account_name' => $r->account?->account_name ?? '',
                'currency' => $r->account?->base_currency ?? 'IDR',
                'statement_date' => $r->statement_date->toDateString(),
                'statement_cash_balance' => (string) $r->statement_cash_balance,
                'computed_cash_balance' => (string) $r->computed_cash_balance,
                'cash_difference' => (string) $r->cash_difference,
                'status' => $r->status,
                'notes' => $r->notes,
            ])
            ->all();

        $instruments = Instrument::query()
            ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
            ->where('instruments.is_active', true)
            ->orderBy('exchanges.id')
            ->orderBy('instruments.symbol')
            ->get(['instruments.id', 'instruments.symbol', 'instruments.name', 'instruments.currency', 'exchanges.code as exchange_code'])
            ->map(fn ($r): array => [
                'id' => (int) $r->id,
                'symbol' => $r->symbol,
                'name' => $r->name,
                'currency' => $r->currency,
                'exchange_code' => $r->exchange_code,
            ])
            ->all();

        return Inertia::render('portfolio/index', array_merge($data, [
            'brokers' => Broker::query()->where('is_active', true)->orderBy('id')->get(['id', 'code', 'name', 'country_code', 'default_currency', 'csv_adapter']),
            'transactions' => $transactions,
            'reconciliations' => $reconciliations,
            'instruments' => $instruments,
            'selectedAccountId' => $accountId,
            'currencies' => config('nadi.currencies', ['IDR', 'USD', 'SGD', 'CNY', 'HKD']),
        ]));
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'broker_id' => ['nullable', 'integer', 'exists:brokers,id'],
            'account_name' => ['required', 'string', 'max:100'],
            'account_label' => ['nullable', 'string', 'max:60'],
            'base_currency' => ['required', 'string', Rule::in(config('nadi.currencies', ['IDR', 'USD', 'SGD', 'CNY', 'HKD']))],
            'cost_basis_method' => ['required', 'string', Rule::in(['weighted_average', 'fifo'])],
        ]);

        $user = $request->user();
        $portfolio = $user->ensureDefaultPortfolio();

        BrokerageAccount::query()->create([
            'user_id' => $user->id,
            'portfolio_id' => $portfolio->id,
            'broker_id' => $validated['broker_id'] ?? null,
            'account_name' => $validated['account_name'],
            'account_label' => $validated['account_label'] ?? null,
            'base_currency' => strtoupper($validated['base_currency']),
            'cost_basis_method' => $validated['cost_basis_method'],
            'is_demo' => false,
            'is_active' => true,
        ]);

        return back()->with('status', 'account-created');
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reporting_currency' => ['required', 'string', Rule::in(config('nadi.currencies', ['IDR', 'USD', 'SGD', 'CNY', 'HKD']))],
            'cost_basis_method' => ['required', 'string', Rule::in(['weighted_average', 'fifo'])],
        ]);

        $portfolio = $request->user()->ensureDefaultPortfolio();
        $portfolio->update([
            'reporting_currency' => strtoupper($validated['reporting_currency']),
            'cost_basis_method' => $validated['cost_basis_method'],
        ]);

        return back()->with('status', 'portfolio-preferences-updated');
    }

    public function storeTransaction(Request $request, PortfolioAccountingService $accounting): RedirectResponse
    {
        $validated = $request->validate([
            'brokerage_account_id' => ['required', 'integer', 'exists:brokerage_accounts,id'],
            'counterparty_account_id' => ['nullable', 'integer', 'exists:brokerage_accounts,id'],
            'transaction_type' => ['required', 'string', Rule::in([
                'cash_deposit', 'cash_withdrawal', 'buy', 'sell',
                'dividend_receipt', 'dividend_tax', 'fee', 'cash_adjustment',
                'stock_transfer', 'stock_split', 'reverse_split', 'bonus_shares', 'rights_issue',
            ])],
            'trade_date' => ['required', 'date'],
            'instrument_id' => ['nullable', 'integer', 'exists:instruments,id'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'gross_amount' => ['nullable', 'numeric'],
            'broker_fee' => ['nullable', 'numeric', 'min:0'],
            'exchange_fee' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'ratio_numerator' => ['nullable', 'numeric', 'gt:0'],
            'ratio_denominator' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $account = BrokerageAccount::query()->findOrFail($validated['brokerage_account_id']);
        abort_if($account->user_id !== $user->id, 403);

        try {
            if ($validated['transaction_type'] === 'stock_transfer') {
                $toAccount = BrokerageAccount::query()->findOrFail($validated['counterparty_account_id'] ?? 0);
                abort_if($toAccount->user_id !== $user->id, 403);
                $instrument = Instrument::query()->findOrFail($validated['instrument_id'] ?? 0);

                $accounting->recordTransfer(
                    $user,
                    $account,
                    $toAccount,
                    $instrument,
                    substr((string) $validated['trade_date'], 0, 10),
                    (string) ($validated['quantity'] ?? '0'),
                    $validated['notes'] ?? null,
                );
            } else {
                $accounting->recordTransaction($user, $account, array_merge($validated, ['is_demo' => false]));
            }
        } catch (DomainException $e) {
            return back()->withErrors(['transaction' => $e->getMessage()]);
        }

        return back()->with('status', 'transaction-recorded');
    }

    public function reverseTransaction(
        Request $request,
        PortfolioTransaction $portfolioTransaction,
        PortfolioAccountingService $accounting,
    ): RedirectResponse {
        abort_if($portfolioTransaction->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $accounting->reverseTransaction($request->user(), $portfolioTransaction, $validated['reason']);
        } catch (DomainException $e) {
            return back()->withErrors(['reversal' => $e->getMessage()]);
        }

        return back()->with('status', 'transaction-reversed');
    }

    public function importCsv(Request $request, BrokerCsvImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'brokerage_account_id' => ['required', 'integer', 'exists:brokerage_accounts,id'],
            'adapter' => ['required', 'string', Rule::in(BrokerCsvImporter::SUPPORTED_ADAPTERS)],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $user = $request->user();
        $account = BrokerageAccount::query()->findOrFail($validated['brokerage_account_id']);
        abort_if($account->user_id !== $user->id, 403);

        $content = (string) file_get_contents($validated['file']->getRealPath());

        try {
            $summary = $importer->import($user, $account, $content, $validated['adapter']);
        } catch (DomainException $e) {
            return back()->withErrors(['csv' => $e->getMessage()]);
        }

        return back()->with('status', "import-ok:{$summary['inserted']}:{$summary['skipped_duplicates']}");
    }

    public function exportTransactions(Request $request): StreamedResponse
    {
        $user = $request->user();
        $txs = PortfolioTransaction::query()
            ->with(['instrument.exchange:id,code', 'account:id,account_name'])
            ->where('user_id', $user->id)
            ->orderByDesc('trade_date')
            ->orderByDesc('id')
            ->limit(2000)
            ->get();

        $filename = 'nadi-portfolio-ledger-'.now('UTC')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($txs): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, [
                'Account', 'TradeDate', 'Type', 'Exchange', 'Symbol', 'Quantity', 'Price',
                'GrossAmount', 'BrokerFee', 'ExchangeFee', 'TaxAmount', 'NetCashAmount',
                'Currency', 'ImportSource', 'ReversedAt', 'Notes',
            ]);

            foreach ($txs as $tx) {
                fputcsv($out, [
                    CsvSanitizer::escapeCell($tx->account?->account_name ?? ''),
                    CsvSanitizer::escapeCell($tx->trade_date->toDateString()),
                    CsvSanitizer::escapeCell($tx->transaction_type),
                    CsvSanitizer::escapeCell($tx->instrument?->exchange?->code ?? ''),
                    CsvSanitizer::escapeCell($tx->instrument?->symbol ?? ''),
                    CsvSanitizer::escapeCell($tx->quantity),
                    CsvSanitizer::escapeCell($tx->price),
                    CsvSanitizer::escapeCell($tx->gross_amount),
                    CsvSanitizer::escapeCell($tx->broker_fee),
                    CsvSanitizer::escapeCell($tx->exchange_fee),
                    CsvSanitizer::escapeCell($tx->tax_amount),
                    CsvSanitizer::escapeCell($tx->net_cash_amount),
                    CsvSanitizer::escapeCell($tx->transaction_currency),
                    CsvSanitizer::escapeCell($tx->import_source),
                    CsvSanitizer::escapeCell($tx->reversed_at?->toIso8601String() ?? ''),
                    CsvSanitizer::escapeCell($tx->notes ?? ''),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function reconcile(Request $request, PortfolioReconciliationService $reconciler): RedirectResponse
    {
        $validated = $request->validate([
            'brokerage_account_id' => ['required', 'integer', 'exists:brokerage_accounts,id'],
            'statement_date' => ['required', 'date'],
            'statement_cash_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:255'],
            'create_cash_adjustment' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $account = BrokerageAccount::query()->findOrFail($validated['brokerage_account_id']);
        abort_if($account->user_id !== $user->id, 403);

        $rec = $reconciler->reconcile(
            $user,
            $account,
            substr((string) $validated['statement_date'], 0, 10),
            (string) $validated['statement_cash_balance'],
            [],
            $validated['notes'] ?? null,
            (bool) ($validated['create_cash_adjustment'] ?? false),
        );

        return back()->with('status', "reconcile-{$rec->status}");
    }
}
