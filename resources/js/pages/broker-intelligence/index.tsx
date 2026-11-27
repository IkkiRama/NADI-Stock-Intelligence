import { BrokerFlowChart, type BrokerDailyTrendPoint } from '@/components/nadi/broker-flow-chart';
import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { MetricCard } from '@/components/nadi/metric-card';
import { Money } from '@/components/nadi/money';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatCompact, formatDateTime, formatNumber, formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Activity, Building2, Download, Lock, ShieldCheck, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';

interface BrokerRankRow {
    broker_code: string;
    broker_name: string;
    broker_category: string;
    buy_volume: number;
    sell_volume: number;
    net_volume: number;
    buy_value: string;
    sell_value: string;
    net_value: string;
    avg_buy_price: string | null;
    avg_sell_price: string | null;
}

interface StockFlowPayload {
    instrument: {
        id: number;
        exchange_code: string;
        symbol: string;
        name: string;
        currency: string;
        close: string | null;
        change_pct: string | null;
    };
    window: string;
    window_start: string;
    window_end: string;
    as_of: string;
    trading_days_count: number;
    currency: string;
    is_demo: boolean;
    source_name: string;
    summary: {
        regime: string;
        total_buy_value: string;
        total_sell_value: string;
        top3_buy_net: string;
        top3_sell_net_abs: string;
        top3_net_imbalance: string;
        cr3_buy_pct: string | null;
        cr3_sell_pct: string | null;
        cr5_buy_pct: string | null;
        cr5_sell_pct: string | null;
        foreign_net_value: string;
        local_inst_net_value: string;
        retail_net_value: string;
        smart_money_net_value: string;
        flow_ratio: string;
        active_brokers_count: number;
    };
    top_buyers: BrokerRankRow[];
    top_sellers: BrokerRankRow[];
    daily_trend: BrokerDailyTrendPoint[];
}

interface FlowMoverRow {
    symbol: string;
    exchange_code: string;
    name: string;
    currency: string;
    close: string | null;
    change_pct: string | null;
    regime: string;
    smart_money_net_value: string;
    foreign_net_value: string;
    top3_net_imbalance: string;
    cr3_buy_pct: string | null;
    cr3_sell_pct: string | null;
    as_of: string;
    is_demo: boolean;
}

interface DirectoryRow {
    id: number;
    code: string;
    name: string;
    exchange_member_code: string | null;
    country_code: string;
    default_currency: string;
    csv_adapter: string;
    broker_category: string;
    typical_buy_fee_pct: string | null;
    typical_sell_fee_pct: string | null;
    supports_live_sync: boolean;
    notes: string | null;
    connected_accounts: number;
}

interface PersonalAccountAnalyticsRow {
    account_id: number;
    account_name: string;
    account_label: string | null;
    broker_code: string;
    broker_name: string;
    member_code: string | null;
    broker_category: string;
    base_currency: string;
    cost_basis_method: string;
    trade_count: number;
    buy_count: number;
    sell_count: number;
    dividend_count: number;
    gross_traded_value_rep: string;
    broker_fees_rep: string;
    exchange_fees_rep: string;
    taxes_rep: string;
    total_charges_rep: string;
    effective_fee_pct: string | null;
    effective_fee_bps: string | null;
    manual_tx_count: number;
    csv_tx_count: number;
    last_reconciled_at: string | null;
    is_demo: boolean;
}

interface BrokerIntelligenceProps {
    filters: {
        window: string;
        symbol: string | null;
        exchange: string;
        category: string;
        currency: string;
    };
    windows: string[];
    categories: string[];
    currencies: string[];
    availableInstruments: Array<{
        id: number;
        exchange_code: string;
        symbol: string;
        name: string;
        currency: string;
        close: string | null;
        change_pct: string | null;
        latest_date: string;
        is_demo: boolean;
    }>;
    stockFlow: StockFlowPayload | null;
    marketFlowMovers: {
        top_accumulated: FlowMoverRow[];
        top_distributed: FlowMoverRow[];
    };
    directory: DirectoryRow[];
    personalAnalytics: {
        reporting_currency: string;
        summary: {
            total_accounts: number;
            total_trades: number;
            total_traded_value_rep: string;
            total_charges_rep: string;
            weighted_fee_pct: string | null;
            weighted_fee_bps: string | null;
            contains_demo: boolean;
        };
        accounts: PersonalAccountAnalyticsRow[];
    };
    marketDepthStatus: {
        enabled: boolean;
        licensed: boolean;
        reason_key: string;
    };
}

