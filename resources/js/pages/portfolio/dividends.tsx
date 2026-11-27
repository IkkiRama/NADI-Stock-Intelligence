import { DataFreshness } from '@/components/nadi/data-freshness';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { MetricCard } from '@/components/nadi/metric-card';
import { Money } from '@/components/nadi/money';
import {
    DividendIncomeChart,
    type MonthlyProjectedDividend,
    type MonthlyRealizedDividend,
} from '@/components/nadi/portfolio/dividend-income-chart';
import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatNumber, formatPct } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Briefcase, Calendar, Info, TrendingUp } from 'lucide-react';
import { useState } from 'react';

interface HoldingDividendRow {
    instrument_id: number;
    symbol: string;
    name: string;
    exchange_code: string;
    country_code: string;
    currency: string;
    quantity: string;
    market_value_rep: string;
    total_cost_rep: string;
    estimated_annual_dps_local: string;
    tax_rate_pct: string;
    projected_annual_gross_rep: string;
    projected_annual_net_rep: string;
    realized_net_rep: string;
    yield_on_mv: string | null;
    yield_on_cost: string | null;
    payout_ratio: string | null;
    high_payout_flag: boolean;
    safeguard_triggered: boolean;
    estimation_basis: string;
}

interface UpcomingDividendEvent {
    id: number;
    instrument_id: number;
    symbol: string;
    name: string;
    exchange_code: string;
    ex_date: string;
    payment_date: string | null;
    declared_currency: string;
    cash_amount: string | null;
    is_held: boolean;
    held_shares: string | null;
    estimated_gross_rep: string | null;
    is_demo: boolean;
}

interface DividendLedgerRow {
    id: number;
    trade_date: string;
    transaction_type: string;
    account_name: string;
    instrument_id: number | null;
    symbol: string | null;
    exchange_code: string | null;
    currency: string;
    gross_amount: string;
    tax_amount: string;
    net_amount: string;
    net_amount_reporting: string;
    notes: string | null;
    is_demo: boolean;
}

interface DividendsPageProps {
    accounts: Array<{ id: number; account_name: string; base_currency: string }>;
    currencies: string[];
    filters: {
        account_id: number | null;
        currency: string;
        tax_pct: string;
    };
    dividends: {
        reporting_currency: string;
        is_demo: boolean;
        summary: {
            realized_net_ytd_rep: string;
            realized_net_12m_rep: string;
            realized_net_all_time_rep: string;
            realized_tax_all_time_rep: string;
            projected_annual_gross_rep: string;
            projected_annual_net_rep: string;
            projected_monthly_avg_rep: string;
            portfolio_yield_on_mv: string | null;
            portfolio_yield_on_cost: string | null;
            paying_holdings_count: number;
            total_holdings_count: number;
        };
        monthly_realized: MonthlyRealizedDividend[];
        monthly_projected: MonthlyProjectedDividend[];
        holdings: HoldingDividendRow[];
        upcoming_calendar: UpcomingDividendEvent[];
        ledger_history: DividendLedgerRow[];
    };
}

