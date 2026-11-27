import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { MetricCard } from '@/components/nadi/metric-card';
import { Money } from '@/components/nadi/money';
import {
    RebalanceWeightChart,
    UnderwaterDrawdownChart,
    type UnderwaterPoint,
} from '@/components/nadi/portfolio/risk-charts';
import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatNumber, formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Briefcase, Info, Plus, Scale, ShieldAlert, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface DrawdownEpisode {
    peak_date: string;
    trough_date: string;
    recovery_date: string | null;
    depth_pct: string;
    days_to_trough: number;
    days_to_recovery: number | null;
    status: 'recovered' | 'underwater';
}

interface CorrelationRow {
    instrument_id: number;
    symbol: string;
    cells: Array<{
        symbol: string;
        correlation: string | null;
        overlap: number;
    }>;
}

interface CurrencyExposureRow {
    currency: string;
    is_reporting_currency: boolean;
    equity_rep: string;
    cash_rep: string;
    total_rep: string;
    weight_pct: string;
    shock_plus_5pct_rep: string;
    shock_minus_5pct_rep: string;
    shock_plus_10pct_rep: string;
    shock_minus_10pct_rep: string;
    portfolio_impact_10pct: string;
}

interface RebalanceRow {
    instrument_id: number;
    symbol: string;
    name: string;
    exchange_code: string;
    currency: string;
    lot_size: number;
    ref_price_local: string | null;
    held_shares: string;
    current_mv_rep: string;
    current_weight_pct: string;
    target_weight_pct: string;
    target_mv_rep: string;
    drift_pct: string;
    safety_category: string;
    safeguard_triggered: boolean;
    action: string;
    suggested_lots: number;
    suggested_shares: string;
    estimated_gross_local: string;
    estimated_gross_rep: string;
    estimated_fee_rep: string;
    net_cash_impact_rep: string;
    indicative_realized_pnl_rep: string | null;
    post_shares: string;
    post_mv_rep: string;
    post_weight_pct: string;
    post_drift_pct: string;
}

interface RiskRebalancingProps {
    portfolio: {
        id: number;
        name: string;
        reporting_currency: string;
        cost_basis_method: 'weighted_average' | 'fifo';
    };
    summary: {
        reporting_currency: string;
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
        benchmark: string;
        rf_pct: string;
        inflow: string;
    };
    currencies: string[];
    benchmarks: Array<{
        id: number;
        code: string;
        name: string;
        country_code: string;
        currency: string;
    }>;
    risk: {
        has_data: boolean;
        sufficient_observations: boolean;
        observations_count: number;
        min_observations_required: number;
        risk_free_rate_annual: string;
        max_drawdown: string | null;
        max_drawdown_episode: DrawdownEpisode | null;
        current_drawdown: string | null;
        annualized_volatility: string | null;
        downside_deviation: string | null;
        sharpe_ratio: string | null;
        sortino_ratio: string | null;
        calmar_ratio: string | null;
        var_95_daily_pct: string | null;
        var_95_daily_rep: string | null;
        cvar_95_daily_pct: string | null;
        positive_days_pct: string | null;
        beta: string | null;
        benchmark_correlation: string | null;
        r_squared: string | null;
        upside_capture: string | null;
        downside_capture: string | null;
        underwater_series: UnderwaterPoint[];
        drawdown_episodes: DrawdownEpisode[];
    };
    benchmarkMeta: {
        code: string;
        name: string;
        index_currency: string;
        index_type: string;
        observations_count: number;
        is_demo: boolean;
    };
    correlation: {
        has_data: boolean;
        symbols: Array<{ instrument_id: number; symbol: string; currency: string; observations: number }>;
        rows: CorrelationRow[];
        average_pairwise: string | null;
        highest_pair: { pair: string; correlation: string; overlap: number } | null;
        lowest_pair: { pair: string; correlation: string; overlap: number } | null;
        min_overlap_required: number;
    };
    currencyExposure: CurrencyExposureRow[];
    rebalance: {
        has_data: boolean;
        is_default_preview: boolean;
        reporting_currency: string;
        prefs: {
            cash_buffer_pct: number;
            drift_band_pct: number;
            risk_free_rate_pct: number | null;
            exclude_safety_flagged_buys: boolean;
        };
        additional_cash_inflow_rep: string;
        allocated_target_pct: string;
        cash_buffer_pct: string;
        total_target_plus_cash_pct: string;
        over_allocated: boolean;
        buys_scaled_by_cash: boolean;
        safety_blocked_count: number;
        pre_total_equity_rep: string;
        post_inflow_equity_rep: string;
        pre_cash_rep: string;
        target_cash_rep: string;
        post_cash_rep: string;
        post_total_equity_rep: string;
        total_buy_gross_rep: string;
        total_sell_gross_rep: string;
        total_estimated_fees_rep: string;
        turnover_pct: string;
        pre_hhi: string | null;
        post_hhi: string | null;
        rows: RebalanceRow[];
    };
    universeInstruments: Array<{
        id: number;
        symbol: string;
        name: string;
        exchange_code: string;
        currency: string;
        lot_size: number;
    }>;
}

