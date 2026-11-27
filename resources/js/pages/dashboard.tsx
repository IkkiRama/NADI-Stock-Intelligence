import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { MarketStatusBadge } from '@/components/nadi/market-status-badge';
import { ModuleEmptyState } from '@/components/nadi/module-empty-state';
import { Money } from '@/components/nadi/money';
import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Sparkline } from '@/components/nadi/sparkline';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatNumber, formatPct, formatPrice, formatTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    type BreadcrumbItem,
    type DashboardWidgetConfig,
    type ExchangeClockItem,
    type IndexCardItem,
    type SharedData,
} from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Compass, SlidersHorizontal } from 'lucide-react';
import { useEffect, useState } from 'react';

interface MoverItem {
    id: number;
    symbol: string;
    name: string;
    exchange_code: string;
    currency: string;
    sector: string | null;
    close: string;
    change: string | null;
    change_pct: string | null;
    volume: number;
    as_of: string;
    is_demo: boolean;
}

interface DashboardProps {
    indexCards: IndexCardItem[];
    clocks: ExchangeClockItem[];
    breadth: {
        advancers: number;
        decliners: number;
        unchanged: number;
        total: number;
        as_of: string | null;
        is_demo: boolean;
    };
    topMovers: {
        gainers: MoverItem[];
        losers: MoverItem[];
    };
    sectorPerformance: Array<{
        sector: string;
        avg_change_pct: string;
        count: number;
    }>;
    watchlistItems: Array<{
        id: number;
        symbol: string;
        name: string;
        exchange_code: string;
        currency: string;
        close: string | null;
        change: string | null;
        change_pct: string | null;
        as_of: string | null;
        is_demo: boolean;
    }>;
    safetyWarnings?: Array<{
        id: number;
        symbol: string;
        name: string;
        exchange_code: string;
        risk_score: string | null;
        risk_category: string;
        safeguard_triggered: boolean;
        rule_count: number;
        is_demo: boolean;
    }>;
    recentSignals?: Array<{
        id: number;
        symbol: string;
        name: string;
        exchange_code: string;
        currency: string;
        stance: string;
        entry_low: string | null;
        entry_high: string | null;
        target_1: string | null;
        net_rr: string | null;
        is_demo: boolean;
    }>;
    upcomingEvents?: Array<{
        id: number;
        symbol: string;
        exchange_code: string;
        action_type: string;
        ex_date: string;
        declared_currency: string | null;
        cash_amount: string | null;
        is_demo: boolean;
    }>;
    unreadAlertCount?: number;
    portfolioSummary?: {
        reporting_currency: string;
        total_equity: string;
        market_value: string;
        cash_balance: string;
        net_contributions: string;
        unrealized_pnl: string;
        unrealized_pnl_pct: string | null;
        realized_pnl: string;
        holdings_count: number;
        accounts_count: number;
        is_demo: boolean;
    };
    exchanges: Array<{ code: string; name: string; country_code: string }>;
    selectedExchange: string;
    dashboardLayout: DashboardWidgetConfig[];
}

