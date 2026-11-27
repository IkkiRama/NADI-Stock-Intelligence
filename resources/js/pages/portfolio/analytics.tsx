import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { MetricCard } from '@/components/nadi/metric-card';
import { Money } from '@/components/nadi/money';
import {
    type AttributionComponents,
    type BenchmarkSeriesPoint,
    PerformanceCharts,
    type PeriodReturnPoint,
    type RollingExcessPoint,
    type TwrDailyPoint,
} from '@/components/nadi/portfolio/performance-charts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatNumber, formatPct } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Briefcase, Info, Scale } from 'lucide-react';
import { useState } from 'react';

interface StockAttributionRow {
    instrument_id: number;
    symbol: string;
    name: string;
    exchange_code: string;
    sector: string;
    country_code: string;
    currency: string;
    end_market_value_rep: string;
    local_price_effect_rep: string;
    fx_effect_rep: string;
    dividend_rep: string;
    fee_tax_rep: string;
    total_pnl_rep: string;
    contribution_pct: string | null;
}

interface GroupedAttributionRow {
    key: string;
    label: string;
    positions_count?: number;
    broker_code?: string;
    end_market_value_rep?: string;
    end_equity_rep?: string;
    local_price_effect_rep: string;
    fx_effect_rep: string;
    dividend_rep: string;
    fee_tax_rep: string;
    cash_fx_and_adjustments_rep?: string;
    total_pnl_rep: string;
    contribution_pct: string | null;
}

interface AnalyticsProps {
    portfolio: {
        id: number;
        name: string;
        reporting_currency: string;
        cost_basis_method: 'weighted_average' | 'fifo';
    };
    summary: {
        reporting_currency: string;
        cost_basis_method: string;
        total_equity: string;
        as_of: string | null;
        is_demo: boolean;
    };
    accounts: Array<{
        id: number;
        account_name: string;
        base_currency: string;
    }>;
    filters: {
        account_id: number | null;
        currency: string;
        method: 'weighted_average' | 'fifo';
        range: string;
        start_date: string | null;
        end_date: string | null;
        inception_date: string | null;
        benchmark: string;
        benchmark_ccy_mode: 'reporting_aligned' | 'native_index';
    };
    currencies: string[];
    benchmarks: Array<{
        id: number;
        code: string;
        name: string;
        country_code: string;
        currency: string;
        index_type: string;
    }>;
    windowMeta: {
        has_data: boolean;
        inception_date: string | null;
        start_date: string | null;
        end_date: string | null;
        begin_equity: string;
        end_equity: string;
        missing_price_symbols: string[];
        missing_fx: string[];
        is_demo: boolean;
    };
    twr: {
        has_data: boolean;
        twr_total: string | null;
        twr_annualized: string | null;
        elapsed_days: number;
        short_period_notice: boolean;
        annualization_skipped_reason: string | null;
        total_inflows_rep: string;
        total_outflows_rep: string;
        net_external_flows_rep: string;
        net_investment_gain_rep: string;
        daily_series: TwrDailyPoint[];
        monthly_returns: PeriodReturnPoint[];
        annual_returns: PeriodReturnPoint[];
    };
    xirr: {
        has_data: boolean;
        xirr_annualized: string | null;
        modified_dietz_return: string | null;
        net_investment_gain_rep: string;
        status: string;
        iterations: number;
        cash_flows_count: number;
        elapsed_days: number;
        short_horizon_warning: boolean;
        multiple_roots_warning: boolean;
    };
    attribution: {
        has_data: boolean;
        by_stock: StockAttributionRow[];
        top_contributors: StockAttributionRow[];
        top_detractors: StockAttributionRow[];
        by_sector: GroupedAttributionRow[];
        by_country: GroupedAttributionRow[];
        by_broker: GroupedAttributionRow[];
        components: AttributionComponents;
        reconciliation: {
            average_capital_rep: string | null;
            begin_equity_rep: string;
            end_equity_rep: string;
            total_gain_rep: string;
            modified_dietz_return: string | null;
            twr_return: string | null;
            geometric_linking_residual: string | null;
        };
    };
    benchmark: {
        has_data: boolean;
        code: string;
        name: string;
        country_code: string;
        index_currency: string;
        reporting_currency: string;
        index_type: string;
        currency_mode: 'reporting_aligned' | 'native_index';
        currency_aligned: boolean;
        currency_converted: boolean;
        currency_mismatch_warning: boolean;
        missing_fx: boolean;
        is_demo: boolean;
        observations_count: number;
        min_observations_required: number;
        portfolio_twr: string | null;
        benchmark_return: string | null;
        excess_return: string | null;
        tracking_error: string | null;
        information_ratio: string | null;
        series: BenchmarkSeriesPoint[];
        rolling_excess_20d: RollingExcessPoint[];
    };
}

