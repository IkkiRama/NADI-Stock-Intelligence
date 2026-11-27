import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { MetricCard } from '@/components/nadi/metric-card';
import { Money } from '@/components/nadi/money';
import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatNumber, formatPct } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Download, FileJson, FileSpreadsheet, FileText, Info, Printer } from 'lucide-react';

interface ReportExportHistoryItem {
    id: number;
    report_type: string;
    format: string;
    reporting_currency: string;
    cost_basis_method: string;
    range_code: string;
    row_count: number;
    is_demo: boolean;
    generated_at: string | null;
}

interface ReportsPageProps {
    report: Record<string, unknown> & {
        report_type: string;
        meta: {
            generated_at_utc: string;
            portfolio_name: string;
            account_id: number | null;
            reporting_currency: string;
            cost_basis_method: string;
            range: string;
            benchmark: string;
            benchmark_ccy_mode: string;
            as_of: string | null;
            is_demo: boolean;
        };
        valuation: {
            summary: {
                total_equity: string;
                market_value: string;
                cash_balance: string;
                unrealized_pnl: string;
                realized_pnl: string;
                total_pnl: string;
                holdings_count: number;
                accounts_count: number;
            };
            consolidatedHoldings: Array<{
                instrument_id: number;
                symbol: string;
                name: string;
                exchange_code: string;
                sector: string | null;
                currency: string;
                quantity: string;
                market_value_reporting: string;
                total_cost_reporting: string;
                unrealized_pnl_reporting: string;
                weight_pct: string;
                safety_category: string;
                safeguard_triggered: boolean;
            }>;
        };
        command_center?: {
            concentration: { hhi: string | null; hhi_level: string; top3_pct: string };
        };
        twr?: { twr_total: string | null; twr_annualized: string | null; net_investment_gain_rep: string };
        xirr?: { xirr_annualized: string | null; modified_dietz_return: string | null; status: string };
        benchmark?: { code: string; benchmark_return: string | null; excess_return: string | null };
        attribution?: {
            by_stock: Array<{
                symbol: string;
                exchange_code: string;
                sector: string;
                local_price_effect_rep: string;
                fx_effect_rep: string;
                dividend_rep: string;
                fee_tax_rep: string;
                total_pnl_rep: string;
                contribution_pct: string | null;
            }>;
        };
        dividends?: {
            summary: {
                realized_net_ytd_rep: string;
                realized_net_all_time_rep: string;
                projected_annual_net_rep: string;
                portfolio_yield_on_cost: string | null;
            };
            holdings: Array<{
                symbol: string;
                exchange_code: string;
                estimation_basis: string;
                tax_rate_pct: string;
                projected_annual_net_rep: string;
                realized_net_rep: string;
                yield_on_mv: string | null;
                yield_on_cost: string | null;
            }>;
        };
        risk?: {
            max_drawdown: string | null;
            annualized_volatility: string | null;
            sharpe_ratio: string | null;
            beta: string | null;
        };
        rebalance?: {
            total_estimated_fees_rep: string;
            turnover_pct: string;
            pre_hhi: string | null;
            post_hhi: string | null;
            rows: Array<{
                symbol: string;
                exchange_code: string;
                current_weight_pct: string;
                target_weight_pct: string;
                action: string;
                suggested_lots: number;
                suggested_shares: string;
                estimated_gross_rep: string;
                post_weight_pct: string;
            }>;
        };
        ledger?: {
            totals: {
                deposits_rep: string;
                withdrawals_rep: string;
                buy_gross_rep: string;
                sell_gross_rep: string;
                dividends_net_rep: string;
                fees_taxes_rep: string;
            };
            transactions: Array<{
                id: number;
                trade_date: string;
                account_name: string;
                transaction_type: string;
                symbol: string;
                quantity: string | null;
                gross_amount: string;
                transaction_currency: string;
                net_cash_reporting: string;
            }>;
        };
        disclaimers: Record<string, string>;
        row_count: number;
    };
    reportTypes: string[];
    ranges: string[];
    currencies: string[];
    benchmarks: Array<{ code: string; name: string; currency: string }>;
    accounts: Array<{ id: number; account_name: string; base_currency: string }>;
    filters: {
        type: string;
        account_id: number | null;
        currency: string;
        method: string;
        range: string;
        benchmark: string;
        benchmark_ccy_mode: string;
    };
    exportHistory: ReportExportHistoryItem[];
}

