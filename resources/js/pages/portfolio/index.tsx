import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { Money } from '@/components/nadi/money';
import { AllocationPanel, type AllocationBucket } from '@/components/nadi/portfolio/allocation-panel';
import { ConcentrationPanel, type ConcentrationData } from '@/components/nadi/portfolio/concentration-panel';
import { CostVsMarketChart, type PortfolioHistoryPoint } from '@/components/nadi/portfolio/cost-vs-market-chart';
import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatNumber, formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Briefcase, Download, Lock, Plus, RotateCcw, UploadCloud } from 'lucide-react';
import { useState } from 'react';

interface PortfolioProps {
    portfolio: {
        id: number;
        name: string;
        reporting_currency: string;
        cost_basis_method: 'weighted_average' | 'fifo';
    };
    summary: {
        reporting_currency: string;
        cost_basis_method: string;
        market_value: string;
        cash_balance: string;
        total_equity: string;
        invested_cost: string;
        net_contributions: string;
        unrealized_pnl: string;
        unrealized_pnl_pct: string | null;
        realized_pnl: string;
        dividends_received: string;
        fees_paid: string;
        total_pnl: string;
        return_on_contributions_pct: string | null;
        daily_change: string;
        daily_change_pct: string | null;
        holdings_count: number;
        accounts_count: number;
        safety_flagged_count: number;
        risk_status: 'empty' | 'balanced' | 'concentrated' | 'attention_required';
        as_of: string | null;
        last_synced_at: string | null;
        is_demo: boolean;
        missing_fx: string[];
    };
    accountComparison: Array<{
        id: number;
        account_name: string;
        broker_code: string;
        broker_name: string;
        base_currency: string;
        holdings_count: number;
        cash_balance_reporting: string;
        market_value_reporting: string;
        total_cost_reporting: string;
        total_equity_reporting: string;
        net_contributions_reporting: string;
        unrealized_pnl_reporting: string;
        unrealized_pnl_pct: string | null;
        realized_pnl_reporting: string;
        dividends_received_reporting: string;
        total_pnl_reporting: string;
        portfolio_share_pct: string;
        is_demo: boolean;
    }>;
    accounts: Array<{
        id: number;
        account_name: string;
        account_label: string | null;
        broker_code: string;
        broker_name: string;
        base_currency: string;
        cash_balance: string;
        net_contributions: string;
        realized_pnl: string;
        dividends_received: string;
        is_demo: boolean;
        last_reconciled_at: string | null;
    }>;
    brokers: Array<{ id: number; code: string; name: string; default_currency: string; csv_adapter: string }>;
    consolidatedHoldings: Array<{
        instrument_id: number;
        symbol: string;
        name: string;
        exchange_code: string;
        sector: string | null;
        currency: string;
        quantity: string;
        avg_cost_per_share: string;
        total_cost: string;
        close: string | null;
        market_value: string;
        market_value_reporting: string;
        total_cost_reporting: string;
        unrealized_pnl: string;
        unrealized_pnl_pct: string | null;
        weight_pct: string;
        accounts_count: number;
        safety_category: string;
        safety_risk_score: string | null;
        safeguard_triggered: boolean;
        is_demo: boolean;
    }>;
    accountHoldings: Array<{
        id: number;
        brokerage_account_id: number;
        account_name: string;
        instrument_id: number;
        symbol: string;
        exchange_code: string;
        currency: string;
        quantity: string;
        cost_per_share: string;
        total_cost: string;
        market_value: string | null;
        unrealized_pnl: string | null;
        unrealized_pnl_pct: string | null;
    }>;
    transactions: Array<{
        id: number;
        brokerage_account_id: number;
        account_name: string;
        symbol: string | null;
        exchange_code: string | null;
        transaction_type: string;
        trade_date: string;
        quantity: string | null;
        price: string | null;
        gross_amount: string;
        broker_fee: string;
        net_cash_amount: string;
        transaction_currency: string;
        import_source: string;
        notes: string | null;
        reversed_at: string | null;
        reversal_reason: string | null;
        is_demo: boolean;
    }>;
    reconciliations: Array<{
        id: number;
        account_name: string;
        currency: string;
        statement_date: string;
        statement_cash_balance: string;
        computed_cash_balance: string;
        cash_difference: string;
        status: string;
        notes: string | null;
    }>;
    instruments: Array<{ id: number; symbol: string; name: string; currency: string; exchange_code: string }>;
    selectedAccountId: number | null;
    currencies: string[];
    allocations: Record<string, AllocationBucket[]>;
    concentration: ConcentrationData;
    concentrationThresholds: Record<string, number>;
    historySeries: PortfolioHistoryPoint[];
}