const PRESET_RANGES = ['1M', '3M', '6M', 'YTD', '1Y', 'ALL'] as const;

export default function PortfolioAnalyticsPage({
    summary,
    accounts,
    filters,
    currencies,
    benchmarks,
    windowMeta,
    twr,
    xirr,
    attribution,
    benchmark,
}: AnalyticsProps) {
    const { t, locale } = useT();
    const { settings } = usePage<SharedData>().props;
    const isProMode = settings?.analysis_mode === 'pro';

    const [customStart, setCustomStart] = useState(filters.start_date ?? '');
    const [customEnd, setCustomEnd] = useState(filters.end_date ?? '');
    const [attrTab, setAttrTab] = useState<'stock' | 'sector' | 'country' | 'broker'>('stock');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.portfolio'), href: '/portfolio' },
        { title: t('nav.portfolio_analytics'), href: '/portfolio-analytics' },
    ];

    const navigateFilters = (overrides: Record<string, string | number | null>) => {
        const next: Record<string, string> = {
            currency: String(overrides.currency ?? filters.currency),
            method: String(overrides.method ?? filters.method),
            range: String(overrides.range ?? filters.range),
            benchmark: String(overrides.benchmark ?? filters.benchmark),
            benchmark_ccy_mode: String(overrides.benchmark_ccy_mode ?? filters.benchmark_ccy_mode),
        };
        const acct = overrides.account_id !== undefined ? overrides.account_id : filters.account_id;
        if (acct) next.account_id = String(acct);

        const sDate = overrides.start_date !== undefined ? overrides.start_date : filters.start_date;
        const eDate = overrides.end_date !== undefined ? overrides.end_date : filters.end_date;
        if (next.range === 'CUSTOM' && sDate && eDate) {
            next.start_date = String(sDate);
            next.end_date = String(eDate);
        }

        router.get('/portfolio-analytics', next, { preserveState: true, preserveScroll: true });
    };

    const applyCustomDates = (e: React.FormEvent) => {
        e.preventDefault();
        if (!customStart || !customEnd) return;
        navigateFilters({ range: 'CUSTOM', start_date: customStart, end_date: customEnd });
    };

    const reportingCcy = filters.currency;
    const benchCcyBadge = benchmark.currency_aligned
        ? benchmark.currency_converted
            ? `${benchmark.index_currency} -> ${benchmark.reporting_currency}`
            : benchmark.reporting_currency
        : `${benchmark.index_currency} (Native)`;

    const renderSignedMoney = (value: string) => {
        const n = Number(value);
        const cls = n > 0 ? 'text-[var(--nadi-gain-ink)]' : n < 0 ? 'text-[var(--nadi-loss-ink)]' : 'text-muted-foreground';
        const prefix = n > 0 ? '+' : '';
        return (
            <span className={cn('num font-medium', cls)}>
                {prefix}
                <Money value={value} currency={reportingCcy} />
            </span>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('perf.title')} />

            <div className="space-y-5">
                {/* Header & Filters */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className="bg-[var(--nadi-ice)]/35 text-foreground flex h-9 w-9 items-center justify-center rounded-md">
                            <BarChart3 className="h-4 w-4" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-lg font-semibold tracking-tight">{t('perf.title')}</h1>
                                {(windowMeta.is_demo || benchmark.is_demo) && <DemoBadge />}
                            </div>
                            <p className="text-muted-foreground text-xs">{t('perf.subtitle')}</p>
                            <DataFreshness
                                asOf={windowMeta.end_date ?? summary.as_of}
                                isDemo={windowMeta.is_demo}
                                className="mt-1"
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild size="sm" variant="outline" className="h-8 gap-1 text-xs">
                            <Link href="/portfolio">
                                <Briefcase className="h-3.5 w-3.5" />
                                <span>{t('nav.portfolio')}</span>
                            </Link>
                        </Button>

                        <Button asChild size="sm" variant="outline" className="h-8 gap-1 text-xs">
                            <Link href="/risk-rebalancing">
                                <Scale className="h-3.5 w-3.5" />
                                <span>{t('nav.risk_rebalancing')}</span>
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
                </div>

                {/* Time Range & Benchmark Controls */}
                <div className="bg-card border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                    <div className="flex flex-wrap items-center gap-1.5">
                        {PRESET_RANGES.map((r) => (
                            <Button
                                key={r}
                                type="button"
                                size="sm"
                                variant={filters.range === r ? 'default' : 'ghost'}
                                className="h-7 px-2.5 text-xs"
                                onClick={() => navigateFilters({ range: r })}
                            >
                                {r}
                            </Button>
                        ))}
                        <form onSubmit={applyCustomDates} className="ml-1 flex flex-wrap items-center gap-1.5">
                            <Input
                                type="date"
                                value={customStart}
                                onChange={(e) => setCustomStart(e.target.value)}
                                className="h-7 w-32 text-xs"
                                aria-label="Start date"
                            />
                            <span className="text-muted-foreground text-xs">–</span>
                            <Input
                                type="date"
                                value={customEnd}
                                onChange={(e) => setCustomEnd(e.target.value)}
                                className="h-7 w-32 text-xs"
                                aria-label="End date"
                            />
                            <Button type="submit" size="sm" variant="outline" className="h-7 px-2 text-xs">
                                {t('perf.apply_dates')}
                            </Button>
                        </form>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted-foreground text-xs font-medium">{t('perf.benchmark.label')}:</span>
                        <Select value={filters.benchmark} onValueChange={(code) => navigateFilters({ benchmark: code })}>
                            <SelectTrigger className="h-8 w-44 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {benchmarks.map((b) => (
                                    <SelectItem key={b.code} value={b.code}>
                                        {b.code} · {b.currency} ({b.country_code})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.benchmark_ccy_mode}
                            onValueChange={(mode) => navigateFilters({ benchmark_ccy_mode: mode })}
                        >
                            <SelectTrigger className="h-8 w-44 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="reporting_aligned">
                                    {t('perf.benchmark.mode_aligned', { currency: reportingCcy })}
                                </SelectItem>
                                <SelectItem value="native_index">{t('perf.benchmark.mode_native')}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* Notices: Currency Mismatch, Missing FX/Prices, Short Horizon */}
                {benchmark.currency_mismatch_warning && (
                    <div className="border-[var(--nadi-coral)] bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)] flex items-start gap-2 rounded-md border px-3 py-2 text-xs">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            {t('perf.notice.currency_mismatch', {
                                benchmark: benchmark.code,
                                indexCcy: benchmark.index_currency,
                                reportingCcy: benchmark.reporting_currency,
                            })}
                        </span>
                    </div>
                )}

                {windowMeta.has_data && (windowMeta.missing_price_symbols.length > 0 || windowMeta.missing_fx.length > 0) && (
                    <div className="border-border bg-muted/40 text-muted-foreground flex items-start gap-2 rounded-md border px-3 py-2 text-xs">
                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>
                            {t('perf.notice.partial_data', {
                                symbols: windowMeta.missing_price_symbols.join(', ') || '—',
                                fx: windowMeta.missing_fx.join(', ') || '—',
                            })}
                        </span>
                    </div>
                )}

                {!windowMeta.has_data ? (
                    <EmptyState
                        icon={BarChart3}
                        title={t('perf.empty.title')}
                        description={t('perf.empty.desc')}
                        actionLabel={t('nav.portfolio')}
                        actionHref="/portfolio"
                    />
                ) : (
                    <>
                        {/* KPI Strip */}
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                            <MetricCard
                                label={t('perf.kpi.twr')}
                                value={<ChangeBadge changePct={twr.twr_total} />}
                                badge={
                                    <span className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 text-[10px] font-medium">
                                        {twr.elapsed_days}D
                                    </span>
                                }
                                subtext={
                                    twr.twr_annualized !== null
                                        ? `${t('perf.kpi.annualized')}: ${formatPct(twr.twr_annualized, locale, true)}`
                                        : t('perf.kpi.under_30d_ann')
                                }
                            />

                            <MetricCard
                                label={t('perf.kpi.xirr')}
                                value={
                                    xirr.xirr_annualized !== null ? (
                                        <ChangeBadge changePct={xirr.xirr_annualized} />
                                    ) : (
                                        <span className="text-muted-foreground text-base font-medium">—</span>
                                    )
                                }
                                badge={
                                    <span className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 text-[10px] font-medium">
                                        {xirr.status === 'converged'
                                            ? `${xirr.cash_flows_count} CF`
                                            : t(`perf.xirr_status.${xirr.status}`)}
                                    </span>
                                }
                                subtext={
                                    xirr.modified_dietz_return !== null
                                        ? `${t('perf.kpi.mod_dietz')}: ${formatPct(xirr.modified_dietz_return, locale, true)}`
                                        : t('perf.kpi.mwr_note')
                                }
                            />

                            <MetricCard
                                label={`${benchmark.code} (${benchmark.index_type === 'total_return' ? 'TR' : 'Price'})`}
                                value={<ChangeBadge changePct={benchmark.benchmark_return} />}
                                badge={
                                    <span className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 text-[10px] font-medium">
                                        {benchCcyBadge}
                                    </span>
                                }
                                subtext={benchmark.name}
                            />

                            <MetricCard
                                label={t('perf.kpi.excess_return')}
                                value={<ChangeBadge changePct={benchmark.excess_return} />}
                                subtext={t('perf.kpi.excess_sub')}
                            />

                            <MetricCard
                                label={t('perf.kpi.tracking_error')}
                                value={
                                    benchmark.tracking_error !== null ? (
                                        <span className="num">{formatPct(benchmark.tracking_error, locale, false)}</span>
                                    ) : (
                                        <span className="text-muted-foreground text-base font-medium">—</span>
                                    )
                                }
                                subtext={
                                    benchmark.information_ratio !== null
                                        ? `IR: ${formatNumber(benchmark.information_ratio, locale, 2, 2)}`
                                        : t('perf.kpi.min_obs_note', {
                                              count: benchmark.observations_count,
                                              min: benchmark.min_observations_required,
                                          })
                                }
                            />

                            <MetricCard
                                label={t('perf.kpi.net_gain')}
                                value={renderSignedMoney(twr.net_investment_gain_rep)}
                                subtext={
                                    <span>
                                        {t('perf.kpi.net_flows')}:{' '}
                                        <Money value={twr.net_external_flows_rep} currency={reportingCcy} />
                                    </span>
                                }
                            />
                        </div>

                        {/* Methodology Disclosure Strip */}
                        <div className="border-border bg-muted/30 text-muted-foreground grid gap-2 rounded-md border p-3 text-xs md:grid-cols-2">
                            <div className="flex items-start gap-2">
                                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>{t('perf.disclosure.twr_vs_xirr')}</span>
                            </div>
                            <div className="flex items-start gap-2">
                                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>
                                    {t('perf.disclosure.price_index_note', {
                                        code: benchmark.code,
                                        type: benchmark.index_type,
                                        window: `${formatDate(windowMeta.start_date, locale)} – ${formatDate(windowMeta.end_date, locale)}`,
                                    })}
                                </span>
                            </div>
                        </div>

                        {/* Charts */}
                        <PerformanceCharts
                            twrDaily={twr.daily_series}
                            benchmarkSeries={benchmark.series}
                            benchmarkCode={benchmark.code}
                            benchmarkCurrencyLabel={benchCcyBadge}
                            rollingExcess20d={benchmark.rolling_excess_20d}
                            monthlyReturns={twr.monthly_returns}
                            annualReturns={twr.annual_returns}
                            components={attribution.components}
                            reportingCurrency={reportingCcy}
                            isProMode={isProMode}
                        />

                        {/* Top Contributors & Detractors */}
                        <div className="grid gap-4 lg:grid-cols-2">
                            <section className="bg-card border-border rounded-md border p-4 space-y-2.5">
                                <h2 className="text-sm font-semibold">{t('perf.attr.top_contributors')}</h2>
                                {attribution.top_contributors.length === 0 ? (
                                    <p className="text-muted-foreground text-xs">{t('perf.empty.no_contributors')}</p>
                                ) : (
                                    <div className="space-y-2">
                                        {attribution.top_contributors.map((row) => (
                                            <div key={row.instrument_id} className="flex items-center justify-between gap-2 text-xs">
                                                <div>
                                                    <Link
                                                        href={`/stocks/${row.exchange_code}/${row.symbol}`}
                                                        className="font-semibold hover:underline"
                                                    >
                                                        {row.symbol}
                                                    </Link>
                                                    <span className="text-muted-foreground ml-1.5">
                                                        {row.sector} · {row.country_code}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-3 text-right">
                                                    {renderSignedMoney(row.total_pnl_rep)}
                                                    <ChangeBadge changePct={row.contribution_pct} pill />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>

                            <section className="bg-card border-border rounded-md border p-4 space-y-2.5">
                                <h2 className="text-sm font-semibold">{t('perf.attr.top_detractors')}</h2>
                                {attribution.top_detractors.length === 0 ? (
                                    <p className="text-muted-foreground text-xs">{t('perf.empty.no_detractors')}</p>
                                ) : (
                                    <div className="space-y-2">
                                        {attribution.top_detractors.map((row) => (
                                            <div key={row.instrument_id} className="flex items-center justify-between gap-2 text-xs">
                                                <div>
                                                    <Link
                                                        href={`/stocks/${row.exchange_code}/${row.symbol}`}
                                                        className="font-semibold hover:underline"
                                                    >
                                                        {row.symbol}
                                                    </Link>
                                                    <span className="text-muted-foreground ml-1.5">
                                                        {row.sector} · {row.country_code}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-3 text-right">
                                                    {renderSignedMoney(row.total_pnl_rep)}
                                                    <ChangeBadge changePct={row.contribution_pct} pill />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>
                        </div>

                        {/* Performance Attribution Table */}
                        <section className="bg-card border-border rounded-md border p-4 space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h2 className="text-sm font-semibold">{t('perf.attr.title')}</h2>
                                    <p className="text-muted-foreground text-[11px]">{t('perf.attr.subtitle')}</p>
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    {(
                                        [
                                            ['stock', t('perf.attr.tab.stock')],
                                            ['sector', t('perf.attr.tab.sector')],
                                            ['country', t('perf.attr.tab.country')],
                                            ['broker', t('perf.attr.tab.broker')],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <Button
                                            key={key}
                                            type="button"
                                            size="sm"
                                            variant={attrTab === key ? 'secondary' : 'ghost'}
                                            className="h-7 text-xs"
                                            onClick={() => setAttrTab(key)}
                                        >
                                            {label}
                                        </Button>
                                    ))}
                                </div>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-border text-muted-foreground border-b">
                                        <tr>
                                            <th className="py-2 pr-3 font-medium">{t('perf.col.item')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('perf.col.local_price')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('perf.col.fx_effect')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('perf.col.dividends')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('perf.col.fees')}</th>
                                            <th className="py-2 pr-3 text-right font-medium">{t('perf.col.total_pnl')}</th>
                                            <th className="py-2 text-right font-medium">{t('perf.col.contribution')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {attrTab === 'stock' &&
                                            attribution.by_stock.map((row) => (
                                                <tr key={row.instrument_id} className="hover:bg-muted/30">
                                                    <td className="py-2 pr-3">
                                                        <Link
                                                            href={`/stocks/${row.exchange_code}/${row.symbol}`}
                                                            className="font-semibold hover:underline"
                                                        >
                                                            {row.symbol}
                                                        </Link>
                                                        <div className="text-muted-foreground text-[11px]">
                                                            {row.name} · {row.currency}
                                                        </div>
                                                    </td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.local_price_effect_rep)}</td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.fx_effect_rep)}</td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.dividend_rep)}</td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.fee_tax_rep)}</td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.total_pnl_rep)}</td>
                                                    <td className="num py-2 text-right">
                                                        <ChangeBadge changePct={row.contribution_pct} />
                                                    </td>
                                                </tr>
                                            ))}

                                        {attrTab !== 'stock' &&
                                            (attrTab === 'sector'
                                                ? attribution.by_sector
                                                : attrTab === 'country'
                                                  ? attribution.by_country
                                                  : attribution.by_broker
                                            ).map((row) => (
                                                <tr key={row.key} className="hover:bg-muted/30">
                                                    <td className="py-2 pr-3 font-medium">
                                                        {row.label}
                                                        {row.positions_count !== undefined && (
                                                            <span className="text-muted-foreground ml-1.5 text-[11px]">
                                                                ({row.positions_count})
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.local_price_effect_rep)}</td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.fx_effect_rep)}</td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.dividend_rep)}</td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.fee_tax_rep)}</td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.total_pnl_rep)}</td>
                                                    <td className="num py-2 text-right">
                                                        <ChangeBadge changePct={row.contribution_pct} />
                                                    </td>
                                                </tr>
                                            ))}
                                    </tbody>
                                    <tfoot className="border-border bg-muted/25 border-t text-xs font-medium">
                                        {attrTab !== 'broker' && (
                                            <tr>
                                                <td className="py-2 pr-3">{t('perf.driver.cash_other')}</td>
                                                <td className="py-2 pr-3 text-right">—</td>
                                                <td className="py-2 pr-3 text-right">—</td>
                                                <td className="py-2 pr-3 text-right">—</td>
                                                <td className="py-2 pr-3 text-right">—</td>
                                                <td className="num py-2 pr-3 text-right">
                                                    {renderSignedMoney(attribution.components.cash_fx_and_adjustments_rep)}
                                                </td>
                                                <td className="num py-2 text-right">
                                                    <ChangeBadge changePct={attribution.components.cash_fx_and_adjustments_pct} />
                                                </td>
                                            </tr>
                                        )}
                                        <tr className="border-border border-t font-semibold">
                                            <td className="py-2 pr-3">{t('perf.reconcile.total_row')}</td>
                                            <td className="num py-2 pr-3 text-right">
                                                {renderSignedMoney(attribution.components.local_price_effect_rep)}
                                            </td>
                                            <td className="num py-2 pr-3 text-right">
                                                {renderSignedMoney(attribution.components.fx_effect_rep)}
                                            </td>
                                            <td className="num py-2 pr-3 text-right">
                                                {renderSignedMoney(attribution.components.dividend_rep)}
                                            </td>
                                            <td className="num py-2 pr-3 text-right">
                                                {renderSignedMoney(attribution.components.fee_tax_rep)}
                                            </td>
                                            <td className="num py-2 pr-3 text-right">
                                                {renderSignedMoney(attribution.reconciliation.total_gain_rep)}
                                            </td>
                                            <td className="num py-2 text-right">
                                                <ChangeBadge changePct={attribution.reconciliation.modified_dietz_return} />
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            {/* Reconciliation Footer */}
                            <div className="border-border bg-muted/20 grid gap-2 rounded border p-2.5 text-[11px] sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <span className="text-muted-foreground">{t('perf.reconcile.avg_capital')}: </span>
                                    <Money value={attribution.reconciliation.average_capital_rep} currency={reportingCcy} />
                                </div>
                                <div>
                                    <span className="text-muted-foreground">{t('perf.kpi.mod_dietz')}: </span>
                                    <ChangeBadge changePct={attribution.reconciliation.modified_dietz_return} />
                                </div>
                                <div>
                                    <span className="text-muted-foreground">{t('perf.reconcile.twr')}: </span>
                                    <ChangeBadge changePct={attribution.reconciliation.twr_return} />
                                </div>
                                <div>
                                    <span className="text-muted-foreground">{t('perf.reconcile.residual')}: </span>
                                    <span className="num font-medium">
                                        {formatPct(attribution.reconciliation.geometric_linking_residual, locale, true)}
                                    </span>
                                </div>
                            </div>
                        </section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