interface DraftTarget {
    instrument_id: number;
    symbol: string;
    name: string;
    exchange_code: string;
    target_weight_pct: string;
}

const RANGES = ['1M', '3M', '6M', 'YTD', '1Y', 'ALL'] as const;

export default function RiskRebalancingPage({
    summary,
    accounts,
    filters,
    currencies,
    benchmarks,
    risk,
    benchmarkMeta,
    correlation,
    currencyExposure,
    rebalance,
    universeInstruments,
}: RiskRebalancingProps) {
    const { t, locale } = useT();
    const { flash, errors } = usePage<SharedData & { errors?: Record<string, string> }>().props;

    const [activeTab, setActiveTab] = useState<'rebalance' | 'drawdown' | 'correlation'>('rebalance');
    const [rfInput, setRfInput] = useState(filters.rf_pct);
    const [inflowInput, setInflowInput] = useState(filters.inflow);

    const [draftTargets, setDraftTargets] = useState<DraftTarget[]>(() =>
        rebalance.rows.map((r) => ({
            instrument_id: r.instrument_id,
            symbol: r.symbol,
            name: r.name,
            exchange_code: r.exchange_code,
            target_weight_pct: r.target_weight_pct,
        })),
    );
    const [cashBufferInput, setCashBufferInput] = useState(String(rebalance.prefs.cash_buffer_pct));
    const [driftBandInput, setDriftBandInput] = useState(String(rebalance.prefs.drift_band_pct));
    const [excludeSafetyBuys, setExcludeSafetyBuys] = useState<boolean>(rebalance.prefs.exclude_safety_flagged_buys);
    const [addInstrumentId, setAddInstrumentId] = useState<string>('');
    const [savingTargets, setSavingTargets] = useState(false);

    useEffect(() => {
        setDraftTargets(
            rebalance.rows.map((r) => ({
                instrument_id: r.instrument_id,
                symbol: r.symbol,
                name: r.name,
                exchange_code: r.exchange_code,
                target_weight_pct: r.target_weight_pct,
            })),
        );
        setCashBufferInput(String(rebalance.prefs.cash_buffer_pct));
        setDriftBandInput(String(rebalance.prefs.drift_band_pct));
        setExcludeSafetyBuys(rebalance.prefs.exclude_safety_flagged_buys);
        setRfInput(filters.rf_pct);
        setInflowInput(filters.inflow);
    }, [rebalance, filters]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.portfolio'), href: '/portfolio' },
        { title: t('nav.risk_rebalancing'), href: '/risk-rebalancing' },
    ];

    const navigateFilters = (overrides: Record<string, string | number | null>) => {
        const next: Record<string, string> = {
            currency: String(overrides.currency ?? filters.currency),
            method: String(overrides.method ?? filters.method),
            range: String(overrides.range ?? filters.range),
            benchmark: String(overrides.benchmark ?? filters.benchmark),
            rf_pct: String(overrides.rf_pct ?? rfInput),
            inflow: String(overrides.inflow ?? inflowInput),
        };
        const acct = overrides.account_id !== undefined ? overrides.account_id : filters.account_id;
        if (acct) next.account_id = String(acct);

        router.get('/risk-rebalancing', next, { preserveState: true, preserveScroll: true });
    };

    const submitSimParams = (e: React.FormEvent) => {
        e.preventDefault();
        navigateFilters({ rf_pct: rfInput || '0', inflow: inflowInput || '0' });
    };

    const sumTargetEq = draftTargets.reduce((acc, row) => acc + (Number(row.target_weight_pct) || 0), 0);
    const cashBufferNum = Number(cashBufferInput) || 0;
    const totalAllocatedPct = sumTargetEq + cashBufferNum;
    const isOver100 = totalAllocatedPct > 100.01;

    const addInstrumentRow = () => {
        if (!addInstrumentId) return;
        const idNum = Number(addInstrumentId);
        if (draftTargets.some((d) => d.instrument_id === idNum)) return;
        const found = universeInstruments.find((u) => u.id === idNum);
        if (!found) return;
        setDraftTargets((prev) => [
            ...prev,
            {
                instrument_id: found.id,
                symbol: found.symbol,
                name: found.name,
                exchange_code: found.exchange_code,
                target_weight_pct: '0.00',
            },
        ]);
        setAddInstrumentId('');
    };

    const saveTargets = (e: React.FormEvent) => {
        e.preventDefault();
        if (isOver100) return;
        setSavingTargets(true);
        router.put(
            '/risk-rebalancing/targets',
            {
                cash_buffer_pct: Number(cashBufferInput) || 0,
                drift_band_pct: Number(driftBandInput) || 0,
                risk_free_rate_pct: rfInput !== '' ? Number(rfInput) : null,
                exclude_safety_flagged_buys: excludeSafetyBuys,
                targets: draftTargets.map((d) => ({
                    instrument_id: d.instrument_id,
                    target_weight_pct: Number(d.target_weight_pct) || 0,
                })),
            },
            {
                preserveScroll: true,
                onFinish: () => setSavingTargets(false),
            },
        );
    };

    const applyPreset = (preset: 'equal_weight' | 'current_weights' | 'clear') => {
        router.post(
            '/risk-rebalancing/presets',
            { preset, account_id: filters.account_id },
            { preserveScroll: true },
        );
    };

    const reportingCcy = filters.currency;

    const renderSignedMoney = (value: string | null) => {
        if (value === null) return <span className="text-muted-foreground">—</span>;
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

    const renderCorrCellClass = (valStr: string | null, isDiag: boolean) => {
        if (isDiag) return 'bg-muted/60 font-semibold';
        if (valStr === null) return 'text-muted-foreground';
        const v = Number(valStr);
        if (v >= 0.7) return 'bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)] font-medium';
        if (v >= 0.35) return 'bg-[var(--nadi-coral)]/20 text-foreground';
        if (v <= -0.2) return 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)] font-medium';
        return 'bg-[var(--nadi-ice)]/20 text-foreground';
    };

    const renderActionBadge = (action: string) => {
        const cls =
            action === 'buy'
                ? 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)]'
                : action === 'sell'
                  ? 'bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]'
                  : action === 'blocked_by_safety'
                    ? 'border border-[var(--nadi-coral)] bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]'
                    : 'bg-muted text-muted-foreground';

        return (
            <span className={cn('inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold uppercase', cls)}>
                {t(`risk.reb.action.${action}`)}
            </span>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('risk.title')} />

            <div className="space-y-5">
                {/* Header & Controls */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className="bg-[var(--nadi-lavender)]/30 text-foreground flex h-9 w-9 items-center justify-center rounded-md">
                            <Scale className="h-4 w-4" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-lg font-semibold tracking-tight">{t('risk.title')}</h1>
                                {(summary.is_demo || benchmarkMeta.is_demo) && <DemoBadge />}
                            </div>
                            <p className="text-muted-foreground text-xs">{t('risk.subtitle')}</p>
                            <DataFreshness asOf={filters.end_date ?? summary.as_of} isDemo={summary.is_demo} className="mt-1" />
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
                            <Link href="/portfolio-analytics">
                                <BarChart3 className="h-3.5 w-3.5" />
                                <span>{t('nav.portfolio_analytics')}</span>
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
                    </div>
                </div>

                {flash?.status && (
                    <div className="bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)] rounded-md px-3 py-2 text-xs font-medium">
                        {t('risk.status_saved')}: {flash.status}
                    </div>
                )}

                {errors?.targets && (
                    <div className="border-[var(--nadi-coral)] bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)] rounded-md border px-3 py-2 text-xs font-medium">
                        {errors.targets}
                    </div>
                )}

                {/* Filter Strip */}
                <div className="bg-card border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                    <div className="flex flex-wrap items-center gap-1">
                        {RANGES.map((r) => (
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

                        <Select value={filters.benchmark} onValueChange={(code) => navigateFilters({ benchmark: code })}>
                            <SelectTrigger className="ml-2 h-7 w-40 text-xs">
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

                    <form onSubmit={submitSimParams} className="flex flex-wrap items-center gap-2 text-xs">
                        <div className="flex items-center gap-1.5">
                            <Label htmlFor="rf_pct" className="text-muted-foreground text-xs">
                                {t('risk.filter.rf_rate')} (%)
                            </Label>
                            <Input
                                id="rf_pct"
                                type="number"
                                step="0.1"
                                min="0"
                                max="30"
                                value={rfInput}
                                onChange={(e) => setRfInput(e.target.value)}
                                className="h-7 w-20 text-xs"
                            />
                        </div>
                        <div className="flex items-center gap-1.5">
                            <Label htmlFor="inflow_sim" className="text-muted-foreground text-xs">
                                {t('risk.filter.cash_inflow')} ({reportingCcy})
                            </Label>
                            <Input
                                id="inflow_sim"
                                type="number"
                                min="0"
                                step="100000"
                                value={inflowInput}
                                onChange={(e) => setInflowInput(e.target.value)}
                                className="h-7 w-32 text-xs"
                            />
                        </div>
                        <Button type="submit" size="sm" variant="outline" className="h-7 px-2.5 text-xs">
                            {t('perf.apply_dates')}
                        </Button>
                    </form>
                </div>

                {/* Risk KPI Strip */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <MetricCard
                        label={t('risk.kpi.max_dd')}
                        value={<ChangeBadge changePct={risk.max_drawdown} />}
                        badge={
                            risk.max_drawdown_episode && (
                                <span className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 text-[10px] font-medium">
                                    {risk.max_drawdown_episode.status === 'recovered' ? t('risk.dd.recovered') : t('risk.dd.underwater')}
                                </span>
                            )
                        }
                        subtext={
                            risk.max_drawdown_episode
                                ? `${formatDate(risk.max_drawdown_episode.peak_date, locale)} → ${formatDate(risk.max_drawdown_episode.trough_date, locale)}`
                                : '—'
                        }
                    />

                    <MetricCard
                        label={t('risk.kpi.current_dd')}
                        value={<ChangeBadge changePct={risk.current_drawdown} />}
                        subtext={
                            risk.positive_days_pct !== null
                                ? `${t('risk.kpi.win_days')}: ${formatPct(risk.positive_days_pct, locale, false)}`
                                : '—'
                        }
                    />

                    <MetricCard
                        label={t('risk.kpi.ann_vol')}
                        value={
                            risk.annualized_volatility !== null ? (
                                <span className="num">{formatPct(risk.annualized_volatility, locale, false)}</span>
                            ) : (
                                <span className="text-muted-foreground text-base font-medium">—</span>
                            )
                        }
                        subtext={
                            risk.downside_deviation !== null
                                ? `${t('risk.kpi.downside_dev')}: ${formatPct(risk.downside_deviation, locale, false)}`
                                : t('perf.kpi.min_obs_note', { count: risk.observations_count, min: risk.min_observations_required })
                        }
                    />

                    <MetricCard
                        label={t('risk.kpi.sharpe')}
                        value={
                            risk.sharpe_ratio !== null ? (
                                <span className="num">{formatNumber(risk.sharpe_ratio, locale, 2, 2)}</span>
                            ) : (
                                <span className="text-muted-foreground text-base font-medium">—</span>
                            )
                        }
                        subtext={`Rf: ${formatPct(risk.risk_free_rate_annual, locale, false)}`}
                    />

                    <MetricCard
                        label={t('risk.kpi.sortino')}
                        value={
                            risk.sortino_ratio !== null ? (
                                <span className="num">{formatNumber(risk.sortino_ratio, locale, 2, 2)}</span>
                            ) : (
                                <span className="text-muted-foreground text-base font-medium">—</span>
                            )
                        }
                        subtext={
                            risk.calmar_ratio !== null
                                ? `Calmar: ${formatNumber(risk.calmar_ratio, locale, 2, 2)}`
                                : t('risk.kpi.calmar_na')
                        }
                    />

                    <MetricCard
                        label={`${t('risk.kpi.beta')} (${benchmarkMeta.code})`}
                        value={
                            risk.beta !== null ? (
                                <span className="num">{formatNumber(risk.beta, locale, 2, 2)}</span>
                            ) : (
                                <span className="text-muted-foreground text-base font-medium">—</span>
                            )
                        }
                        subtext={
                            risk.var_95_daily_pct !== null
                                ? `VaR95 1D: ${formatPct(risk.var_95_daily_pct, locale, true)}`
                                : t('perf.kpi.min_obs_note', { count: risk.observations_count, min: risk.min_observations_required })
                        }
                    />
                </div>

                {/* Module Section Tabs */}
                <div className="border-border flex flex-wrap gap-1 border-b pb-2">
                    {(
                        [
                            ['rebalance', t('risk.tab.rebalance')],
                            ['drawdown', t('risk.tab.drawdown')],
                            ['correlation', t('risk.tab.correlation')],
                        ] as const
                    ).map(([key, label]) => (
                        <Button
                            key={key}
                            type="button"
                            size="sm"
                            variant={activeTab === key ? 'secondary' : 'ghost'}
                            className="h-8 text-xs font-medium"
                            onClick={() => setActiveTab(key)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>

                {/* TAB 1: REBALANCING STUDIO */}
                {activeTab === 'rebalance' && (
                    <div className="space-y-4">
                        <div className="border-border bg-muted/30 text-muted-foreground flex items-start gap-2 rounded-md border px-3 py-2 text-xs">
                            <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            <span>{t('risk.reb.simulation_disclaimer')}</span>
                        </div>

                        {rebalance.is_default_preview && (
                            <div className="border-[var(--nadi-lavender)] bg-[var(--nadi-lavender)]/15 text-foreground flex items-start gap-2 rounded-md border px-3 py-2 text-xs">
                                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>{t('risk.reb.default_preview_note')}</span>
                            </div>
                        )}

                        {rebalance.buys_scaled_by_cash && (
                            <div className="border-[var(--nadi-coral)] bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)] flex items-start gap-2 rounded-md border px-3 py-2 text-xs">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>{t('risk.reb.buys_scaled_note')}</span>
                            </div>
                        )}

                        {!rebalance.has_data && draftTargets.length === 0 ? (
                            <EmptyState
                                icon={Scale}
                                title={t('risk.empty.no_targets')}
                                description={t('risk.empty.no_targets_desc')}
                                actionLabel={t('nav.portfolio')}
                                actionHref="/portfolio"
                            />
                        ) : (
                            <>
                                {/* Before / After Summary Strip */}
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <MetricCard
                                        label={t('risk.reb.summary.cash')}
                                        value={<Money value={rebalance.post_cash_rep} currency={reportingCcy} />}
                                        subtext={
                                            <span>
                                                {t('risk.reb.summary.cash_before')}: <Money value={rebalance.pre_cash_rep} currency={reportingCcy} /> ·{' '}
                                                {t('risk.reb.summary.cash_target')}: <Money value={rebalance.target_cash_rep} currency={reportingCcy} />
                                            </span>
                                        }
                                    />
                                    <MetricCard
                                        label={t('risk.reb.summary.turnover')}
                                        value={<span className="num">{formatPct(rebalance.turnover_pct, locale, false)}</span>}
                                        subtext={
                                            <span>
                                                Buy: <Money value={rebalance.total_buy_gross_rep} currency={reportingCcy} /> · Sell:{' '}
                                                <Money value={rebalance.total_sell_gross_rep} currency={reportingCcy} />
                                            </span>
                                        }
                                    />
                                    <MetricCard
                                        label={t('risk.reb.summary.est_fees')}
                                        value={<Money value={rebalance.total_estimated_fees_rep} currency={reportingCcy} />}
                                        subtext={t('risk.reb.summary.fees_sub')}
                                    />
                                    <MetricCard
                                        label={t('risk.reb.summary.hhi_shift')}
                                        value={
                                            <span className="num">
                                                {rebalance.pre_hhi ?? '—'} → {rebalance.post_hhi ?? '—'}
                                            </span>
                                        }
                                        subtext={
                                            rebalance.safety_blocked_count > 0
                                                ? t('risk.reb.safety_blocked_badge', { count: rebalance.safety_blocked_count })
                                                : t('risk.reb.summary.hhi_sub')
                                        }
                                    />
                                </div>

                                {/* Target Editor + Chart */}
                                <div className="grid gap-4 lg:grid-cols-12">
                                    <section className="bg-card border-border rounded-md border p-4 space-y-3 lg:col-span-5">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <h2 className="text-sm font-semibold">{t('risk.reb.target_editor')}</h2>
                                            <div className="flex flex-wrap gap-1">
                                                <Button type="button" size="sm" variant="outline" className="h-7 text-[11px]" onClick={() => applyPreset('equal_weight')}>
                                                    {t('risk.reb.preset.equal')}
                                                </Button>
                                                <Button type="button" size="sm" variant="outline" className="h-7 text-[11px]" onClick={() => applyPreset('current_weights')}>
                                                    {t('risk.reb.preset.current')}
                                                </Button>
                                                <Button type="button" size="sm" variant="ghost" className="h-7 text-[11px]" onClick={() => applyPreset('clear')}>
                                                    {t('risk.reb.preset.clear')}
                                                </Button>
                                            </div>
                                        </div>

                                        <form onSubmit={saveTargets} className="space-y-3">
                                            <div className="grid grid-cols-2 gap-2 text-xs">
                                                <div>
                                                    <Label className="text-[11px]">{t('risk.reb.cash_buffer_pct')}</Label>
                                                    <Input
                                                        type="number"
                                                        step="0.5"
                                                        min="0"
                                                        max="95"
                                                        value={cashBufferInput}
                                                        onChange={(e) => setCashBufferInput(e.target.value)}
                                                        className="mt-1 h-8 text-xs"
                                                    />
                                                </div>
                                                <div>
                                                    <Label className="text-[11px]">{t('risk.reb.drift_band_pct')}</Label>
                                                    <Input
                                                        type="number"
                                                        step="0.5"
                                                        min="0"
                                                        max="25"
                                                        value={driftBandInput}
                                                        onChange={(e) => setDriftBandInput(e.target.value)}
                                                        className="mt-1 h-8 text-xs"
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-2 text-xs">
                                                <Checkbox
                                                    id="safety-block-buys"
                                                    checked={excludeSafetyBuys}
                                                    onCheckedChange={(v) => setExcludeSafetyBuys(v === true)}
                                                />
                                                <Label htmlFor="safety-block-buys" className="flex items-center gap-1 text-xs">
                                                    <ShieldAlert className="h-3.5 w-3.5 text-[var(--nadi-loss-ink)]" />
                                                    <span>{t('risk.reb.safety_guard_toggle')}</span>
                                                </Label>
                                            </div>

                                            {/* Add Instrument Picker */}
                                            <div className="flex items-center gap-1.5">
                                                <Select value={addInstrumentId} onValueChange={setAddInstrumentId}>
                                                    <SelectTrigger className="h-8 flex-1 text-xs">
                                                        <SelectValue placeholder={t('risk.reb.add_stock_placeholder')} />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {universeInstruments
                                                            .filter((u) => !draftTargets.some((d) => d.instrument_id === u.id))
                                                            .map((u) => (
                                                                <SelectItem key={u.id} value={String(u.id)}>
                                                                    {u.symbol} · {u.exchange_code} ({u.currency})
                                                                </SelectItem>
                                                            ))}
                                                    </SelectContent>
                                                </Select>
                                                <Button type="button" size="sm" variant="outline" className="h-8 px-2.5 text-xs" onClick={addInstrumentRow}>
                                                    <Plus className="h-3.5 w-3.5" />
                                                </Button>
                                            </div>

                                            {/* Draft Target Rows */}
                                            <div className="divide-border max-h-60 divide-y overflow-y-auto rounded border">
                                                {draftTargets.map((row) => (
                                                    <div key={row.instrument_id} className="flex items-center justify-between gap-2 px-2.5 py-1.5 text-xs">
                                                        <div className="min-w-0 flex-1">
                                                            <span className="font-semibold">{row.symbol}</span>
                                                            <span className="text-muted-foreground ml-1.5 text-[11px]">{row.exchange_code}</span>
                                                        </div>
                                                        <div className="flex items-center gap-1.5">
                                                            <Input
                                                                type="number"
                                                                step="0.25"
                                                                min="0"
                                                                max="100"
                                                                value={row.target_weight_pct}
                                                                onChange={(e) => {
                                                                    const val = e.target.value;
                                                                    setDraftTargets((prev) =>
                                                                        prev.map((p) =>
                                                                            p.instrument_id === row.instrument_id
                                                                                ? { ...p, target_weight_pct: val }
                                                                                : p,
                                                                        ),
                                                                    );
                                                                }}
                                                                className="num h-7 w-20 text-right text-xs"
                                                            />
                                                            <span className="text-muted-foreground">%</span>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="ghost"
                                                                className="text-muted-foreground hover:text-destructive h-7 w-7 p-0"
                                                                onClick={() =>
                                                                    setDraftTargets((prev) =>
                                                                        prev.filter((p) => p.instrument_id !== row.instrument_id),
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>

                                            {/* Total Allocation Meter + Save */}
                                            <div className="flex items-center justify-between text-xs">
                                                <span className={cn('num font-medium', isOver100 ? 'text-[var(--nadi-loss-ink)]' : 'text-muted-foreground')}>
                                                    {t('risk.reb.allocated_total', {
                                                        equity: totalAllocatedPct > 0 ? sumTargetEq.toFixed(2) : '0.00',
                                                        cash: cashBufferNum.toFixed(2),
                                                        total: totalAllocatedPct.toFixed(2),
                                                    })}
                                                </span>
                                                <Button type="submit" size="sm" className="h-8 text-xs" disabled={savingTargets || isOver100}>
                                                    {t('risk.reb.save_targets')}
                                                </Button>
                                            </div>
                                        </form>
                                    </section>

                                    <section className="bg-card border-border rounded-md border p-4 space-y-3 lg:col-span-7">
                                        <h2 className="text-sm font-semibold">{t('risk.reb.chart_title')}</h2>
                                        <RebalanceWeightChart rows={rebalance.rows} />
                                    </section>
                                </div>

                                {/* Suggested Orders Table */}
                                <section className="bg-card border-border rounded-md border p-4 space-y-3">
                                    <h2 className="text-sm font-semibold">{t('risk.reb.orders_title')}</h2>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left text-xs">
                                            <thead className="border-border text-muted-foreground border-b">
                                                <tr>
                                                    <th className="py-2 pr-3 font-medium">{t('perf.col.item')}</th>
                                                    <th className="py-2 pr-3 text-right font-medium">{t('risk.reb.col_current_w')}</th>
                                                    <th className="py-2 pr-3 text-right font-medium">{t('risk.reb.col_target_w')}</th>
                                                    <th className="py-2 pr-3 text-right font-medium">{t('risk.reb.col_drift')}</th>
                                                    <th className="py-2 pr-3 font-medium">{t('risk.reb.col_action')}</th>
                                                    <th className="py-2 pr-3 text-right font-medium">{t('risk.reb.col_lots_shares')}</th>
                                                    <th className="py-2 pr-3 text-right font-medium">{t('risk.reb.col_est_value')}</th>
                                                    <th className="py-2 pr-3 text-right font-medium">{t('risk.reb.col_est_fee')}</th>
                                                    <th className="py-2 text-right font-medium">{t('risk.reb.col_post_w')}</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-border divide-y">
                                                {rebalance.rows.map((row) => (
                                                    <tr key={row.instrument_id} className="hover:bg-muted/30">
                                                        <td className="py-2 pr-3">
                                                            <div className="flex items-center gap-1.5">
                                                                <Link href={`/stocks/${row.exchange_code}/${row.symbol}`} className="font-semibold hover:underline">
                                                                    {row.symbol}
                                                                </Link>
                                                                <SafetyBadge category={row.safety_category} safeguard={row.safeguard_triggered} />
                                                            </div>
                                                            <div className="text-muted-foreground text-[11px]">
                                                                {row.ref_price_local
                                                                    ? `${formatPrice(row.ref_price_local, row.currency, locale)} ${row.currency} (1 lot = ${row.lot_size})`
                                                                    : row.name}
                                                            </div>
                                                        </td>
                                                        <td className="num py-2 pr-3 text-right">{row.current_weight_pct}%</td>
                                                        <td className="num py-2 pr-3 text-right">{row.target_weight_pct}%</td>
                                                        <td className="num py-2 pr-3 text-right">
                                                            <span
                                                                className={cn(
                                                                    Number(row.drift_pct) > 0
                                                                        ? 'text-[var(--nadi-loss-ink)]'
                                                                        : Number(row.drift_pct) < 0
                                                                          ? 'text-[var(--nadi-gain-ink)]'
                                                                          : 'text-muted-foreground',
                                                                )}
                                                            >
                                                                {Number(row.drift_pct) > 0 ? `+${row.drift_pct}%` : `${row.drift_pct}%`}
                                                            </span>
                                                        </td>
                                                        <td className="py-2 pr-3">{renderActionBadge(row.action)}</td>
                                                        <td className="num py-2 pr-3 text-right">
                                                            {row.suggested_lots > 0
                                                                ? `${row.suggested_lots} lot (${formatNumber(row.suggested_shares, locale, 0, 0)})`
                                                                : '—'}
                                                        </td>
                                                        <td className="num py-2 pr-3 text-right">
                                                            {Number(row.estimated_gross_rep) > 0 ? (
                                                                <div>
                                                                    <Money value={row.estimated_gross_rep} currency={reportingCcy} />
                                                                    {row.indicative_realized_pnl_rep !== null && (
                                                                        <div className="text-[10px]">
                                                                            Est. P&L: {renderSignedMoney(row.indicative_realized_pnl_rep)}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            ) : (
                                                                '—'
                                                            )}
                                                        </td>
                                                        <td className="num py-2 pr-3 text-right">
                                                            {Number(row.estimated_fee_rep) > 0 ? (
                                                                <Money value={row.estimated_fee_rep} currency={reportingCcy} />
                                                            ) : (
                                                                '—'
                                                            )}
                                                        </td>
                                                        <td className="num py-2 text-right font-medium">{row.post_weight_pct}%</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            </>
                        )}
                    </div>
                )}

                {/* TAB 2: DRAWDOWN & BENCHMARK RISK */}
                {activeTab === 'drawdown' && (
                    <div className="space-y-4">
                        <section className="bg-card border-border rounded-md border p-4 space-y-3">
                            <div>
                                <h2 className="text-sm font-semibold">{t('risk.dd.underwater_title')}</h2>
                                <p className="text-muted-foreground text-[11px]">{t('risk.dd.underwater_sub')}</p>
                            </div>
                            <UnderwaterDrawdownChart series={risk.underwater_series} />
                        </section>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <section className="bg-card border-border rounded-md border p-4 space-y-3">
                                <h2 className="text-sm font-semibold">{t('risk.dd.episodes_title')}</h2>
                                {risk.drawdown_episodes.length === 0 ? (
                                    <EmptyState title={t('risk.empty.no_drawdown')} />
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left text-xs">
                                            <thead className="border-border text-muted-foreground border-b">
                                                <tr>
                                                    <th className="py-2 pr-3 font-medium">{t('risk.dd.col_peak')}</th>
                                                    <th className="py-2 pr-3 font-medium">{t('risk.dd.col_trough')}</th>
                                                    <th className="py-2 pr-3 font-medium">{t('risk.dd.col_recovery')}</th>
                                                    <th className="py-2 pr-3 text-right font-medium">{t('risk.dd.col_depth')}</th>
                                                    <th className="py-2 text-right font-medium">{t('risk.dd.col_status')}</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-border divide-y">
                                                {risk.drawdown_episodes.map((ep, idx) => (
                                                    <tr key={`${ep.peak_date}-${idx}`}>
                                                        <td className="num py-2 pr-3">{formatDate(ep.peak_date, locale)}</td>
                                                        <td className="num py-2 pr-3">{formatDate(ep.trough_date, locale)}</td>
                                                        <td className="num py-2 pr-3">
                                                            {ep.recovery_date ? formatDate(ep.recovery_date, locale) : '—'}
                                                        </td>
                                                        <td className="num py-2 pr-3 text-right">
                                                            <ChangeBadge changePct={ep.depth_pct} />
                                                        </td>
                                                        <td className="py-2 text-right">
                                                            <span
                                                                className={cn(
                                                                    'rounded px-1.5 py-0.5 text-[10px] font-medium',
                                                                    ep.status === 'recovered'
                                                                        ? 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)]'
                                                                        : 'bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]',
                                                                )}
                                                            >
                                                                {t(`risk.dd.${ep.status}`)}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </section>

                            <section className="bg-card border-border rounded-md border p-4 space-y-3">
                                <h2 className="text-sm font-semibold">{t('risk.dd.tail_and_capture')}</h2>
                                <div className="divide-border divide-y text-xs">
                                    <div className="flex items-center justify-between py-2">
                                        <span className="text-muted-foreground">{t('risk.dd.var_95_pct')}</span>
                                        <ChangeBadge changePct={risk.var_95_daily_pct} />
                                    </div>
                                    <div className="flex items-center justify-between py-2">
                                        <span className="text-muted-foreground">{t('risk.dd.var_95_money')}</span>
                                        {risk.var_95_daily_rep !== null ? (
                                            <Money value={risk.var_95_daily_rep} currency={reportingCcy} />
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </div>
                                    <div className="flex items-center justify-between py-2">
                                        <span className="text-muted-foreground">{t('risk.dd.cvar_95')}</span>
                                        <ChangeBadge changePct={risk.cvar_95_daily_pct} />
                                    </div>
                                    <div className="flex items-center justify-between py-2">
                                        <span className="text-muted-foreground">
                                            {t('risk.dd.corr_and_r2')} ({benchmarkMeta.code})
                                        </span>
                                        <span className="num">
                                            {risk.benchmark_correlation !== null
                                                ? `${formatNumber(risk.benchmark_correlation, locale, 2, 2)} (R² ${formatNumber(risk.r_squared, locale, 2, 2)})`
                                                : '—'}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between py-2">
                                        <span className="text-muted-foreground">{t('risk.dd.upside_capture')}</span>
                                        <span className="num">
                                            {risk.upside_capture !== null ? formatPct(risk.upside_capture, locale, false) : '—'}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between py-2">
                                        <span className="text-muted-foreground">{t('risk.dd.downside_capture')}</span>
                                        <span className="num">
                                            {risk.downside_capture !== null ? formatPct(risk.downside_capture, locale, false) : '—'}
                                        </span>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                )}

                {/* TAB 3: CORRELATION MATRIX & CURRENCY SHOCK */}
                {activeTab === 'correlation' && (
                    <div className="space-y-4">
                        <section className="bg-card border-border rounded-md border p-4 space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h2 className="text-sm font-semibold">{t('risk.corr.title')}</h2>
                                    <p className="text-muted-foreground text-[11px]">
                                        {t('risk.corr.subtitle', { min: correlation.min_overlap_required })}
                                    </p>
                                </div>
                                {correlation.average_pairwise !== null && (
                                    <div className="text-muted-foreground flex flex-wrap gap-3 text-xs">
                                        <span>
                                            Avg ρ: <strong className="text-foreground num">{correlation.average_pairwise}</strong>
                                        </span>
                                        {correlation.highest_pair && (
                                            <span>
                                                Max: <strong className="text-foreground num">{correlation.highest_pair.pair} ({correlation.highest_pair.correlation})</strong>
                                            </span>
                                        )}
                                        {correlation.lowest_pair && (
                                            <span>
                                                Min: <strong className="text-foreground num">{correlation.lowest_pair.pair} ({correlation.lowest_pair.correlation})</strong>
                                            </span>
                                        )}
                                    </div>
                                )}
                            </div>

                            {!correlation.has_data ? (
                                <EmptyState title={t('risk.empty.no_corr')} />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full border-collapse text-center text-xs">
                                        <thead>
                                            <tr className="border-border text-muted-foreground border-b">
                                                <th className="py-2 pr-3 text-left font-medium">{t('perf.col.item')}</th>
                                                {correlation.symbols.map((s) => (
                                                    <th key={s.instrument_id} className="px-2 py-2 font-medium">
                                                        {s.symbol}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border divide-y">
                                            {correlation.rows.map((row) => (
                                                <tr key={row.instrument_id}>
                                                    <td className="py-2 pr-3 text-left font-semibold">{row.symbol}</td>
                                                    {row.cells.map((cell, idx) => {
                                                        const isDiag = cell.symbol === row.symbol;
                                                        return (
                                                            <td
                                                                key={`${row.symbol}-${idx}`}
                                                                className={cn(
                                                                    'num border-border/50 border px-2 py-1.5',
                                                                    renderCorrCellClass(cell.correlation, isDiag),
                                                                )}
                                                            >
                                                                {cell.correlation !== null
                                                                    ? formatNumber(cell.correlation, locale, 2, 2)
                                                                    : '—'}
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>

                        <section className="bg-card border-border rounded-md border p-4 space-y-3">
                            <div>
                                <h2 className="text-sm font-semibold">{t('risk.fx.title')}</h2>
                                <p className="text-muted-foreground text-[11px]">{t('risk.fx.subtitle', { currency: reportingCcy })}</p>
                            </div>
                            {currencyExposure.length === 0 ? (
                                <EmptyState title={t('risk.empty.no_targets')} />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-xs">
                                        <thead className="border-border text-muted-foreground border-b">
                                            <tr>
                                                <th className="py-2 pr-3 font-medium">{t('risk.fx.col_ccy')}</th>
                                                <th className="py-2 pr-3 text-right font-medium">{t('risk.fx.col_equity')}</th>
                                                <th className="py-2 pr-3 text-right font-medium">{t('risk.fx.col_cash')}</th>
                                                <th className="py-2 pr-3 text-right font-medium">{t('risk.fx.col_total')}</th>
                                                <th className="py-2 pr-3 text-right font-medium">{t('risk.fx.col_weight')}</th>
                                                <th className="py-2 pr-3 text-right font-medium">{t('risk.fx.col_shock_m5')}</th>
                                                <th className="py-2 pr-3 text-right font-medium">{t('risk.fx.col_shock_p5')}</th>
                                                <th className="py-2 text-right font-medium">{t('risk.fx.col_shock_m10')}</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border divide-y">
                                            {currencyExposure.map((row) => (
                                                <tr key={row.currency} className="hover:bg-muted/30">
                                                    <td className="py-2 pr-3 font-semibold">
                                                        {row.currency}
                                                        {row.is_reporting_currency && (
                                                            <span className="bg-muted text-muted-foreground ml-1.5 rounded px-1.5 py-0.5 text-[10px]">
                                                                Base
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="num py-2 pr-3 text-right">
                                                        <Money value={row.equity_rep} currency={reportingCcy} />
                                                    </td>
                                                    <td className="num py-2 pr-3 text-right">
                                                        <Money value={row.cash_rep} currency={reportingCcy} />
                                                    </td>
                                                    <td className="num py-2 pr-3 text-right font-medium">
                                                        <Money value={row.total_rep} currency={reportingCcy} />
                                                    </td>
                                                    <td className="num py-2 pr-3 text-right">{formatPct(row.weight_pct, locale, false)}</td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.shock_minus_5pct_rep)}</td>
                                                    <td className="num py-2 pr-3 text-right">{renderSignedMoney(row.shock_plus_5pct_rep)}</td>
                                                    <td className="num py-2 text-right">{renderSignedMoney(row.shock_minus_10pct_rep)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