export default function PortfolioPage({
    portfolio,
    summary,
    accounts,
    accountComparison,
    brokers,
    consolidatedHoldings,
    accountHoldings,
    transactions,
    reconciliations,
    instruments,
    selectedAccountId,
    currencies,
    allocations,
    concentration,
    concentrationThresholds,
    historySeries,
}: PortfolioProps) {
    const { t, locale } = useT();
    const { flash } = usePage<SharedData>().props;

    const [tab, setTab] = useState<'overview' | 'holdings' | 'allocation' | 'accounts' | 'ledger' | 'import' | 'reconcile'>('overview');
    const [accountModalOpen, setAccountModalOpen] = useState(false);
    const [txModalOpen, setTxModalOpen] = useState(false);
    const [reverseTargetId, setReverseTargetId] = useState<number | null>(null);
    const [reverseReason, setReverseReason] = useState('');

    // Forms
    const accountForm = useForm({
        broker_id: String(brokers[0]?.id ?? ''),
        account_name: '',
        account_label: '',
        base_currency: 'IDR',
        cost_basis_method: portfolio.cost_basis_method,
    });

    const txForm = useForm({
        brokerage_account_id: String(selectedAccountId ?? accounts[0]?.id ?? ''),
        counterparty_account_id: String(accounts[1]?.id ?? accounts[0]?.id ?? ''),
        transaction_type: 'buy',
        trade_date: new Date().toISOString().slice(0, 10),
        instrument_id: String(instruments[0]?.id ?? ''),
        quantity: '',
        price: '',
        gross_amount: '',
        broker_fee: '0',
        exchange_fee: '0',
        tax_amount: '0',
        ratio_numerator: '2',
        ratio_denominator: '1',
        notes: '',
    });

    const importForm = useForm<{ brokerage_account_id: string; adapter: string; file: File | null }>({
        brokerage_account_id: String(selectedAccountId ?? accounts[0]?.id ?? ''),
        adapter: 'stockbit',
        file: null,
    });

    const recForm = useForm<{
        brokerage_account_id: string;
        statement_date: string;
        statement_cash_balance: string;
        notes: string;
        create_cash_adjustment: boolean;
    }>({
        brokerage_account_id: String(selectedAccountId ?? accounts[0]?.id ?? ''),
        statement_date: new Date().toISOString().slice(0, 10),
        statement_cash_balance: '',
        notes: '',
        create_cash_adjustment: false,
    });

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.portfolio'), href: '/portfolio' }];

    const switchFilter = (params: Record<string, string | number | null>) => {
        const next: Record<string, string> = {
            currency: String(params.currency ?? summary.reporting_currency),
            method: String(params.method ?? summary.cost_basis_method),
        };
        const acct = params.account_id !== undefined ? params.account_id : selectedAccountId;
        if (acct) next.account_id = String(acct);
        router.get('/portfolio', next, { preserveState: true, preserveScroll: true });
    };

    const savePrefs = (currency: string, method: string) => {
        router.patch(
            '/portfolio/preferences',
            { reporting_currency: currency, cost_basis_method: method },
            { preserveScroll: true },
        );
    };

    const submitAccount = (e: React.FormEvent) => {
        e.preventDefault();
        accountForm.post('/portfolio/accounts', {
            preserveScroll: true,
            onSuccess: () => {
                accountForm.reset('account_name', 'account_label');
                setAccountModalOpen(false);
            },
        });
    };

    const submitTx = (e: React.FormEvent) => {
        e.preventDefault();
        txForm.post('/portfolio/transactions', {
            preserveScroll: true,
            onSuccess: () => {
                txForm.reset('quantity', 'price', 'gross_amount', 'notes');
                setTxModalOpen(false);
            },
        });
    };

    const submitReverse = (e: React.FormEvent) => {
        e.preventDefault();
        if (!reverseTargetId || !reverseReason.trim()) return;
        router.post(
            `/portfolio/transactions/${reverseTargetId}/reverse`,
            { reason: reverseReason.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setReverseTargetId(null);
                    setReverseReason('');
                },
            },
        );
    };

    const submitImport = (e: React.FormEvent) => {
        e.preventDefault();
        importForm.post('/portfolio/import-csv', { forceFormData: true, preserveScroll: true });
    };

    const submitReconcile = (e: React.FormEvent) => {
        e.preventDefault();
        recForm.post('/portfolio/reconcile', { preserveScroll: true });
    };

    const needsInstrument = !['cash_deposit', 'cash_withdrawal', 'fee', 'cash_adjustment'].includes(txForm.data.transaction_type);
    const isCashOrDiv = ['cash_deposit', 'cash_withdrawal', 'dividend_receipt', 'dividend_tax', 'fee', 'cash_adjustment'].includes(txForm.data.transaction_type);
    const isSplit = ['stock_split', 'reverse_split'].includes(txForm.data.transaction_type);
    const isTransfer = txForm.data.transaction_type === 'stock_transfer';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('portfolio.title')} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className="bg-[var(--nadi-sage)]/25 text-[var(--nadi-gain-ink)] flex h-9 w-9 items-center justify-center rounded-md">
                            <Briefcase className="h-4 w-4" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-lg font-semibold tracking-tight">{t('portfolio.title')}</h1>
                                {summary.is_demo && <DemoBadge />}
                            </div>
                            <p className="text-muted-foreground text-xs">{t('portfolio.subtitle')}</p>
                            <DataFreshness asOf={summary.as_of} isDemo={summary.is_demo} className="mt-1" />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={selectedAccountId ? String(selectedAccountId) : 'ALL'}
                            onValueChange={(v) => switchFilter({ account_id: v === 'ALL' ? null : Number(v) })}
                        >
                            <SelectTrigger className="h-8 w-44 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('app.all')} ({accounts.length})</SelectItem>
                                {accounts.map((a) => (
                                    <SelectItem key={a.id} value={String(a.id)}>
                                        {a.account_name} ({a.base_currency})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={summary.reporting_currency}
                            onValueChange={(ccy) => {
                                savePrefs(ccy, summary.cost_basis_method);
                                switchFilter({ currency: ccy });
                            }}
                        >
                            <SelectTrigger className="h-8 w-24 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((c) => (
                                    <SelectItem key={c} value={c}>
                                        {c}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={summary.cost_basis_method}
                            onValueChange={(m) => {
                                savePrefs(summary.reporting_currency, m);
                                switchFilter({ method: m });
                            }}
                        >
                            <SelectTrigger className="h-8 w-40 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="weighted_average">{t('portfolio.method.weighted_average')}</SelectItem>
                                <SelectItem value="fifo">{t('portfolio.method.fifo')}</SelectItem>
                            </SelectContent>
                        </Select>

                        <Button type="button" size="sm" variant="outline" className="h-8 gap-1 text-xs" onClick={() => setAccountModalOpen(true)}>
                            <Plus className="h-3.5 w-3.5" />
                            <span>{t('portfolio.add_account')}</span>
                        </Button>

                        <Button
                            type="button"
                            size="sm"
                            className="h-8 gap-1 text-xs"
                            disabled={accounts.length === 0}
                            onClick={() => setTxModalOpen(true)}
                        >
                            <Plus className="h-3.5 w-3.5" />
                            <span>{t('portfolio.record_tx')}</span>
                        </Button>
                    </div>
                </div>

                {flash?.status && (
                    <div className="bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)] rounded-md px-3 py-2 text-xs font-medium">
                        Status: {flash.status}
                    </div>
                )}

                <div className="border-border bg-muted/40 text-muted-foreground flex items-center gap-2 rounded-md border px-3 py-2 text-xs">
                    <Lock className="h-3.5 w-3.5 shrink-0" />
                    <span>{t('portfolio.no_credentials_note')}</span>
                </div>

                {/* KPI Strip (Command Center Metrics) */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="bg-card border-border rounded-md border p-3">
                        <span className="text-muted-foreground block text-[11px]">{t('portfolio.kpi.total_equity')}</span>
                        <div className="num mt-1 text-base font-bold">
                            <Money value={summary.total_equity} currency={summary.reporting_currency} />
                        </div>
                        <span className="text-muted-foreground num block text-[11px]">
                            {summary.accounts_count} akun · {summary.holdings_count} saham
                        </span>
                    </div>
                    <div className="bg-card border-border rounded-md border p-3">
                        <span className="text-muted-foreground block text-[11px]">{t('portfolio.kpi.market_value')}</span>
                        <div className="num mt-1 text-base font-semibold">
                            <Money value={summary.market_value} currency={summary.reporting_currency} />
                        </div>
                        <span className="text-muted-foreground num block text-[11px]">
                            {t('portfolio.kpi.invested_cost')}: <Money value={summary.invested_cost} currency={summary.reporting_currency} />
                        </span>
                    </div>
                    <div className="bg-card border-border rounded-md border p-3">
                        <span className="text-muted-foreground block text-[11px]">{t('portfolio.kpi.cash_balance')}</span>
                        <div className="num mt-1 text-base font-semibold">
                            <Money value={summary.cash_balance} currency={summary.reporting_currency} />
                        </div>
                        <span className="text-muted-foreground num block text-[11px]">
                            {t('portfolio.kpi.net_contributions')}: <Money value={summary.net_contributions} currency={summary.reporting_currency} />
                        </span>
                    </div>
                    <div className="bg-card border-border rounded-md border p-3">
                        <span className="text-muted-foreground block text-[11px]">{t('portfolio.kpi.total_pnl')}</span>
                        <div className="mt-1">
                            <ChangeBadge
                                change={summary.total_pnl}
                                changePct={summary.return_on_contributions_pct}
                                currency={summary.reporting_currency}
                                showValue
                            />
                        </div>
                        <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-2 text-[11px]">
                            <span>
                                {t('portfolio.kpi.daily_change')}: <ChangeBadge change={summary.daily_change} changePct={summary.daily_change_pct} currency={summary.reporting_currency} showValue />
                            </span>
                        </div>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-3 text-xs">
                    <div className="bg-card border-border flex items-center justify-between rounded-md border px-3 py-2">
                        <span className="text-muted-foreground">{t('portfolio.kpi.unrealized')}</span>
                        <ChangeBadge change={summary.unrealized_pnl} changePct={summary.unrealized_pnl_pct} currency={summary.reporting_currency} showValue />
                    </div>
                    <div className="bg-card border-border flex items-center justify-between rounded-md border px-3 py-2">
                        <span className="text-muted-foreground">{t('portfolio.kpi.realized_div')}</span>
                        <span className="num font-semibold">
                            <Money value={summary.realized_pnl} currency={summary.reporting_currency} /> · Div{' '}
                            <Money value={summary.dividends_received} currency={summary.reporting_currency} />
                        </span>
                    </div>
                    <div className="bg-card border-border flex items-center justify-between rounded-md border px-3 py-2">
                        <span className="text-muted-foreground">{t('portfolio.kpi.risk_status')}</span>
                        <span
                            className={cn(
                                'rounded px-1.5 py-0.5 text-[11px] font-semibold',
                                summary.risk_status === 'attention_required'
                                    ? 'bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]'
                                    : summary.risk_status === 'concentrated'
                                      ? 'bg-[var(--nadi-lavender)]/25 text-[var(--nadi-lavender-ink)]'
                                      : 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)]',
                            )}
                        >
                            {t(`portfolio.risk.${summary.risk_status}`)}
                        </span>
                    </div>
                </div>

                {/* Sub-tabs */}
                <div className="border-border flex flex-wrap items-center justify-between gap-2 border-b pb-2">
                    <div className="flex flex-wrap gap-1">
                        {(['overview', 'holdings', 'allocation', 'accounts', 'ledger', 'import', 'reconcile'] as const).map((k) => (
                            <Button
                                key={k}
                                type="button"
                                size="sm"
                                variant={tab === k ? 'default' : 'ghost'}
                                className="h-8 text-xs"
                                onClick={() => setTab(k)}
                            >
                                {t(`portfolio.tab.${k}`)}
                            </Button>
                        ))}
                    </div>

                    <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs">
                        <a href="/portfolio/transactions/export">
                            <Download className="h-3.5 w-3.5" />
                            <span>{t('portfolio.export_csv')}</span>
                        </a>
                    </Button>
                </div>

                {/* Tab 0: Command Center Overview */}
                {tab === 'overview' && (
                    <div className="space-y-5">
                        <CostVsMarketChart
                            historySeries={historySeries}
                            holdings={consolidatedHoldings}
                            reportingCurrency={summary.reporting_currency}
                        />
                        <AllocationPanel allocations={allocations} reportingCurrency={summary.reporting_currency} />
                        <ConcentrationPanel
                            concentration={concentration}
                            thresholds={concentrationThresholds}
                            reportingCurrency={summary.reporting_currency}
                        />
                    </div>
                )}

                {/* Tab 2: Allocation & Concentration dedicated view */}
                {tab === 'allocation' && (
                    <div className="space-y-5">
                        <AllocationPanel allocations={allocations} reportingCurrency={summary.reporting_currency} />
                        <ConcentrationPanel
                            concentration={concentration}
                            thresholds={concentrationThresholds}
                            reportingCurrency={summary.reporting_currency}
                        />
                    </div>
                )}

                {/* Tab 3: Multi-Account Side-by-Side Comparison */}
                {tab === 'accounts' && (
                    <section className="bg-card border-border rounded-md border p-4 space-y-3">
                        <h2 className="text-sm font-semibold">{t('portfolio.accounts.comparison_title')}</h2>
                        {accountComparison.length === 0 ? (
                            <EmptyState title={t('app.none')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full border-collapse text-xs">
                                    <thead>
                                        <tr className="border-border border-b text-left">
                                            <th className="py-2 font-semibold">Akun / Broker</th>
                                            <th className="py-2 font-semibold">CCY</th>
                                            <th className="py-2 text-right font-semibold">Posisi</th>
                                            <th className="py-2 text-right font-semibold">Cost Basis ({summary.reporting_currency})</th>
                                            <th className="py-2 text-right font-semibold">Nilai Saham</th>
                                            <th className="py-2 text-right font-semibold">Kas</th>
                                            <th className="py-2 text-right font-semibold">Total Ekuitas</th>
                                            <th className="py-2 text-right font-semibold">Unrealized</th>
                                            <th className="py-2 text-right font-semibold">Total P&L</th>
                                            <th className="py-2 text-right font-semibold">Porsi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {accountComparison.map((ac) => (
                                            <tr key={ac.id} className="hover:bg-muted/30">
                                                <td className="py-2 font-medium">
                                                    {ac.account_name}
                                                    <span className="text-muted-foreground block text-[10px]">{ac.broker_name}</span>
                                                </td>
                                                <td className="py-2 font-mono">{ac.base_currency}</td>
                                                <td className="num py-2 text-right">{ac.holdings_count}</td>
                                                <td className="num py-2 text-right">
                                                    <Money value={ac.total_cost_reporting} currency={summary.reporting_currency} />
                                                </td>
                                                <td className="num py-2 text-right">
                                                    <Money value={ac.market_value_reporting} currency={summary.reporting_currency} />
                                                </td>
                                                <td className="num py-2 text-right">
                                                    <Money value={ac.cash_balance_reporting} currency={summary.reporting_currency} />
                                                </td>
                                                <td className="num py-2 text-right font-semibold">
                                                    <Money value={ac.total_equity_reporting} currency={summary.reporting_currency} />
                                                </td>
                                                <td className="py-2 text-right">
                                                    <ChangeBadge change={ac.unrealized_pnl_reporting} changePct={ac.unrealized_pnl_pct} currency={summary.reporting_currency} showValue />
                                                </td>
                                                <td className="py-2 text-right">
                                                    <ChangeBadge change={ac.total_pnl_reporting} currency={summary.reporting_currency} showValue />
                                                </td>
                                                <td className="num py-2 text-right font-semibold">{formatNumber(ac.portfolio_share_pct, locale, 2, 2)}%</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                )}

                {/* Tab 1: Holdings */}
                {tab === 'holdings' && (
                    <div className="space-y-5">
                        <section className="bg-card border-border rounded-md border p-4 space-y-3">
                            <h2 className="text-sm font-semibold">{t('portfolio.holdings.consolidated')}</h2>
                            {consolidatedHoldings.length === 0 ? (
                                <EmptyState title={t('heatmap.empty_portfolio')} />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full border-collapse text-xs">
                                        <thead>
                                            <tr className="border-border border-b text-left">
                                                <th className="py-2 font-semibold">{t('stocks.col.symbol')}</th>
                                                <th className="py-2 font-semibold">Safety</th>
                                                <th className="py-2 text-right font-semibold">Qty</th>
                                                <th className="py-2 text-right font-semibold">Avg Cost</th>
                                                <th className="py-2 text-right font-semibold">Cost ({summary.reporting_currency})</th>
                                                <th className="py-2 text-right font-semibold">{t('stocks.col.price')}</th>
                                                <th className="py-2 text-right font-semibold">Nilai ({summary.reporting_currency})</th>
                                                <th className="py-2 text-right font-semibold">Unrealized P&L</th>
                                                <th className="py-2 text-right font-semibold">Bobot</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border divide-y">
                                            {consolidatedHoldings.map((h) => (
                                                <tr key={h.instrument_id} className="hover:bg-muted/30">
                                                    <td className="py-2 font-medium">
                                                        <Link href={`/stocks/${h.exchange_code}/${h.symbol}`} className="hover:underline">
                                                            {h.symbol}{' '}
                                                            <span className="text-muted-foreground text-[10px]">{h.exchange_code}</span>
                                                        </Link>
                                                    </td>
                                                    <td className="py-2">
                                                        <SafetyBadge category={h.safety_category} score={h.safety_risk_score} safeguard={h.safeguard_triggered} compact />
                                                    </td>
                                                    <td className="num py-2 text-right">{formatNumber(h.quantity, locale, 0, 2)}</td>
                                                    <td className="num py-2 text-right">{formatPrice(h.avg_cost_per_share, h.currency, locale)}</td>
                                                    <td className="num py-2 text-right">
                                                        <Money value={h.total_cost_reporting} currency={summary.reporting_currency} />
                                                    </td>
                                                    <td className="num py-2 text-right">{formatPrice(h.close, h.currency, locale)}</td>
                                                    <td className="num py-2 text-right font-semibold">
                                                        <Money value={h.market_value_reporting} currency={summary.reporting_currency} />
                                                    </td>
                                                    <td className="py-2 text-right">
                                                        <ChangeBadge change={h.unrealized_pnl} changePct={h.unrealized_pnl_pct} currency={h.currency} showValue />
                                                    </td>
                                                    <td className="num py-2 text-right">{formatPct(h.weight_pct, locale, false)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>

                        {accountHoldings.length > 0 && (
                            <section className="bg-card border-border rounded-md border p-4 space-y-3">
                                <h2 className="text-sm font-semibold">{t('portfolio.holdings.per_account')}</h2>
                                <div className="overflow-x-auto">
                                    <table className="w-full border-collapse text-xs">
                                        <thead>
                                            <tr className="border-border border-b text-left">
                                                <th className="py-2 font-semibold">Akun</th>
                                                <th className="py-2 font-semibold">{t('stocks.col.symbol')}</th>
                                                <th className="py-2 text-right font-semibold">Qty</th>
                                                <th className="py-2 text-right font-semibold">Cost/Share</th>
                                                <th className="py-2 text-right font-semibold">Cost Basis</th>
                                                <th className="py-2 text-right font-semibold">Market Value</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border divide-y">
                                            {accountHoldings.map((ah) => (
                                                <tr key={ah.id}>
                                                    <td className="py-2">{ah.account_name}</td>
                                                    <td className="py-2 font-medium">
                                                        {ah.symbol} ({ah.exchange_code})
                                                    </td>
                                                    <td className="num py-2 text-right">{formatNumber(ah.quantity, locale, 0, 2)}</td>
                                                    <td className="num py-2 text-right">{formatPrice(ah.cost_per_share, ah.currency, locale)}</td>
                                                    <td className="num py-2 text-right">
                                                        <Money value={ah.total_cost} currency={ah.currency} />
                                                    </td>
                                                    <td className="num py-2 text-right">
                                                        <Money value={ah.market_value} currency={ah.currency} />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        )}
                    </div>
                )}

                {/* Tab 2: Ledger */}
                {tab === 'ledger' && (
                    <section className="bg-card border-border rounded-md border p-4 space-y-3">
                        <h2 className="text-sm font-semibold">{t('portfolio.tab.ledger')}</h2>
                        {transactions.length === 0 ? (
                            <EmptyState title={t('app.none')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full border-collapse text-xs">
                                    <thead>
                                        <tr className="border-border border-b text-left">
                                            <th className="py-2 font-semibold">Tanggal</th>
                                            <th className="py-2 font-semibold">Akun</th>
                                            <th className="py-2 font-semibold">Tipe</th>
                                            <th className="py-2 font-semibold">Emiten</th>
                                            <th className="py-2 text-right font-semibold">Qty</th>
                                            <th className="py-2 text-right font-semibold">Harga</th>
                                            <th className="py-2 text-right font-semibold">Arus Kas Bersih</th>
                                            <th className="py-2 text-right font-semibold">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {transactions.map((tx) => (
                                            <tr key={tx.id} className={cn(tx.reversed_at && 'opacity-50 line-through')}>
                                                <td className="num py-2">{formatDate(tx.trade_date, locale)}</td>
                                                <td className="py-2">{tx.account_name}</td>
                                                <td className="py-2 font-mono text-[11px]">{tx.transaction_type}</td>
                                                <td className="py-2 font-medium">{tx.symbol ? `${tx.symbol} (${tx.exchange_code})` : '—'}</td>
                                                <td className="num py-2 text-right">{tx.quantity ? formatNumber(tx.quantity, locale, 0, 2) : '—'}</td>
                                                <td className="num py-2 text-right">{formatPrice(tx.price, tx.transaction_currency, locale)}</td>
                                                <td className="num py-2 text-right">
                                                    <Money value={tx.net_cash_amount} currency={tx.transaction_currency} />
                                                </td>
                                                <td className="py-2 text-right">
                                                    {!tx.reversed_at && tx.transaction_type !== 'reversal' && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            className="h-7 gap-1 px-2 text-[11px]"
                                                            onClick={() => setReverseTargetId(tx.id)}
                                                        >
                                                            <RotateCcw className="h-3 w-3" />
                                                            <span>{t('portfolio.reverse')}</span>
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                )}

                {/* Tab 3: CSV Import */}
                {tab === 'import' && (
                    <form onSubmit={submitImport} className="bg-card border-border space-y-4 rounded-md border p-4">
                        <div className="flex items-center gap-2">
                            <UploadCloud className="text-muted-foreground h-4 w-4" />
                            <h2 className="text-sm font-semibold">{t('portfolio.tab.import')}</h2>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label className="text-[11px]">Akun Tujuan</Label>
                                <Select value={importForm.data.brokerage_account_id} onValueChange={(v) => importForm.setData('brokerage_account_id', v)}>
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accounts.map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>
                                                {a.account_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1">
                                <Label className="text-[11px]">Adapter Broker</Label>
                                <Select value={importForm.data.adapter} onValueChange={(v) => importForm.setData('adapter', v)}>
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="nadi_standard">NADI Standard</SelectItem>
                                        <SelectItem value="stockbit">Stockbit (IDX)</SelectItem>
                                        <SelectItem value="ajaib">Ajaib (IDX)</SelectItem>
                                        <SelectItem value="ipot">IPOT (IDX)</SelectItem>
                                        <SelectItem value="bions">BIONS (IDX)</SelectItem>
                                        <SelectItem value="most">MOST (IDX)</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1">
                                <Label className="text-[11px]">File CSV (.csv / .txt)</Label>
                                <Input
                                    type="file"
                                    accept=".csv,.txt"
                                    onChange={(e) => importForm.setData('file', e.target.files?.[0] ?? null)}
                                    className="h-8 text-xs"
                                    required
                                />
                            </div>
                        </div>
                        <p className="text-muted-foreground font-mono text-[11px]">
                            Kolom didukung: trade_date, transaction_type (buy/sell/cash_deposit/dividend_receipt), exchange, symbol, quantity atau lots, price, broker_fee, tax_amount, external_ref
                        </p>
                        <Button type="submit" size="sm" disabled={importForm.processing || accounts.length === 0}>
                            {t('portfolio.import_submit')}
                        </Button>
                    </form>
                )}

                {/* Tab 4: Reconciliation */}
                {tab === 'reconcile' && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <form onSubmit={submitReconcile} className="bg-card border-border space-y-3 rounded-md border p-4">
                            <h2 className="text-sm font-semibold">{t('portfolio.tab.reconcile')}</h2>
                            <div className="space-y-1">
                                <Label className="text-[11px]">Akun</Label>
                                <Select value={recForm.data.brokerage_account_id} onValueChange={(v) => recForm.setData('brokerage_account_id', v)}>
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accounts.map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>
                                                {a.account_name} ({a.base_currency})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Tanggal Statement</Label>
                                    <Input
                                        type="date"
                                        value={recForm.data.statement_date}
                                        onChange={(e) => recForm.setData('statement_date', e.target.value)}
                                        className="h-8 text-xs"
                                        required
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Saldo Kas Statement</Label>
                                    <Input
                                        type="number"
                                        step="any"
                                        value={recForm.data.statement_cash_balance}
                                        onChange={(e) => recForm.setData('statement_cash_balance', e.target.value)}
                                        className="num h-8 text-xs"
                                        required
                                    />
                                </div>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[11px]">Catatan</Label>
                                <Input value={recForm.data.notes} onChange={(e) => recForm.setData('notes', e.target.value)} className="h-8 text-xs" />
                            </div>
                            <label className="flex items-center gap-2 text-xs">
                                <Checkbox
                                    checked={recForm.data.create_cash_adjustment}
                                    onCheckedChange={(c) => recForm.setData('create_cash_adjustment', Boolean(c))}
                                />
                                <span>Buat entri penyesuaian kas otomatis bila terdapat selisih</span>
                            </label>
                            <Button type="submit" size="sm" disabled={recForm.processing || accounts.length === 0}>
                                {t('portfolio.reconcile_submit')}
                            </Button>
                        </form>

                        <section className="bg-card border-border rounded-md border p-4 space-y-3">
                            <h2 className="text-sm font-semibold">Riwayat Rekonsiliasi</h2>
                            {reconciliations.length === 0 ? (
                                <EmptyState title={t('app.none')} />
                            ) : (
                                <div className="divide-border divide-y text-xs">
                                    {reconciliations.map((r) => (
                                        <div key={r.id} className="flex items-center justify-between py-2">
                                            <div>
                                                <span className="font-semibold">{r.account_name}</span>{' '}
                                                <span className="text-muted-foreground num">({formatDate(r.statement_date, locale)})</span>
                                                <p className="text-muted-foreground num text-[11px]">
                                                    Selisih kas: <Money value={r.cash_difference} currency={r.currency} />
                                                </p>
                                            </div>
                                            <span
                                                className={cn(
                                                    'rounded px-1.5 py-0.5 text-[11px] font-semibold',
                                                    r.status === 'matched'
                                                        ? 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)]'
                                                        : 'bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]',
                                                )}
                                            >
                                                {r.status}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>
                )}
            </div>

            {/* Add Brokerage Account Modal */}
            <Dialog open={accountModalOpen} onOpenChange={setAccountModalOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('portfolio.add_account')}</DialogTitle>
                        <DialogDescription>{t('portfolio.no_credentials_note')}</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitAccount} className="space-y-3">
                        <div className="space-y-1">
                            <Label className="text-xs">Broker</Label>
                            <Select value={accountForm.data.broker_id} onValueChange={(v) => accountForm.setData('broker_id', v)}>
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {brokers.map((b) => (
                                        <SelectItem key={b.id} value={String(b.id)}>
                                            {b.name} ({b.default_currency})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label className="text-xs">Nama Akun</Label>
                            <Input value={accountForm.data.account_name} onChange={(e) => accountForm.setData('account_name', e.target.value)} required />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label className="text-xs">Mata Uang Dasar</Label>
                                <Select value={accountForm.data.base_currency} onValueChange={(v) => accountForm.setData('base_currency', v)}>
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {currencies.map((c) => (
                                            <SelectItem key={c} value={c}>
                                                {c}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Metode Cost Basis</Label>
                                <Select value={accountForm.data.cost_basis_method} onValueChange={(v) => accountForm.setData('cost_basis_method', v as 'weighted_average' | 'fifo')}>
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="weighted_average">Weighted Average</SelectItem>
                                        <SelectItem value="fifo">FIFO</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setAccountModalOpen(false)}>
                                {t('app.cancel')}
                            </Button>
                            <Button type="submit" disabled={accountForm.processing}>
                                {t('app.save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Record Transaction Modal */}
            <Dialog open={txModalOpen} onOpenChange={setTxModalOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('portfolio.record_tx')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitTx} className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label className="text-xs">Akun</Label>
                                <Select value={txForm.data.brokerage_account_id} onValueChange={(v) => txForm.setData('brokerage_account_id', v)}>
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accounts.map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>
                                                {a.account_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Jenis Transaksi</Label>
                                <Select value={txForm.data.transaction_type} onValueChange={(v) => txForm.setData('transaction_type', v)}>
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="cash_deposit">Setor Kas (Deposit)</SelectItem>
                                        <SelectItem value="cash_withdrawal">Tarik Kas (Withdrawal)</SelectItem>
                                        <SelectItem value="buy">Beli Saham (Buy)</SelectItem>
                                        <SelectItem value="sell">Jual Saham (Sell)</SelectItem>
                                        <SelectItem value="dividend_receipt">Terima Dividen</SelectItem>
                                        <SelectItem value="stock_transfer">Transfer Saham Antar Akun</SelectItem>
                                        <SelectItem value="stock_split">Stock Split</SelectItem>
                                        <SelectItem value="reverse_split">Reverse Split</SelectItem>
                                        <SelectItem value="bonus_shares">Saham Bonus</SelectItem>
                                        <SelectItem value="rights_issue">Rights Issue / HMETD</SelectItem>
                                        <SelectItem value="fee">Biaya Lainnya</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label className="text-xs">Tanggal</Label>
                                <Input type="date" value={txForm.data.trade_date} onChange={(e) => txForm.setData('trade_date', e.target.value)} className="h-8 text-xs" required />
                            </div>
                            {needsInstrument && (
                                <div className="space-y-1">
                                    <Label className="text-xs">Instrumen</Label>
                                    <Select value={txForm.data.instrument_id} onValueChange={(v) => txForm.setData('instrument_id', v)}>
                                        <SelectTrigger className="h-8 text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {instruments.map((inst) => (
                                                <SelectItem key={inst.id} value={String(inst.id)}>
                                                    {inst.symbol} ({inst.exchange_code})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                        </div>

                        {isTransfer && (
                            <div className="space-y-1">
                                <Label className="text-xs">Akun Tujuan Transfer</Label>
                                <Select value={txForm.data.counterparty_account_id} onValueChange={(v) => txForm.setData('counterparty_account_id', v)}>
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accounts.map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>
                                                {a.account_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        {isSplit ? (
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1">
                                    <Label className="text-xs">Rasio Baru (Numerator)</Label>
                                    <Input type="number" step="any" value={txForm.data.ratio_numerator} onChange={(e) => txForm.setData('ratio_numerator', e.target.value)} className="num h-8 text-xs" required />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Rasio Lama (Denominator)</Label>
                                    <Input type="number" step="any" value={txForm.data.ratio_denominator} onChange={(e) => txForm.setData('ratio_denominator', e.target.value)} className="num h-8 text-xs" required />
                                </div>
                            </div>
                        ) : isCashOrDiv ? (
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1">
                                    <Label className="text-xs">Nominal Kotor</Label>
                                    <Input type="number" step="any" value={txForm.data.gross_amount} onChange={(e) => txForm.setData('gross_amount', e.target.value)} className="num h-8 text-xs" required />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Pajak / Withholding</Label>
                                    <Input type="number" step="any" value={txForm.data.tax_amount} onChange={(e) => txForm.setData('tax_amount', e.target.value)} className="num h-8 text-xs" />
                                </div>
                            </div>
                        ) : (
                            <div className="grid grid-cols-3 gap-3">
                                <div className="space-y-1">
                                    <Label className="text-xs">Jumlah Saham</Label>
                                    <Input type="number" step="any" value={txForm.data.quantity} onChange={(e) => txForm.setData('quantity', e.target.value)} className="num h-8 text-xs" required />
                                </div>
                                {!isTransfer && txForm.data.transaction_type !== 'bonus_shares' && (
                                    <>
                                        <div className="space-y-1">
                                            <Label className="text-xs">Harga Eksekusi</Label>
                                            <Input type="number" step="any" value={txForm.data.price} onChange={(e) => txForm.setData('price', e.target.value)} className="num h-8 text-xs" required />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-xs">Fee Broker</Label>
                                            <Input type="number" step="any" value={txForm.data.broker_fee} onChange={(e) => txForm.setData('broker_fee', e.target.value)} className="num h-8 text-xs" />
                                        </div>
                                    </>
                                )}
                            </div>
                        )}

                        <div className="space-y-1">
                            <Label className="text-xs">Catatan</Label>
                            <Input value={txForm.data.notes} onChange={(e) => txForm.setData('notes', e.target.value)} className="h-8 text-xs" />
                        </div>

                        {Object.values(txForm.errors).length > 0 && (
                            <p className="text-[var(--nadi-loss-ink)] text-xs">{Object.values(txForm.errors)[0]}</p>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setTxModalOpen(false)}>
                                {t('app.cancel')}
                            </Button>
                            <Button type="submit" disabled={txForm.processing}>
                                {t('app.save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Reversal Audit Dialog */}
            <Dialog open={reverseTargetId !== null} onOpenChange={(o) => !o && setReverseTargetId(null)}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('portfolio.reverse')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitReverse} className="space-y-3">
                        <div className="space-y-1">
                            <Label className="text-xs">{t('portfolio.reverse_reason')}</Label>
                            <Input value={reverseReason} onChange={(e) => setReverseReason(e.target.value)} required />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setReverseTargetId(null)}>
                                {t('app.cancel')}
                            </Button>
                            <Button type="submit">{t('app.save')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