export default function ReportsCenterPage({
    report,
    reportTypes,
    ranges,
    currencies,
    benchmarks,
    accounts,
    filters,
    exportHistory,
}: ReportsPageProps) {
    const { t, locale } = useT();
    const { settings } = usePage<SharedData>().props;

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.reports'), href: '/reports' }];

    const buildQueryString = (overrides: Record<string, string | number | null> = {}) => {
        const params = new URLSearchParams();
        params.set('type', String(overrides.type ?? filters.type));
        params.set('currency', String(overrides.currency ?? filters.currency));
        params.set('method', String(overrides.method ?? filters.method));
        params.set('range', String(overrides.range ?? filters.range));
        params.set('benchmark', String(overrides.benchmark ?? filters.benchmark));
        params.set('benchmark_ccy_mode', String(overrides.benchmark_ccy_mode ?? filters.benchmark_ccy_mode));
        const acct = overrides.account_id !== undefined ? overrides.account_id : filters.account_id;
        if (acct) params.set('account_id', String(acct));
        return params.toString();
    };

    const navigateFilters = (overrides: Record<string, string | number | null>) => {
        router.get(`/reports?${buildQueryString(overrides)}`, {}, { preserveState: true, preserveScroll: true });
    };

    const qs = buildQueryString();
    const reportingCcy = filters.currency;
    const sum = report.valuation.summary;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('reports.title')} />

            <div className="space-y-5">
                {/* Header & Export Actions */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className="bg-[var(--nadi-ice)]/35 text-foreground flex h-9 w-9 items-center justify-center rounded-md">
                            <FileText className="h-4 w-4" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-lg font-semibold tracking-tight">{t('reports.title')}</h1>
                                {report.meta.is_demo && <DemoBadge />}
                            </div>
                            <p className="text-muted-foreground text-xs">{t('reports.subtitle')}</p>
                            <DataFreshness asOf={report.meta.as_of} isDemo={report.meta.is_demo} className="mt-1" />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild size="sm" variant="default" className="h-8 gap-1.5 text-xs">
                            <a href={`/reports/print?${qs}`} target="_blank" rel="noopener noreferrer">
                                <Printer className="h-3.5 w-3.5" />
                                <span>{t('reports.action.print_pdf')}</span>
                            </a>
                        </Button>
                        <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs">
                            <a href={`/reports/export-csv?${qs}`}>
                                <FileSpreadsheet className="h-3.5 w-3.5" />
                                <span>{t('reports.action.export_csv')}</span>
                            </a>
                        </Button>
                        <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs">
                            <a href={`/reports/export-json?${qs}`}>
                                <FileJson className="h-3.5 w-3.5" />
                                <span>{t('reports.action.export_json')}</span>
                            </a>
                        </Button>
                    </div>
                </div>

                {/* Report Type Selector */}
                <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-5">
                    {reportTypes.map((rt) => {
                        const active = filters.type === rt;
                        return (
                            <button
                                key={rt}
                                type="button"
                                onClick={() => navigateFilters({ type: rt })}
                                className={cn(
                                    'bg-card border-border hover:border-foreground/40 rounded-md border p-3 text-left transition-colors',
                                    active && 'border-foreground/60 bg-muted/40 ring-1 ring-foreground/15',
                                )}
                            >
                                <div className="text-xs font-semibold">{t(`reports.type.${rt}`)}</div>
                                <div className="text-muted-foreground mt-1 text-[11px] leading-snug">
                                    {t(`reports.type_desc.${rt}`)}
                                </div>
                            </button>
                        );
                    })}
                </div>

                {/* Filter Bar */}
                <div className="bg-card border-border flex flex-wrap items-center justify-between gap-2 rounded-md border p-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={filters.account_id ? String(filters.account_id) : 'ALL'}
                            onValueChange={(v) => navigateFilters({ account_id: v === 'ALL' ? null : Number(v) })}
                        >
                            <SelectTrigger className="h-8 w-44 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">
                                    {t('app.all')} ({accounts.length})
                                </SelectItem>
                                {accounts.map((a) => (
                                    <SelectItem key={a.id} value={String(a.id)}>
                                        {a.account_name} ({a.base_currency})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={filters.currency} onValueChange={(ccy) => navigateFilters({ currency: ccy })}>
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

                        <Select value={filters.method} onValueChange={(m) => navigateFilters({ method: m })}>
                            <SelectTrigger className="h-8 w-40 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="weighted_average">{t('portfolio.method.weighted_average')}</SelectItem>
                                <SelectItem value="fifo">{t('portfolio.method.fifo')}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex flex-wrap items-center gap-1.5">
                        {ranges.map((r) => (
                            <Button
                                key={r}
                                type="button"
                                size="sm"
                                variant={filters.range === r ? 'default' : 'ghost'}
                                className="h-7 px-2 text-xs"
                                onClick={() => navigateFilters({ range: r })}
                            >
                                {r}
                            </Button>
                        ))}
                        <Select value={filters.benchmark} onValueChange={(b) => navigateFilters({ benchmark: b })}>
                            <SelectTrigger className="ml-1 h-7 w-36 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {benchmarks.map((b) => (
                                    <SelectItem key={b.code} value={b.code}>
                                        {b.code} ({b.currency})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* Live Report Preview */}
                <section className="bg-card border-border rounded-md border p-4 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-2">
                        <div>
                            <h2 className="text-sm font-semibold">{t(`reports.type.${filters.type}`)}</h2>
                            <p className="text-muted-foreground text-[11px]">
                                {report.meta.portfolio_name} · {reportingCcy} · {report.meta.range} ({report.row_count} {t('reports.rows_label')})
                            </p>
                        </div>
                        <span className="text-muted-foreground num text-[11px]">
                            {formatDateTime(report.meta.generated_at_utc, locale, settings?.timezone)}
                        </span>
                    </div>

                    {/* Preview by Report Type */}
                    {filters.type === 'executive_statement' && (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <MetricCard label={t('portfolio.kpi.total_equity')} value={<Money value={sum.total_equity} currency={reportingCcy} />} />
                                <MetricCard label={t('portfolio.kpi.market_value')} value={<Money value={sum.market_value} currency={reportingCcy} />} />
                                <MetricCard label={t('portfolio.kpi.cash_balance')} value={<Money value={sum.cash_balance} currency={reportingCcy} />} />
                                <MetricCard label={t('portfolio.conc.hhi')} value={<span className="num">{report.command_center?.concentration.hhi ?? '—'}</span>} />
                            </div>
                            {report.valuation.consolidatedHoldings.length === 0 ? (
                                <EmptyState title={t('reports.empty')} />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-xs">
                                        <thead className="border-border text-muted-foreground border-b">
                                            <tr>
                                                <th className="py-2 pr-3 font-medium">{t('perf.col.item')}</th>
                                                <th className="py-2 pr-3 text-right font-medium">{t('div.col.shares')}</th>
                                                <th className="py-2 pr-3 text-right font-medium">{t('portfolio.kpi.market_value')}</th>
                                                <th className="py-2 pr-3 text-right font-medium">{t('portfolio.kpi.unrealized_pnl')}</th>
                                                <th className="py-2 pr-3 text-right font-medium">{t('risk.fx.col_weight')}</th>
                                                <th className="py-2 font-medium">Safety</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border divide-y">
                                            {report.valuation.consolidatedHoldings.map((h) => (
                                                <tr key={h.instrument_id}>
                                                    <td className="py-2 pr-3 font-semibold">
                                                        {h.symbol} <span className="text-muted-foreground font-normal">· {h.exchange_code}</span>
                                                    </td>
                                                    <td className="num py-2 pr-3 text-right">{formatNumber(h.quantity, locale, 0, 2)}</td>
                                                    <td className="num py-2 pr-3 text-right"><Money value={h.market_value_reporting} currency={reportingCcy} /></td>
                                                    <td className="num py-2 pr-3 text-right"><Money value={h.unrealized_pnl_reporting} currency={reportingCcy} /></td>
                                                    <td className="num py-2 pr-3 text-right">{formatPct(h.weight_pct, locale, false)}</td>
                                                    <td className="py-2"><SafetyBadge category={h.safety_category} safeguard={h.safeguard_triggered} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </>
                    )}

                    {filters.type === 'performance_brief' && (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <MetricCard label={t('perf.kpi.twr')} value={<ChangeBadge changePct={report.twr?.twr_total} />} />
                                <MetricCard label={t('perf.kpi.xirr')} value={<ChangeBadge changePct={report.xirr?.xirr_annualized} />} />
                                <MetricCard label={report.benchmark?.code ?? 'Benchmark'} value={<ChangeBadge changePct={report.benchmark?.benchmark_return} />} />
                                <MetricCard label={t('perf.kpi.excess_return')} value={<ChangeBadge changePct={report.benchmark?.excess_return} />} />
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-border text-muted-foreground border-b">
                                        <tr>
                                            <th className="py-2 pr-3 font-medium">{t('perf.col.item')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('perf.col.local_price')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('perf.col.fx_effect')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('perf.col.total_pnl')}</th>
                                            <th className="py-2 text-right font-medium">{t('perf.col.contribution')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {(report.attribution?.by_stock ?? []).map((s) => (
                                            <tr key={s.symbol}>
                                                <td className="py-2 pr-3 font-semibold">{s.symbol} <span className="text-muted-foreground font-normal">· {s.sector}</span></td>
                                                <td className="num py-2 pr-3 text-right"><Money value={s.local_price_effect_rep} currency={reportingCcy} /></td>
                                                <td className="num py-2 pr-3 text-right"><Money value={s.fx_effect_rep} currency={reportingCcy} /></td>
                                                <td className="num py-2 pr-3 text-right"><Money value={s.total_pnl_rep} currency={reportingCcy} /></td>
                                                <td className="num py-2 text-right"><ChangeBadge changePct={s.contribution_pct} /></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}

                    {filters.type === 'dividend_tax_schedule' && (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <MetricCard label={t('div.kpi.realized_ytd')} value={<Money value={report.dividends?.summary.realized_net_ytd_rep} currency={reportingCcy} />} />
                                <MetricCard label={t('div.kpi.all_time_realized')} value={<Money value={report.dividends?.summary.realized_net_all_time_rep} currency={reportingCcy} />} />
                                <MetricCard label={t('div.kpi.projected_net')} value={<Money value={report.dividends?.summary.projected_annual_net_rep} currency={reportingCcy} />} />
                                <MetricCard label={t('div.kpi.yield_cost')} value={<span className="num">{formatPct(report.dividends?.summary.portfolio_yield_on_cost, locale, false)}</span>} />
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-border text-muted-foreground border-b">
                                        <tr>
                                            <th className="py-2 pr-3 font-medium">{t('perf.col.item')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.tax_pct')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.proj_net')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.realized_net')}</th>
                                            <th className="py-2 text-right font-medium">{t('div.col.yield_cost')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {(report.dividends?.holdings ?? []).map((h) => (
                                            <tr key={h.symbol}>
                                                <td className="py-2 pr-3 font-semibold">{h.symbol} <span className="text-muted-foreground font-normal">({t(`div.basis.${h.estimation_basis}`)})</span></td>
                                                <td className="num py-2 pr-3 text-right">{h.tax_rate_pct}%</td>
                                                <td className="num py-2 pr-3 text-right"><Money value={h.projected_annual_net_rep} currency={reportingCcy} /></td>
                                                <td className="num py-2 pr-3 text-right"><Money value={h.realized_net_rep} currency={reportingCcy} /></td>
                                                <td className="num py-2 text-right">{formatPct(h.yield_on_cost, locale, false)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}

                    {filters.type === 'risk_rebalancing_plan' && (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <MetricCard label={t('risk.kpi.max_dd')} value={<ChangeBadge changePct={report.risk?.max_drawdown} />} />
                                <MetricCard label={t('risk.kpi.ann_vol')} value={<span className="num">{formatPct(report.risk?.annualized_volatility, locale, false)}</span>} />
                                <MetricCard label={t('risk.kpi.sharpe')} value={<span className="num">{report.risk?.sharpe_ratio ?? '—'}</span>} />
                                <MetricCard label={t('risk.reb.summary.est_fees')} value={<Money value={report.rebalance?.total_estimated_fees_rep} currency={reportingCcy} />} />
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-border text-muted-foreground border-b">
                                        <tr>
                                            <th className="py-2 pr-3 font-medium">{t('perf.col.item')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('risk.reb.col_current_w')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('risk.reb.col_target_w')}</th>
                                            <th className="py-2 pr-3 font-medium">{t('risk.reb.col_action')}</th>
                                            <th className="py-2 text-right font-medium">{t('risk.reb.col_lots_shares')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {(report.rebalance?.rows ?? []).map((r) => (
                                            <tr key={r.symbol}>
                                                <td className="py-2 pr-3 font-semibold">{r.symbol} · {r.exchange_code}</td>
                                                <td className="num py-2 pr-3 text-right">{r.current_weight_pct}%</td>
                                                <td className="num py-2 pr-3 text-right">{r.target_weight_pct}%</td>
                                                <td className="py-2 pr-3">{t(`risk.reb.action.${r.action}`)}</td>
                                                <td className="num py-2 text-right">{r.suggested_lots > 0 ? `${r.suggested_lots} lot` : '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}

                    {filters.type === 'tax_realized_ledger' && (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <MetricCard label={t('reports.ledger.deposits')} value={<Money value={report.ledger?.totals.deposits_rep} currency={reportingCcy} />} />
                                <MetricCard label={t('reports.ledger.buys')} value={<Money value={report.ledger?.totals.buy_gross_rep} currency={reportingCcy} />} />
                                <MetricCard label={t('reports.ledger.sells')} value={<Money value={report.ledger?.totals.sell_gross_rep} currency={reportingCcy} />} />
                                <MetricCard label={t('reports.ledger.fees')} value={<Money value={report.ledger?.totals.fees_taxes_rep} currency={reportingCcy} />} />
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-border text-muted-foreground border-b">
                                        <tr>
                                            <th className="py-2 pr-3 font-medium">{t('div.col.date')}</th>
                                            <th className="py-2 pr-3 font-medium">{t('div.col.account')}</th>
                                            <th className="py-2 pr-3 font-medium">{t('reports.ledger.tx_type')}</th>
                                            <th className="py-2 pr-3 font-medium">{t('perf.col.item')}</th>
                                            <th className="py-2 text-right font-medium">{t('div.col.net_reporting')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {(report.ledger?.transactions ?? []).slice(0, 30).map((tx) => (
                                            <tr key={tx.id}>
                                                <td className="num py-2 pr-3">{formatDate(tx.trade_date, locale)}</td>
                                                <td className="py-2 pr-3">{tx.account_name}</td>
                                                <td className="py-2 pr-3">{tx.transaction_type}</td>
                                                <td className="py-2 pr-3 font-semibold">{tx.symbol || '—'}</td>
                                                <td className="num py-2 text-right"><Money value={tx.net_cash_reporting} currency={reportingCcy} /></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}

                    {/* Disclaimers */}
                    <div className="border-border bg-muted/30 text-muted-foreground space-y-1 rounded border p-3 text-[11px]">
                        {Object.values(report.disclaimers).map((msg, i) => (
                            <div key={i} className="flex items-start gap-1.5">
                                <Info className="mt-0.5 h-3 w-3 shrink-0" />
                                <span>{msg}</span>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Recent Export History */}
                <section className="bg-card border-border rounded-md border p-4 space-y-3">
                    <div className="flex items-center gap-2">
                        <Download className="h-4 w-4 text-muted-foreground" />
                        <h2 className="text-sm font-semibold">{t('reports.history.title')}</h2>
                    </div>
                    {exportHistory.length === 0 ? (
                        <p className="text-muted-foreground text-xs">{t('reports.history.empty')}</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="border-border text-muted-foreground border-b">
                                    <tr>
                                        <th className="py-2 pr-3 font-medium">{t('reports.history.type')}</th>
                                        <th className="py-2 pr-3 font-medium">{t('reports.history.format')}</th>
                                        <th className="py-2 pr-3 font-medium">{t('risk.fx.col_ccy')}</th>
                                        <th className="py-2 pr-3 font-medium">{t('reports.history.range')}</th>
                                        <th className="py-2 pr-3 text-right font-medium">{t('reports.rows_label')}</th>
                                        <th className="py-2 text-right font-medium">{t('div.col.date')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {exportHistory.map((item) => (
                                        <tr key={item.id}>
                                            <td className="py-2 pr-3 font-medium">{t(`reports.type.${item.report_type}`)}</td>
                                            <td className="py-2 pr-3 uppercase">{item.format}</td>
                                            <td className="py-2 pr-3">{item.reporting_currency}</td>
                                            <td className="py-2 pr-3">{item.range_code}</td>
                                            <td className="num py-2 pr-3 text-right">{item.row_count}</td>
                                            <td className="num py-2 text-right">
                                                {formatDateTime(item.generated_at, locale, settings?.timezone)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