export default function DividendsPage({ accounts, currencies, filters, dividends }: DividendsPageProps) {
    const { t, locale } = useT();
    const [taxInput, setTaxInput] = useState(filters.tax_pct);
    const [tab, setTab] = useState<'holdings' | 'calendar' | 'history'>('holdings');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.portfolio'), href: '/portfolio' },
        { title: t('nav.dividends'), href: '/dividends' },
    ];

    const navigateFilters = (overrides: Record<string, string | number | null>) => {
        const next: Record<string, string> = {
            currency: String(overrides.currency ?? filters.currency),
        };
        const acct = overrides.account_id !== undefined ? overrides.account_id : filters.account_id;
        if (acct) next.account_id = String(acct);

        const tax = overrides.tax_pct !== undefined ? overrides.tax_pct : taxInput;
        if (tax !== '' && tax !== null) next.tax_pct = String(tax);

        router.get('/dividends', next, { preserveState: true, preserveScroll: true });
    };

    const reportingCcy = filters.currency;
    const s = dividends.summary;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('div.title')} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className="bg-[var(--nadi-sage)]/25 text-[var(--nadi-gain-ink)] flex h-9 w-9 items-center justify-center rounded-md">
                            <TrendingUp className="h-4 w-4" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-lg font-semibold tracking-tight">{t('div.title')}</h1>
                                {dividends.is_demo && <DemoBadge />}
                            </div>
                            <p className="text-muted-foreground text-xs">{t('div.subtitle')}</p>
                            <DataFreshness isDemo={dividends.is_demo} className="mt-1" />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild size="sm" variant="outline" className="h-8 gap-1 text-xs">
                            <Link href="/portfolio">
                                <Briefcase className="h-3.5 w-3.5" />
                                <span>{t('nav.portfolio')}</span>
                            </Link>
                        </Button>

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

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                navigateFilters({ tax_pct: taxInput });
                            }}
                            className="flex items-center gap-1.5 text-xs"
                        >
                            <Label htmlFor="div-tax-pct" className="text-muted-foreground text-xs">
                                {t('div.filter.tax_override')} (%)
                            </Label>
                            <Input
                                id="div-tax-pct"
                                type="number"
                                step="0.5"
                                min="0"
                                max="50"
                                placeholder="Auto"
                                value={taxInput}
                                onChange={(e) => setTaxInput(e.target.value)}
                                className="h-8 w-20 text-xs"
                            />
                            <Button type="submit" size="sm" variant="outline" className="h-8 px-2.5 text-xs">
                                {t('perf.apply_dates')}
                            </Button>
                        </form>
                    </div>
                </div>

                <div className="border-border bg-muted/30 text-muted-foreground flex items-start gap-2 rounded-md border px-3 py-2 text-xs">
                    <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>{t('div.estimate_disclosure')}</span>
                </div>

                {/* KPI Strip */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <MetricCard
                        label={t('div.kpi.realized_ytd')}
                        value={<Money value={s.realized_net_ytd_rep} currency={reportingCcy} />}
                        subtext={
                            <span>
                                12M: <Money value={s.realized_net_12m_rep} currency={reportingCcy} />
                            </span>
                        }
                    />
                    <MetricCard
                        label={t('div.kpi.projected_net')}
                        value={<Money value={s.projected_annual_net_rep} currency={reportingCcy} />}
                        badge={
                            <span className="bg-[var(--nadi-lavender)]/20 text-[var(--nadi-lavender-ink)] rounded px-1.5 py-0.5 text-[10px] font-medium">
                                {t('div.badge.estimate')}
                            </span>
                        }
                        subtext={
                            <span>
                                Gross: <Money value={s.projected_annual_gross_rep} currency={reportingCcy} />
                            </span>
                        }
                    />
                    <MetricCard
                        label={t('div.kpi.monthly_avg')}
                        value={<Money value={s.projected_monthly_avg_rep} currency={reportingCcy} />}
                        subtext={t('div.kpi.after_tax_note')}
                    />
                    <MetricCard
                        label={t('div.kpi.yield_mv')}
                        value={<span className="num">{formatPct(s.portfolio_yield_on_mv, locale, false)}</span>}
                        subtext={
                            <span>
                                {t('div.kpi.yield_cost')}: <strong className="num">{formatPct(s.portfolio_yield_on_cost, locale, false)}</strong>
                            </span>
                        }
                    />
                    <MetricCard
                        label={t('div.kpi.paying_count')}
                        value={
                            <span className="num">
                                {s.paying_holdings_count} / {s.total_holdings_count}
                            </span>
                        }
                        subtext={
                            <span>
                                {t('div.kpi.all_time_realized')}: <Money value={s.realized_net_all_time_rep} currency={reportingCcy} />
                            </span>
                        }
                    />
                </div>

                {/* Monthly Realized vs Projected Chart */}
                <section className="bg-card border-border rounded-md border p-4 space-y-3">
                    <div>
                        <h2 className="text-sm font-semibold">{t('div.chart.title')}</h2>
                        <p className="text-muted-foreground text-[11px]">{t('div.chart.subtitle')}</p>
                    </div>
                    <DividendIncomeChart
                        monthlyRealized={dividends.monthly_realized}
                        monthlyProjected={dividends.monthly_projected}
                        reportingCurrency={reportingCcy}
                    />
                </section>

                {/* Tabs */}
                <div className="border-border flex flex-wrap gap-1 border-b pb-2">
                    {(
                        [
                            ['holdings', `${t('div.tab.holdings')} (${dividends.holdings.length})`],
                            ['calendar', `${t('div.tab.calendar')} (${dividends.upcoming_calendar.length})`],
                            ['history', `${t('div.tab.history')} (${dividends.ledger_history.length})`],
                        ] as const
                    ).map(([key, label]) => (
                        <Button
                            key={key}
                            type="button"
                            size="sm"
                            variant={tab === key ? 'secondary' : 'ghost'}
                            className="h-8 text-xs font-medium"
                            onClick={() => setTab(key)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>

                {/* TAB 1: Per-Holding Dividend Profile */}
                {tab === 'holdings' && (
                    <section className="bg-card border-border rounded-md border p-4">
                        {dividends.holdings.length === 0 ? (
                            <EmptyState
                                title={t('div.empty.no_holdings')}
                                actionLabel={t('nav.portfolio')}
                                actionHref="/portfolio"
                            />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-border text-muted-foreground border-b">
                                        <tr>
                                            <th className="py-2 pr-3 font-medium">{t('perf.col.item')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.shares')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.est_dps')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.tax_pct')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.proj_net')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.yield_mv')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.yield_cost')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.realized_net')}</th>
                                            <th className="py-2 text-right font-medium">{t('div.col.payout_safety')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {dividends.holdings.map((row) => (
                                            <tr key={row.instrument_id} className="hover:bg-muted/30">
                                                <td className="py-2 pr-3">
                                                    <Link
                                                        href={`/stocks/${row.exchange_code}/${row.symbol}`}
                                                        className="font-semibold hover:underline"
                                                    >
                                                        {row.symbol}
                                                    </Link>
                                                    <div className="text-muted-foreground text-[11px]">
                                                        {row.exchange_code} · {t(`div.basis.${row.estimation_basis}`)}
                                                    </div>
                                                </td>
                                                <td className="num py-2 pr-3 text-right">{formatNumber(row.quantity, locale, 0, 2)}</td>
                                                <td className="num py-2 pr-3 text-right">
                                                    {Number(row.estimated_annual_dps_local) > 0 ? (
                                                        <Money value={row.estimated_annual_dps_local} currency={row.currency} />
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="num py-2 pr-3 text-right">{row.tax_rate_pct}%</td>
                                                <td className="num py-2 pr-3 text-right font-medium">
                                                    <Money value={row.projected_annual_net_rep} currency={reportingCcy} />
                                                </td>
                                                <td className="num py-2 pr-3 text-right">{formatPct(row.yield_on_mv, locale, false)}</td>
                                                <td className="num py-2 pr-3 text-right">{formatPct(row.yield_on_cost, locale, false)}</td>
                                                <td className="num py-2 pr-3 text-right">
                                                    <Money value={row.realized_net_rep} currency={reportingCcy} />
                                                </td>
                                                <td className="num py-2 text-right">
                                                    <div className="inline-flex items-center justify-end gap-1.5">
                                                        <span>{formatPct(row.payout_ratio, locale, false)}</span>
                                                        {row.high_payout_flag && (
                                                            <span
                                                                title={t('div.high_payout_hint')}
                                                                className="inline-flex items-center rounded bg-[var(--nadi-loss-bg)] px-1.5 py-0.5 text-[10px] font-medium text-[var(--nadi-loss-ink)]"
                                                            >
                                                                <AlertTriangle className="mr-0.5 h-3 w-3" />
                                                                {'>85%'}
                                                            </span>
                                                        )}
                                                        {row.safeguard_triggered && <SafetyBadge category="high" safeguard />}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                )}

                {/* TAB 2: Upcoming Dividend Calendar */}
                {tab === 'calendar' && (
                    <section className="bg-card border-border rounded-md border p-4">
                        {dividends.upcoming_calendar.length === 0 ? (
                            <EmptyState icon={Calendar} title={t('div.empty.no_upcoming')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-border text-muted-foreground border-b">
                                        <tr>
                                            <th className="py-2 pr-3 font-medium">{t('perf.col.item')}</th>
                                            <th className="py-2 pr-3 font-medium">Ex-Date</th>
                                            <th className="py-2 pr-3 font-medium">Pay Date</th>
                                            <th className="py-2 pr-3 text-right font-medium">DPS</th>
                                            <th className="py-2 text-right font-medium">{t('div.col.est_payout')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {dividends.upcoming_calendar.map((ev) => (
                                            <tr key={ev.id} className={cn(ev.is_held && 'bg-[var(--nadi-sage)]/10')}>
                                                <td className="py-2 pr-3">
                                                    <div className="flex items-center gap-1.5">
                                                        <Link href={`/stocks/${ev.exchange_code}/${ev.symbol}`} className="font-semibold hover:underline">
                                                            {ev.symbol}
                                                        </Link>
                                                        {ev.is_held && (
                                                            <span className="bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)] rounded px-1.5 py-0.5 text-[10px] font-medium">
                                                                {t('div.badge.held')}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="text-muted-foreground text-[11px]">{ev.name}</div>
                                                </td>
                                                <td className="num py-2 pr-3">{formatDate(ev.ex_date, locale)}</td>
                                                <td className="num py-2 pr-3">{ev.payment_date ? formatDate(ev.payment_date, locale) : '—'}</td>
                                                <td className="num py-2 pr-3 text-right">
                                                    <Money value={ev.cash_amount} currency={ev.declared_currency} />
                                                </td>
                                                <td className="num py-2 text-right font-medium">
                                                    {ev.estimated_gross_rep ? (
                                                        <Money value={ev.estimated_gross_rep} currency={reportingCcy} />
                                                    ) : (
                                                        '—'
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

                {/* TAB 3: Realized Dividend Ledger History */}
                {tab === 'history' && (
                    <section className="bg-card border-border rounded-md border p-4">
                        {dividends.ledger_history.length === 0 ? (
                            <EmptyState title={t('div.empty.no_history')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-border text-muted-foreground border-b">
                                        <tr>
                                            <th className="py-2 pr-3 font-medium">{t('div.col.date')}</th>
                                            <th className="py-2 pr-3 font-medium">{t('perf.col.item')}</th>
                                            <th className="py-2 pr-3 font-medium">{t('div.col.account')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.gross_tx')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('div.col.tax_tx')}</th>
                                            <th className="py-2 text-right font-medium">{t('div.col.net_reporting')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {dividends.ledger_history.map((row) => (
                                            <tr key={row.id}>
                                                <td className="num py-2 pr-3">{formatDate(row.trade_date, locale)}</td>
                                                <td className="py-2 pr-3">
                                                    {row.symbol ? (
                                                        <Link href={`/stocks/${row.exchange_code}/${row.symbol}`} className="font-semibold hover:underline">
                                                            {row.symbol}
                                                        </Link>
                                                    ) : (
                                                        <span>—</span>
                                                    )}
                                                    <div className="text-muted-foreground text-[11px]">{row.notes ?? row.transaction_type}</div>
                                                </td>
                                                <td className="py-2 pr-3">{row.account_name}</td>
                                                <td className="num py-2 pr-3 text-right">
                                                    <Money value={row.gross_amount} currency={row.currency} />
                                                </td>
                                                <td className="num py-2 pr-3 text-right">
                                                    <Money value={row.tax_amount} currency={row.currency} />
                                                </td>
                                                <td className="num text-[var(--nadi-gain-ink)] py-2 text-right font-medium">
                                                    <Money value={row.net_amount_reporting} currency={reportingCcy} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