export default function Dashboard({
    indexCards,
    clocks,
    breadth,
    topMovers,
    sectorPerformance,
    watchlistItems,
    portfolioSummary,
    safetyWarnings = [],
    recentSignals = [],
    upcomingEvents = [],
    exchanges,
    selectedExchange,
    dashboardLayout,
}: DashboardProps) {
    const { t, locale } = useT();
    const { featureFlags } = usePage<SharedData>().props;

    const [customizeOpen, setCustomizeOpen] = useState(false);
    const [layoutDraft, setLayoutDraft] = useState<DashboardWidgetConfig[]>(dashboardLayout);
    const [reloading, setReloading] = useState(false);

    useEffect(() => {
        setLayoutDraft(dashboardLayout);
    }, [dashboardLayout]);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.overview'), href: '/dashboard' }];

    const handleExchangeChange = (value: string) => {
        setReloading(true);
        router.get(
            '/dashboard',
            { exchange: value },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['breadth', 'topMovers', 'sectorPerformance', 'selectedExchange'],
                onFinish: () => setReloading(false),
            },
        );
    };

    const moveWidget = (index: number, delta: number) => {
        const target = index + delta;
        if (target < 0 || target >= layoutDraft.length) {
            return;
        }
        const next = [...layoutDraft];
        const [item] = next.splice(index, 1);
        next.splice(target, 0, item);
        setLayoutDraft(next);
    };

    const toggleWidget = (id: string, checked: boolean) => {
        setLayoutDraft((prev) => prev.map((w) => (w.id === id ? { ...w, visible: checked } : w)));
    };

    const saveLayout = () => {
        router.patch(
            '/dashboard/layout',
            { widgets: layoutDraft },
            {
                preserveScroll: true,
                onSuccess: () => setCustomizeOpen(false),
            },
        );
    };

    const maxSectorAbs = Math.max(
        0.005,
        ...sectorPerformance.map((s) => Math.abs(Number(s.avg_change_pct) || 0)),
    );

    const renderWidget = (id: string) => {
        switch (id) {
            case 'market_indices':
                return (
                    <section key={id} className="space-y-3">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold">{t('dashboard.widget.market_indices')}</h2>
                            <Link href="/markets" className="text-muted-foreground hover:text-foreground text-xs underline-offset-4 hover:underline">
                                {t('app.view_all')}
                            </Link>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {indexCards.map((idx) => (
                                <Link
                                    key={idx.code}
                                    href={`/markets/indices/${idx.code}`}
                                    className="bg-card border-border hover:border-foreground/30 flex flex-col justify-between rounded-md border p-[var(--nadi-card-p)] transition-colors"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-1.5">
                                                <span className="text-xs font-semibold">{idx.code}</span>
                                                <span className="text-muted-foreground text-[10px] font-medium">{idx.country_code}</span>
                                                {idx.is_demo && <DemoBadge />}
                                            </div>
                                            <p className="text-muted-foreground truncate text-[11px]">{idx.name}</p>
                                        </div>
                                        <Sparkline
                                            values={idx.sparkline}
                                            positive={idx.change_pct !== null ? Number(idx.change_pct) >= 0 : null}
                                            width={72}
                                            height={26}
                                        />
                                    </div>
                                    <div className="mt-3 flex items-baseline justify-between gap-2">
                                        <span className="num text-base font-semibold">
                                            {formatPrice(idx.close, idx.currency, locale)}
                                        </span>
                                        <ChangeBadge changePct={idx.change_pct} pill />
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </section>
                );

            case 'market_clocks':
                return (
                    <section key={id} className="bg-card border-border rounded-md border p-[var(--nadi-card-p)]">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-semibold">{t('dashboard.widget.market_clocks')}</h2>
                        </div>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            {clocks.map((item) => (
                                <div key={item.code} className="border-border/80 flex flex-col justify-between rounded border p-2.5">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-xs font-semibold">
                                            {item.code} <span className="text-muted-foreground font-normal">({item.country_code})</span>
                                        </span>
                                        <MarketStatusBadge status={item.status} />
                                    </div>
                                    <div className="text-muted-foreground mt-2 flex items-center justify-between text-[11px]">
                                        <span className="num font-medium">
                                            {formatTime(item.status.local_time, locale, item.status.timezone)}
                                        </span>
                                        {!item.status.holidays_loaded && (
                                            <span className="text-[10px]">{t('dashboard.holidays_not_loaded')}</span>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                );

            case 'breadth_movers':
                return (
                    <section key={id} className="bg-card border-border rounded-md border p-[var(--nadi-card-p)]">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 className="text-sm font-semibold">{t('dashboard.widget.breadth_movers')}</h2>
                                <DataFreshness asOf={breadth.as_of} isDemo={breadth.is_demo} className="mt-0.5" />
                            </div>
                            <Select value={selectedExchange} onValueChange={handleExchangeChange}>
                                <SelectTrigger className="h-8 w-36 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ALL">{t('app.all')}</SelectItem>
                                    {exchanges.map((ex) => (
                                        <SelectItem key={ex.code} value={ex.code}>
                                            {ex.code}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {reloading ? (
                            <div className="space-y-2">
                                <Skeleton className="h-8 w-full" />
                                <Skeleton className="h-32 w-full" />
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div className="border-border grid grid-cols-3 divide-x rounded border text-center">
                                    <div className="p-2">
                                        <div className="text-muted-foreground text-[11px]">{t('dashboard.breadth.advancers')}</div>
                                        <div className="num text-sm font-semibold text-[var(--nadi-gain-ink)]">{breadth.advancers}</div>
                                    </div>
                                    <div className="p-2">
                                        <div className="text-muted-foreground text-[11px]">{t('dashboard.breadth.decliners')}</div>
                                        <div className="num text-sm font-semibold text-[var(--nadi-loss-ink)]">{breadth.decliners}</div>
                                    </div>
                                    <div className="p-2">
                                        <div className="text-muted-foreground text-[11px]">{t('dashboard.breadth.unchanged')}</div>
                                        <div className="num text-sm font-semibold">{breadth.unchanged}</div>
                                    </div>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <h3 className="text-muted-foreground mb-2 text-xs font-semibold">{t('dashboard.movers.gainers')}</h3>
                                        <div className="divide-border divide-y">
                                            {topMovers.gainers.map((m) => (
                                                <Link
                                                    key={m.id}
                                                    href={`/stocks/${m.exchange_code}/${m.symbol}`}
                                                    className="hover:bg-muted/40 flex items-center justify-between py-2 text-xs transition-colors"
                                                >
                                                    <div className="min-w-0 pr-2">
                                                        <span className="font-semibold">{m.symbol}</span>{' '}
                                                        <span className="text-muted-foreground text-[11px]">{m.exchange_code}</span>
                                                        <p className="text-muted-foreground truncate text-[11px]">{m.name}</p>
                                                    </div>
                                                    <div className="shrink-0 text-right">
                                                        <div className="num font-medium">
                                                            <Money value={m.close} currency={m.currency} />
                                                        </div>
                                                        <ChangeBadge changePct={m.change_pct} />
                                                    </div>
                                                </Link>
                                            ))}
                                        </div>
                                    </div>

                                    <div>
                                        <h3 className="text-muted-foreground mb-2 text-xs font-semibold">{t('dashboard.movers.losers')}</h3>
                                        <div className="divide-border divide-y">
                                            {topMovers.losers.map((m) => (
                                                <Link
                                                    key={m.id}
                                                    href={`/stocks/${m.exchange_code}/${m.symbol}`}
                                                    className="hover:bg-muted/40 flex items-center justify-between py-2 text-xs transition-colors"
                                                >
                                                    <div className="min-w-0 pr-2">
                                                        <span className="font-semibold">{m.symbol}</span>{' '}
                                                        <span className="text-muted-foreground text-[11px]">{m.exchange_code}</span>
                                                        <p className="text-muted-foreground truncate text-[11px]">{m.name}</p>
                                                    </div>
                                                    <div className="shrink-0 text-right">
                                                        <div className="num font-medium">
                                                            <Money value={m.close} currency={m.currency} />
                                                        </div>
                                                        <ChangeBadge changePct={m.change_pct} />
                                                    </div>
                                                </Link>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}
                    </section>
                );

            case 'sector_performance':
                return (
                    <section key={id} className="bg-card border-border rounded-md border p-[var(--nadi-card-p)]">
                        <h2 className="mb-3 text-sm font-semibold">{t('dashboard.widget.sector_performance')} ({selectedExchange})</h2>
                        {sectorPerformance.length === 0 ? (
                            <p className="text-muted-foreground text-xs">{t('app.unavailable')}</p>
                        ) : (
                            <div className="space-y-2.5">
                                {sectorPerformance.map((sec) => {
                                    const num = Number(sec.avg_change_pct) || 0;
                                    const pctWidth = Math.min(100, (Math.abs(num) / maxSectorAbs) * 100);
                                    const isGain = num >= 0;

                                    return (
                                        <div key={sec.sector} className="space-y-1">
                                            <div className="flex items-center justify-between text-xs">
                                                <span className="font-medium">
                                                    {sec.sector} <span className="text-muted-foreground text-[11px]">({sec.count})</span>
                                                </span>
                                                <span className={cn('num font-medium', isGain ? 'text-[var(--nadi-gain-ink)]' : 'text-[var(--nadi-loss-ink)]')}>
                                                    {formatPct(sec.avg_change_pct, locale, true)}
                                                </span>
                                            </div>
                                            <div className="bg-muted h-2 w-full overflow-hidden rounded">
                                                <div
                                                    className={cn('h-full rounded', isGain ? 'bg-[var(--nadi-sage)]' : 'bg-[var(--nadi-coral)]')}
                                                    style={{ width: `${pctWidth}%` }}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </section>
                );

            case 'watchlist_movements':
                return (
                    <section key={id} className="bg-card border-border rounded-md border p-[var(--nadi-card-p)]">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-semibold">{t('dashboard.widget.watchlist_movements')}</h2>
                            <Link href="/stocks" className="text-muted-foreground hover:text-foreground text-xs underline-offset-4 hover:underline">
                                {t('dashboard.watchlist.cta')}
                            </Link>
                        </div>
                        {watchlistItems.length === 0 ? (
                            <EmptyState
                                icon={Compass}
                                title={t('dashboard.watchlist.empty_title')}
                                description={t('dashboard.watchlist.empty_desc')}
                                actionLabel={t('dashboard.watchlist.cta')}
                                actionHref="/stocks"
                            />
                        ) : (
                            <div className="divide-border divide-y">
                                {watchlistItems.map((item) => (
                                    <Link
                                        key={item.id}
                                        href={`/stocks/${item.exchange_code}/${item.symbol}`}
                                        className="hover:bg-muted/40 flex items-center justify-between py-2 text-xs transition-colors"
                                    >
                                        <div>
                                            <span className="font-semibold">{item.symbol}</span>{' '}
                                            <span className="text-muted-foreground">{item.exchange_code}</span>
                                            <p className="text-muted-foreground text-[11px]">{item.name}</p>
                                        </div>
                                        <div className="text-right">
                                            <div className="num font-medium">
                                                <Money value={item.close} currency={item.currency} />
                                            </div>
                                            <ChangeBadge changePct={item.change_pct} />
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>
                );

            case 'portfolio_summary':
                return (
                    <section key={id} className="bg-card border-border rounded-md border p-[var(--nadi-card-p)]">
                        <div className="mb-3 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <h2 className="text-sm font-semibold">{t('dashboard.widget.portfolio_summary')}</h2>
                                {portfolioSummary?.is_demo && <DemoBadge />}
                            </div>
                            <Link href="/portfolio" className="text-muted-foreground hover:text-foreground text-xs underline-offset-4 hover:underline">
                                {t('app.view_all')}
                            </Link>
                        </div>
                        {!portfolioSummary || portfolioSummary.accounts_count === 0 ? (
                            <EmptyState
                                title={t('heatmap.empty_portfolio')}
                                actionLabel={t('nav.portfolio')}
                                actionHref="/portfolio"
                            />
                        ) : (
                            <div className="grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <span className="text-muted-foreground block text-[11px]">{t('portfolio.kpi.total_equity')}</span>
                                    <div className="num text-base font-bold">
                                        <Money value={portfolioSummary.total_equity} currency={portfolioSummary.reporting_currency} />
                                    </div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground block text-[11px]">{t('portfolio.kpi.unrealized')}</span>
                                    <div>
                                        <ChangeBadge
                                            change={portfolioSummary.unrealized_pnl}
                                            changePct={portfolioSummary.unrealized_pnl_pct}
                                            currency={portfolioSummary.reporting_currency}
                                            showValue
                                        />
                                    </div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground block text-[11px]">{t('portfolio.kpi.cash_balance')}</span>
                                    <span className="num font-medium">
                                        <Money value={portfolioSummary.cash_balance} currency={portfolioSummary.reporting_currency} />
                                    </span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground block text-[11px]">Akun / Posisi</span>
                                    <span className="num font-medium">
                                        {portfolioSummary.accounts_count} akun · {portfolioSummary.holdings_count} saham
                                    </span>
                                </div>
                            </div>
                        )}
                    </section>
                );

            case 'stock_safety_warnings':
                return (
                    <section key={id} className="bg-card border-border rounded-md border p-[var(--nadi-card-p)]">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-semibold">{t('dashboard.widget.stock_safety_warnings')}</h2>
                            <Link href="/stock-safety" className="text-muted-foreground hover:text-foreground text-xs underline-offset-4 hover:underline">
                                {t('app.view_all')}
                            </Link>
                        </div>
                        {safetyWarnings.length === 0 ? (
                            <p className="text-muted-foreground text-xs">{t('safety.no_rules')}</p>
                        ) : (
                            <div className="divide-border divide-y">
                                {safetyWarnings.map((w) => (
                                    <Link
                                        key={w.id}
                                        href={`/stocks/${w.exchange_code}/${w.symbol}`}
                                        className="hover:bg-muted/40 flex items-center justify-between py-2 text-xs transition-colors"
                                    >
                                        <div>
                                            <span className="font-semibold">{w.symbol}</span>{' '}
                                            <span className="text-muted-foreground">{w.exchange_code}</span>
                                            <p className="text-muted-foreground text-[11px]">
                                                {w.rule_count} {t('safety.triggered_rules')}
                                            </p>
                                        </div>
                                        <SafetyBadge category={w.risk_category} score={w.risk_score} safeguard={w.safeguard_triggered} />
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>
                );

            case 'trading_signals':
                return (
                    <section key={id} className="bg-card border-border rounded-md border p-[var(--nadi-card-p)]">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-semibold">{t('dashboard.widget.trading_signals')}</h2>
                            <Link href="/opportunities" className="text-muted-foreground hover:text-foreground text-xs underline-offset-4 hover:underline">
                                {t('app.view_all')}
                            </Link>
                        </div>
                        {recentSignals.length === 0 ? (
                            <p className="text-muted-foreground text-xs">{t('opportunities.empty_title')}</p>
                        ) : (
                            <div className="divide-border divide-y">
                                {recentSignals.map((sig) => (
                                    <Link
                                        key={sig.id}
                                        href={`/entry-exit?exchange=${sig.exchange_code}&symbol=${sig.symbol}`}
                                        className="hover:bg-muted/40 flex items-center justify-between py-2 text-xs transition-colors"
                                    >
                                        <div>
                                            <span className="font-semibold">{sig.symbol}</span>{' '}
                                            <span className="text-muted-foreground">{sig.exchange_code}</span>
                                            <p className="text-muted-foreground text-[11px]">{t(`signals.stance.${sig.stance}`)}</p>
                                        </div>
                                        <div className="text-right">
                                            <div className="num font-semibold">
                                                {sig.net_rr ? `R:R ${formatNumber(sig.net_rr, locale, 2, 2)}x` : '—'}
                                            </div>
                                            <span className="text-muted-foreground num text-[11px]">
                                                TP {formatPrice(sig.target_1, sig.currency, locale)}
                                            </span>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>
                );

            case 'curated_news':
                return (
                    <ModuleEmptyState
                        key={id}
                        title={t('dashboard.widget.curated_news')}
                        description={t('planned.newsroom.desc')}
                        phase={8}
                        slug="newsroom"
                    />
                );

            case 'upcoming_events':
                return (
                    <section key={id} className="bg-card border-border rounded-md border p-[var(--nadi-card-p)]">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-semibold">{t('dashboard.widget.upcoming_events')}</h2>
                            <Link href="/alerts-calendar" className="text-muted-foreground hover:text-foreground text-xs underline-offset-4 hover:underline">
                                {t('app.view_all')}
                            </Link>
                        </div>
                        {upcomingEvents.length === 0 ? (
                            <p className="text-muted-foreground text-xs">{t('stocks.actions.empty')}</p>
                        ) : (
                            <div className="divide-border divide-y">
                                {upcomingEvents.map((ev) => (
                                    <Link
                                        key={ev.id}
                                        href="/alerts-calendar"
                                        className="hover:bg-muted/40 flex items-center justify-between py-2 text-xs transition-colors"
                                    >
                                        <div>
                                            <span className="font-semibold">{ev.symbol}</span>{' '}
                                            <span className="text-muted-foreground">{ev.exchange_code}</span>
                                            <p className="text-muted-foreground text-[11px]">{ev.action_type}</p>
                                        </div>
                                        <div className="text-right">
                                            <span className="num font-medium">{formatDate(ev.ex_date, locale)}</span>
                                            {ev.cash_amount && (
                                                <span className="text-muted-foreground num block text-[11px]">
                                                    {ev.declared_currency} {ev.cash_amount}
                                                </span>
                                            )}
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>
                );

            default:
                return null;
        }
    };

    const visibleWidgets = dashboardLayout.filter((w) => w.visible);
    const fullWidthIds = new Set(['market_indices', 'market_clocks', 'breadth_movers']);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('nav.overview')} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">{t('dashboard.title')}</h1>
                        <p className="text-muted-foreground text-xs">{t('dashboard.subtitle')}</p>
                    </div>
                    {featureFlags?.dashboard_customization !== false && (
                        <Button type="button" variant="outline" size="sm" onClick={() => setCustomizeOpen(true)} className="gap-1.5 text-xs">
                            <SlidersHorizontal className="h-3.5 w-3.5" />
                            <span>{t('dashboard.customize')}</span>
                        </Button>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    {visibleWidgets.map((w) => (
                        <div key={w.id} className={cn(fullWidthIds.has(w.id) && 'lg:col-span-2')}>
                            {renderWidget(w.id)}
                        </div>
                    ))}
                </div>
            </div>

            <Dialog open={customizeOpen} onOpenChange={setCustomizeOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('dashboard.customize')}</DialogTitle>
                        <DialogDescription>{t('dashboard.customize_desc')}</DialogDescription>
                    </DialogHeader>

                    <div className="divide-border max-h-80 divide-y overflow-y-auto">
                        {layoutDraft.map((item, idx) => (
                            <div key={item.id} className="flex items-center justify-between py-2.5">
                                <label className="flex cursor-pointer items-center gap-2.5 text-xs font-medium">
                                    <Checkbox
                                        checked={item.visible}
                                        onCheckedChange={(checked) => toggleWidget(item.id, Boolean(checked))}
                                    />
                                    <span>{t(`dashboard.widget.${item.id}`)}</span>
                                </label>
                                <div className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="h-7 w-7"
                                        disabled={idx === 0}
                                        onClick={() => moveWidget(idx, -1)}
                                    >
                                        <ArrowUp className="h-3.5 w-3.5" />
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="h-7 w-7"
                                        disabled={idx === layoutDraft.length - 1}
                                        onClick={() => moveWidget(idx, 1)}
                                    >
                                        <ArrowDown className="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => setCustomizeOpen(false)}>
                            {t('app.cancel')}
                        </Button>
                        <Button type="button" onClick={saveLayout}>
                            {t('app.save')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