function regimePillClass(regime: string): string {
    switch (regime) {
        case 'strong_accumulation':
        case 'accumulation':
            return 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-300 border-emerald-500/30';
        case 'strong_distribution':
        case 'distribution':
            return 'bg-rose-500/12 text-rose-700 dark:text-rose-300 border-rose-500/30';
        default:
            return 'bg-muted text-muted-foreground border-border';
    }
}

export default function BrokerIntelligenceIndex({
    filters,
    windows,
    categories,
    currencies,
    availableInstruments,
    stockFlow,
    marketFlowMovers,
    directory,
    personalAnalytics,
    marketDepthStatus,
}: BrokerIntelligenceProps) {
    const { t, locale } = useT();
    const [activeTab, setActiveTab] = useState<'flow' | 'personal' | 'directory'>('flow');
    const [brokerSearch, setBrokerSearch] = useState('');
    const [localCategory, setLocalCategory] = useState(filters.category || 'all');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.broker_intelligence'), href: '/broker-intelligence' },
    ];

    const applyFilter = (patch: Partial<BrokerIntelligenceProps['filters']>) => {
        const next = {
            window: patch.window ?? filters.window,
            symbol: patch.symbol ?? filters.symbol ?? undefined,
            exchange: patch.exchange ?? filters.exchange,
            category: patch.category ?? filters.category,
            currency: patch.currency ?? filters.currency,
        };
        router.get('/broker-intelligence', next, { preserveScroll: true, preserveState: true });
    };

    const filteredDirectory = useMemo(() => {
        const q = brokerSearch.trim().toLowerCase();
        return directory.filter((b) => {
            if (localCategory !== 'all' && b.broker_category !== localCategory) {
                return false;
            }
            if (!q) {
                return true;
            }
            return (
                b.name.toLowerCase().includes(q) ||
                b.code.toLowerCase().includes(q) ||
                (b.exchange_member_code ?? '').toLowerCase().includes(q)
            );
        });
    }, [directory, brokerSearch, localCategory]);

    const repCcy = personalAnalytics.reporting_currency;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('broker_intel.title')} />

            <div className="space-y-5 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-semibold tracking-tight">{t('broker_intel.title')}</h1>
                            {(stockFlow?.is_demo || personalAnalytics.summary.contains_demo) && <DemoBadge />}
                        </div>
                        <p className="text-muted-foreground mt-0.5 text-xs">{t('broker_intel.subtitle')}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {stockFlow && (
                            <DataFreshness
                                asOf={stockFlow.as_of}
                                source={stockFlow.source_name}
                                isDemo={stockFlow.is_demo}
                            />
                        )}
                        {stockFlow && (
                            <Button asChild size="sm" variant="outline">
                                <a
                                    href={`/broker-intelligence/export-csv?symbol=${encodeURIComponent(stockFlow.instrument.symbol)}&window=${encodeURIComponent(filters.window)}`}
                                >
                                    <Download className="mr-1.5 h-3.5 w-3.5" />
                                    {t('broker_intel.action.export_csv')}
                                </a>
                            </Button>
                        )}
                    </div>
                </div>

                {/* Module Tabs */}
                <div className="border-border flex flex-wrap gap-1.5 border-b pb-2.5">
                    <Button
                        size="sm"
                        variant={activeTab === 'flow' ? 'default' : 'ghost'}
                        onClick={() => setActiveTab('flow')}
                    >
                        <Activity className="mr-1.5 h-3.5 w-3.5" />
                        {t('broker_intel.tab.flow')}
                    </Button>
                    <Button
                        size="sm"
                        variant={activeTab === 'personal' ? 'default' : 'ghost'}
                        onClick={() => setActiveTab('personal')}
                    >
                        <Wallet className="mr-1.5 h-3.5 w-3.5" />
                        {t('broker_intel.tab.personal')}
                    </Button>
                    <Button
                        size="sm"
                        variant={activeTab === 'directory' ? 'default' : 'ghost'}
                        onClick={() => setActiveTab('directory')}
                    >
                        <Building2 className="mr-1.5 h-3.5 w-3.5" />
                        {t('broker_intel.tab.directory')} ({directory.length})
                    </Button>
                </div>

                {/* TAB 1: EOD Broker Flow */}
                {activeTab === 'flow' && (
                    <div className="space-y-5">
                        {/* Filter Bar */}
                        <div className="bg-card border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                            <div className="flex flex-wrap items-center gap-3">
                                <div className="flex items-center gap-2">
                                    <span className="text-muted-foreground text-xs">{t('broker_intel.filter.symbol')}</span>
                                    <Select
                                        value={filters.symbol ?? ''}
                                        onValueChange={(val) => applyFilter({ symbol: val })}
                                    >
                                        <SelectTrigger className="h-8 w-[240px] text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {availableInstruments.map((inst) => (
                                                <SelectItem key={inst.id} value={inst.symbol}>
                                                    {inst.symbol} · {inst.name} ({inst.exchange_code})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="flex items-center gap-1">
                                    <span className="text-muted-foreground mr-1 text-xs">{t('broker_intel.filter.window')}</span>
                                    {windows.map((w) => (
                                        <Button
                                            key={w}
                                            size="sm"
                                            variant={filters.window === w ? 'default' : 'outline'}
                                            className="h-7 px-2.5 text-xs"
                                            onClick={() => applyFilter({ window: w })}
                                        >
                                            {w}
                                        </Button>
                                    ))}
                                </div>
                            </div>

                            {stockFlow && (
                                <div className="flex items-center gap-2 text-xs">
                                    <Link
                                        href={`/stocks/${stockFlow.instrument.exchange_code.toLowerCase()}/${stockFlow.instrument.symbol.toLowerCase()}`}
                                        className="text-primary font-medium hover:underline"
                                    >
                                        {stockFlow.instrument.exchange_code}:{stockFlow.instrument.symbol}
                                    </Link>
                                    {stockFlow.instrument.close && (
                                        <span className="font-mono">
                                            {formatPrice(stockFlow.instrument.close, stockFlow.currency, locale)}
                                        </span>
                                    )}
                                    {stockFlow.instrument.change_pct !== null && (
                                        <ChangeBadge changePct={stockFlow.instrument.change_pct} />
                                    )}
                                </div>
                            )}
                        </div>

                        {!stockFlow ? (
                            <EmptyState
                                icon={Activity}
                                title={t('broker_intel.empty_flow_title')}
                                description={t('broker_intel.empty_flow_desc')}
                            />
                        ) : (
                            <>
                                {/* KPI Strip */}
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <div className="bg-card border-border rounded-md border p-3.5">
                                        <p className="text-muted-foreground text-[11px] font-medium uppercase tracking-wide">
                                            {t('broker_intel.kpi.regime')} ({stockFlow.trading_days_count}d)
                                        </p>
                                        <div className="mt-2">
                                            <span
                                                className={cn(
                                                    'inline-flex rounded border px-2 py-0.5 text-xs font-semibold',
                                                    regimePillClass(stockFlow.summary.regime),
                                                )}
                                            >
                                                {t(`broker_intel.regime.${stockFlow.summary.regime}`)}
                                            </span>
                                        </div>
                                        <p className="text-muted-foreground mt-1.5 text-[11px]">
                                            {stockFlow.window_start} → {stockFlow.window_end}
                                        </p>
                                    </div>

                                    <MetricCard
                                        label={t('broker_intel.kpi.smart_money_net')}
                                        value={`${stockFlow.currency} ${formatCompact(Number(stockFlow.summary.smart_money_net_value), locale)}`}
                                        subtext={`${t('broker_intel.kpi.foreign_net')}: ${stockFlow.currency} ${formatCompact(Number(stockFlow.summary.foreign_net_value), locale)}`}
                                    />

                                    <MetricCard
                                        label={t('broker_intel.kpi.cr3_concentration')}
                                        value={`${formatPct(stockFlow.summary.cr3_buy_pct, locale)} / ${formatPct(stockFlow.summary.cr3_sell_pct, locale)}`}
                                        subtext={`CR5: ${formatPct(stockFlow.summary.cr5_buy_pct, locale)} / ${formatPct(stockFlow.summary.cr5_sell_pct, locale)}`}
                                    />

                                    <MetricCard
                                        label={t('broker_intel.kpi.total_turnover')}
                                        value={`${stockFlow.currency} ${formatCompact(Number(stockFlow.summary.total_buy_value), locale)}`}
                                        subtext={`${stockFlow.summary.active_brokers_count} active brokers`}
                                    />
                                </div>

                                {/* Daily Flow Chart */}
                                <section className="bg-card border-border rounded-md border p-4">
                                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <h2 className="text-sm font-semibold">{t('broker_intel.chart.title')}</h2>
                                            <p className="text-muted-foreground text-xs">{t('broker_intel.chart.subtitle')}</p>
                                        </div>
                                        {stockFlow.is_demo && <DemoBadge />}
                                    </div>
                                    <BrokerFlowChart data={stockFlow.daily_trend} currency={stockFlow.currency} />
                                </section>

                                {/* Top Buyers vs Top Sellers */}
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <section className="bg-card border-border rounded-md border p-4">
                                        <h2 className="mb-2.5 text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                            {t('broker_intel.top_buyers')}
                                        </h2>
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-xs">
                                                <thead>
                                                    <tr className="border-border text-muted-foreground border-b text-left">
                                                        <th className="py-1.5 pr-2">{t('broker_intel.col.broker')}</th>
                                                        <th className="py-1.5 pr-2">{t('broker_intel.col.category')}</th>
                                                        <th className="py-1.5 pr-2 text-right">{t('broker_intel.col.net_value')}</th>
                                                        <th className="py-1.5 pr-2 text-right">{t('broker_intel.col.net_volume')}</th>
                                                        <th className="py-1.5 text-right">{t('broker_intel.col.avg_price')}</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-border divide-y">
                                                    {stockFlow.top_buyers.map((b) => (
                                                        <tr key={b.broker_code}>
                                                            <td className="py-1.5 pr-2">
                                                                <span className="font-mono font-semibold">{b.broker_code}</span>
                                                                <span className="text-muted-foreground ml-1.5 hidden sm:inline">
                                                                    {b.broker_name}
                                                                </span>
                                                            </td>
                                                            <td className="py-1.5 pr-2">
                                                                <span className="bg-muted rounded px-1.5 py-0.5 text-[10px]">
                                                                    {t(`broker_intel.category.${b.broker_category}`)}
                                                                </span>
                                                            </td>
                                                            <td className="py-1.5 pr-2 text-right font-mono text-emerald-600 dark:text-emerald-400">
                                                                +{formatCompact(Number(b.net_value), locale)}
                                                            </td>
                                                            <td className="py-1.5 pr-2 text-right font-mono">
                                                                +{formatCompact(b.net_volume, locale)}
                                                            </td>
                                                            <td className="py-1.5 text-right font-mono">
                                                                {b.avg_buy_price ? formatPrice(b.avg_buy_price, stockFlow.currency, locale) : '—'}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>

                                    <section className="bg-card border-border rounded-md border p-4">
                                        <h2 className="mb-2.5 text-sm font-semibold text-rose-700 dark:text-rose-400">
                                            {t('broker_intel.top_sellers')}
                                        </h2>
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-xs">
                                                <thead>
                                                    <tr className="border-border text-muted-foreground border-b text-left">
                                                        <th className="py-1.5 pr-2">{t('broker_intel.col.broker')}</th>
                                                        <th className="py-1.5 pr-2">{t('broker_intel.col.category')}</th>
                                                        <th className="py-1.5 pr-2 text-right">{t('broker_intel.col.net_value')}</th>
                                                        <th className="py-1.5 pr-2 text-right">{t('broker_intel.col.net_volume')}</th>
                                                        <th className="py-1.5 text-right">{t('broker_intel.col.avg_price')}</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-border divide-y">
                                                    {stockFlow.top_sellers.map((s) => (
                                                        <tr key={s.broker_code}>
                                                            <td className="py-1.5 pr-2">
                                                                <span className="font-mono font-semibold">{s.broker_code}</span>
                                                                <span className="text-muted-foreground ml-1.5 hidden sm:inline">
                                                                    {s.broker_name}
                                                                </span>
                                                            </td>
                                                            <td className="py-1.5 pr-2">
                                                                <span className="bg-muted rounded px-1.5 py-0.5 text-[10px]">
                                                                    {t(`broker_intel.category.${s.broker_category}`)}
                                                                </span>
                                                            </td>
                                                            <td className="py-1.5 pr-2 text-right font-mono text-rose-600 dark:text-rose-400">
                                                                {formatCompact(Number(s.net_value), locale)}
                                                            </td>
                                                            <td className="py-1.5 pr-2 text-right font-mono">
                                                                {formatCompact(s.net_volume, locale)}
                                                            </td>
                                                            <td className="py-1.5 text-right font-mono">
                                                                {s.avg_sell_price ? formatPrice(s.avg_sell_price, stockFlow.currency, locale) : '—'}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                </div>

                                {/* Market-Wide Flow Movers */}
                                <section className="bg-card border-border rounded-md border p-4">
                                    <h2 className="mb-3 text-sm font-semibold">
                                        {t('broker_intel.movers.title')} ({filters.window})
                                    </h2>
                                    <div className="grid gap-4 lg:grid-cols-2">
                                        <div>
                                            <p className="mb-2 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                                {t('broker_intel.movers.accumulated')}
                                            </p>
                                            <div className="divide-border divide-y">
                                                {marketFlowMovers.top_accumulated.map((m) => (
                                                    <button
                                                        key={m.symbol}
                                                        type="button"
                                                        onClick={() => applyFilter({ symbol: m.symbol })}
                                                        className="hover:bg-muted/40 flex w-full items-center justify-between py-2 text-left text-xs transition-colors"
                                                    >
                                                        <div>
                                                            <span className="font-semibold">{m.symbol}</span>
                                                            <span className="text-muted-foreground ml-1.5">{m.name}</span>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-mono text-emerald-600 dark:text-emerald-400">
                                                                +{m.currency} {formatCompact(Number(m.smart_money_net_value), locale)}
                                                            </span>
                                                            <span
                                                                className={cn(
                                                                    'rounded border px-1.5 py-0.5 text-[10px]',
                                                                    regimePillClass(m.regime),
                                                                )}
                                                            >
                                                                {t(`broker_intel.regime.${m.regime}`)}
                                                            </span>
                                                        </div>
                                                    </button>
                                                ))}
                                            </div>
                                        </div>

                                        <div>
                                            <p className="mb-2 text-xs font-medium text-rose-700 dark:text-rose-400">
                                                {t('broker_intel.movers.distributed')}
                                            </p>
                                            <div className="divide-border divide-y">
                                                {marketFlowMovers.top_distributed.map((m) => (
                                                    <button
                                                        key={m.symbol}
                                                        type="button"
                                                        onClick={() => applyFilter({ symbol: m.symbol })}
                                                        className="hover:bg-muted/40 flex w-full items-center justify-between py-2 text-left text-xs transition-colors"
                                                    >
                                                        <div>
                                                            <span className="font-semibold">{m.symbol}</span>
                                                            <span className="text-muted-foreground ml-1.5">{m.name}</span>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-mono text-rose-600 dark:text-rose-400">
                                                                {m.currency} {formatCompact(Number(m.smart_money_net_value), locale)}
                                                            </span>
                                                            <span
                                                                className={cn(
                                                                    'rounded border px-1.5 py-0.5 text-[10px]',
                                                                    regimePillClass(m.regime),
                                                                )}
                                                            >
                                                                {t(`broker_intel.regime.${m.regime}`)}
                                                            </span>
                                                        </div>
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </>
                        )}

                        {/* Licensed Market Depth Honest Disclosure */}
                        <section className="bg-muted/40 border-border flex items-start gap-3 rounded-md border p-3.5 text-xs">
                            <Lock className="text-muted-foreground mt-0.5 h-4 w-4 shrink-0" />
                            <div>
                                <div className="flex items-center gap-2 font-semibold">
                                    <span>{t('broker_intel.depth.title')}</span>
                                    <span className="bg-background border-border rounded border px-1.5 py-0.5 text-[10px]">
                                        {marketDepthStatus.licensed ? 'Licensed' : t('broker_intel.depth.badge')}
                                    </span>
                                </div>
                                <p className="text-muted-foreground mt-0.5 leading-relaxed">
                                    {t(marketDepthStatus.reason_key)}
                                </p>
                            </div>
                        </section>
                    </div>
                )}

                {/* TAB 2: Personal Brokerage Cost & Execution Analytics */}
                {activeTab === 'personal' && (
                    <div className="space-y-5">
                        <div className="bg-card border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                            <div className="flex items-center gap-2">
                                <span className="text-muted-foreground text-xs">{t('broker_intel.filter.currency')}</span>
                                <Select
                                    value={filters.currency}
                                    onValueChange={(val) => applyFilter({ currency: val })}
                                >
                                    <SelectTrigger className="h-8 w-[120px] text-xs">
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
                            <Button asChild size="sm" variant="outline">
                                <Link href="/portfolio">{t('broker_intel.personal.go_portfolio')}</Link>
                            </Button>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <MetricCard
                                label={t('broker_intel.personal.accounts')}
                                value={String(personalAnalytics.summary.total_accounts)}
                            />
                            <MetricCard
                                label={t('broker_intel.personal.trades')}
                                value={formatNumber(personalAnalytics.summary.total_trades, locale, 0, 0)}
                            />
                            <MetricCard
                                label={t('broker_intel.personal.traded_value')}
                                value={`${repCcy} ${formatCompact(Number(personalAnalytics.summary.total_traded_value_rep), locale)}`}
                            />
                            <MetricCard
                                label={t('broker_intel.personal.fee_drag')}
                                value={
                                    personalAnalytics.summary.weighted_fee_bps !== null
                                        ? `${formatNumber(personalAnalytics.summary.weighted_fee_bps, locale, 1, 1)} bps`
                                        : '—'
                                }
                                subtext={
                                    personalAnalytics.summary.weighted_fee_pct !== null
                                        ? formatPct(personalAnalytics.summary.weighted_fee_pct, locale)
                                        : undefined
                                }
                            />
                        </div>

                        {personalAnalytics.accounts.length === 0 ? (
                            <EmptyState
                                icon={Wallet}
                                title={t('broker_intel.personal.empty_title')}
                                description={t('broker_intel.personal.empty_desc')}
                                actionLabel={t('broker_intel.personal.go_portfolio')}
                                actionHref="/portfolio"
                            />
                        ) : (
                            <section className="bg-card border-border rounded-md border p-4">
                                <h2 className="mb-3 text-sm font-semibold">{t('broker_intel.personal.table_title')}</h2>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="border-border text-muted-foreground border-b text-left">
                                                <th className="py-2 pr-3">{t('broker_intel.col.account')}</th>
                                                <th className="py-2 pr-3">{t('broker_intel.col.trades_split')}</th>
                                                <th className="py-2 pr-3 text-right">{t('broker_intel.personal.traded_value')}</th>
                                                <th className="py-2 pr-3 text-right">{t('broker_intel.col.broker_fee')}</th>
                                                <th className="py-2 pr-3 text-right">{t('broker_intel.col.exchange_tax')}</th>
                                                <th className="py-2 pr-3 text-right">{t('broker_intel.col.total_charges')}</th>
                                                <th className="py-2 pr-3 text-right">{t('broker_intel.col.fee_bps')}</th>
                                                <th className="py-2">{t('broker_intel.col.import_mix')}</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border divide-y">
                                            {personalAnalytics.accounts.map((a) => (
                                                <tr key={a.account_id}>
                                                    <td className="py-2 pr-3">
                                                        <div className="flex items-center gap-1.5 font-medium">
                                                            <span>{a.account_name}</span>
                                                            {a.is_demo && <DemoBadge />}
                                                        </div>
                                                        <div className="text-muted-foreground text-[11px]">
                                                            {a.broker_name} ({a.base_currency} · {a.cost_basis_method})
                                                        </div>
                                                    </td>
                                                    <td className="py-2 pr-3 font-mono">
                                                        {a.trade_count} ({a.buy_count}B / {a.sell_count}S)
                                                    </td>
                                                    <td className="py-2 pr-3 text-right font-mono">
                                                        <Money value={a.gross_traded_value_rep} currency={repCcy} mode="money" />
                                                    </td>
                                                    <td className="py-2 pr-3 text-right font-mono">
                                                        <Money value={a.broker_fees_rep} currency={repCcy} mode="money" />
                                                    </td>
                                                    <td className="py-2 pr-3 text-right font-mono">
                                                        <Money value={String(Number(a.exchange_fees_rep) + Number(a.taxes_rep))} currency={repCcy} mode="money" />
                                                    </td>
                                                    <td className="py-2 pr-3 text-right font-mono font-semibold">
                                                        <Money value={a.total_charges_rep} currency={repCcy} mode="money" />
                                                    </td>
                                                    <td className="py-2 pr-3 text-right font-mono">
                                                        {a.effective_fee_bps !== null
                                                            ? `${formatNumber(a.effective_fee_bps, locale, 1, 1)} bps`
                                                            : '—'}
                                                    </td>
                                                    <td className="py-2 text-[11px]">
                                                        <span>
                                                            M:{a.manual_tx_count} / CSV:{a.csv_tx_count}
                                                        </span>
                                                        {a.last_reconciled_at && (
                                                            <div className="text-muted-foreground">
                                                                {formatDateTime(a.last_reconciled_at, locale)}
                                                            </div>
                                                        )}
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

                {/* TAB 3: Official Broker Directory */}
                {activeTab === 'directory' && (
                    <div className="space-y-4">
                        <div className="bg-card border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                            <div className="flex flex-wrap items-center gap-1.5">
                                {categories.map((c) => (
                                    <Button
                                        key={c}
                                        size="sm"
                                        variant={localCategory === c ? 'default' : 'outline'}
                                        className="h-7 px-2.5 text-xs"
                                        onClick={() => setLocalCategory(c)}
                                    >
                                        {t(`broker_intel.category.${c}`)}
                                    </Button>
                                ))}
                            </div>
                            <Input
                                value={brokerSearch}
                                onChange={(e) => setBrokerSearch(e.target.value)}
                                placeholder={t('broker_intel.filter.search_broker')}
                                className="h-8 w-full text-xs sm:w-[240px]"
                            />
                        </div>

                        <section className="bg-card border-border rounded-md border p-4">
                            <div className="mb-3">
                                <h2 className="text-sm font-semibold">{t('broker_intel.dir.title')}</h2>
                                <p className="text-muted-foreground text-xs">{t('broker_intel.dir.subtitle')}</p>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="border-border text-muted-foreground border-b text-left">
                                            <th className="py-2 pr-3">{t('broker_intel.dir.col_code')}</th>
                                            <th className="py-2 pr-3">{t('broker_intel.dir.col_member')}</th>
                                            <th className="py-2 pr-3">{t('broker_intel.col.category')}</th>
                                            <th className="py-2 pr-3">{t('broker_intel.dir.col_country')}</th>
                                            <th className="py-2 pr-3">{t('broker_intel.dir.col_fee_ref')}</th>
                                            <th className="py-2 pr-3">{t('broker_intel.dir.col_adapter')}</th>
                                            <th className="py-2 pr-3">{t('broker_intel.dir.col_live_sync')}</th>
                                            <th className="py-2 text-right">{t('broker_intel.dir.col_connected')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {filteredDirectory.map((b) => (
                                            <tr key={b.id}>
                                                <td className="py-2 pr-3">
                                                    <div className="font-semibold">{b.name}</div>
                                                    <div className="text-muted-foreground font-mono text-[11px]">{b.code}</div>
                                                </td>
                                                <td className="py-2 pr-3 font-mono font-semibold">
                                                    {b.exchange_member_code ?? '—'}
                                                </td>
                                                <td className="py-2 pr-3">
                                                    <span className="bg-muted rounded px-1.5 py-0.5 text-[10px]">
                                                        {t(`broker_intel.category.${b.broker_category}`)}
                                                    </span>
                                                </td>
                                                <td className="py-2 pr-3 font-mono">
                                                    {b.country_code} · {b.default_currency}
                                                </td>
                                                <td className="py-2 pr-3 font-mono">
                                                    {b.typical_buy_fee_pct && b.typical_sell_fee_pct
                                                        ? `${formatPct(b.typical_buy_fee_pct, locale)} / ${formatPct(b.typical_sell_fee_pct, locale)}`
                                                        : '—'}
                                                </td>
                                                <td className="py-2 pr-3 font-mono text-[11px]">{b.csv_adapter}</td>
                                                <td className="py-2 pr-3">
                                                    <span className="text-muted-foreground inline-flex items-center gap-1 text-[11px]">
                                                        <ShieldCheck className="h-3.5 w-3.5" />
                                                        {b.supports_live_sync ? 'API' : t('broker_intel.dir.manual_only')}
                                                    </span>
                                                </td>
                                                <td className="py-2 text-right font-mono">{b.connected_accounts}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                )}

                <p className="text-muted-foreground border-border border-t pt-3 text-[11px]">
                    {t('broker_intel.disclaimer')}
                </p>
            </div>
        </AppLayout>
    );
}
