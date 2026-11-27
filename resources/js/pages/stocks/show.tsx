import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { MarketStatusBadge } from '@/components/nadi/market-status-badge';
import { ModuleEmptyState } from '@/components/nadi/module-empty-state';
import { Money } from '@/components/nadi/money';
import { PriceChart } from '@/components/nadi/price-chart';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatCompact, formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type MarketClockStatus, type OhlcvBar } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Star } from 'lucide-react';

interface StockShowProps {
    instrument: {
        id: number;
        symbol: string;
        name: string;
        type: string;
        currency: string;
        sector: string | null;
        industry: string | null;
        country_code: string;
        lot_size: number;
        lot_size_verified: boolean;
        exchange: {
            code: string;
            name: string;
            timezone: string;
            clock: MarketClockStatus;
        };
        quote: {
            close: string;
            prev_close: string | null;
            change: string | null;
            change_pct: string | null;
            volume: number;
            value_traded: string | null;
            open: string | null;
            high: string | null;
            low: string | null;
            as_of: string;
            is_demo: boolean;
            source: string;
        } | null;
    };
    stats: {
        high_52w: string | null;
        low_52w: string | null;
        avg_volume_20d: string | null;
        return_1m: string | null;
        return_3m: string | null;
        return_6m: string | null;
        return_ytd: string | null;
        return_1y: string | null;
    };
    bars: OhlcvBar[];
    inWatchlist: boolean;
}

export default function StockShow({ instrument, stats, bars, inWatchlist }: StockShowProps) {
    const { t, locale } = useT();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.stock_explorer'), href: '/stocks' },
        { title: `${instrument.exchange.code}:${instrument.symbol}`, href: `/stocks/${instrument.exchange.code}/${instrument.symbol}` },
    ];

    const toggleWatchlist = () => {
        if (inWatchlist) {
            router.delete(`/watchlist/items/${instrument.id}`, { preserveScroll: true });
        } else {
            router.post('/watchlist/items', { instrument_id: instrument.id }, { preserveScroll: true });
        }
    };

    const returnStrip = [
        { label: t('markets.return_1m'), val: stats.return_1m },
        { label: t('markets.return_3m'), val: stats.return_3m },
        { label: t('markets.return_6m'), val: stats.return_6m },
        { label: t('markets.return_ytd'), val: stats.return_ytd },
        { label: t('markets.return_1y'), val: stats.return_1y },
    ];

    const asidePanel = (
        <div className="space-y-4">
            <div className="bg-card border-border rounded-md border p-4">
                <h2 className="mb-3 text-xs font-semibold tracking-wider uppercase">{t('stocks.detail.stats')}</h2>
                <dl className="divide-border divide-y text-xs">
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.open')}</dt>
                        <dd className="num font-medium">{formatPrice(instrument.quote?.open, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.high')}</dt>
                        <dd className="num font-medium">{formatPrice(instrument.quote?.high, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.low')}</dt>
                        <dd className="num font-medium">{formatPrice(instrument.quote?.low, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.prev_close')}</dt>
                        <dd className="num font-medium">{formatPrice(instrument.quote?.prev_close, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('markets.high_52w')}</dt>
                        <dd className="num font-medium">{formatPrice(stats.high_52w, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('markets.low_52w')}</dt>
                        <dd className="num font-medium">{formatPrice(stats.low_52w, instrument.currency, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.col.volume')}</dt>
                        <dd className="num font-medium">{formatCompact(instrument.quote?.volume, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.avg_vol_20d')}</dt>
                        <dd className="num font-medium">{formatCompact(stats.avg_volume_20d, locale)}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.value_traded')}</dt>
                        <dd className="num font-medium">{formatCompact(instrument.quote?.value_traded, locale)}</dd>
                    </div>
                </dl>
            </div>

            <div className="bg-card border-border rounded-md border p-4">
                <h2 className="mb-3 text-xs font-semibold tracking-wider uppercase">{t('stocks.detail.profile')}</h2>
                <dl className="divide-border divide-y text-xs">
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.col.exchange')}</dt>
                        <dd className="font-medium">{instrument.exchange.code}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.col.sector')}</dt>
                        <dd className="font-medium">{instrument.sector ?? '—'}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.currency')}</dt>
                        <dd className="font-medium">{instrument.currency}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.country')}</dt>
                        <dd className="font-medium">{instrument.country_code}</dd>
                    </div>
                    <div className="flex justify-between py-2">
                        <dt className="text-muted-foreground">{t('stocks.detail.lot_size')}</dt>
                        <dd className="num text-right font-medium">
                            {instrument.lot_size}
                            {!instrument.lot_size_verified && (
                                <span className="text-muted-foreground block text-[10px] font-normal">
                                    {t('stocks.detail.lot_unverified')}
                                </span>
                            )}
                        </dd>
                    </div>
                </dl>
            </div>

            <ModuleEmptyState
                title={t('stocks.detail.holding_status')}
                description={t('planned.portfolio.desc')}
                phase={4}
                slug="portfolio"
            />
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs} aside={asidePanel}>
            <Head title={`${instrument.symbol} (${instrument.exchange.code}) — ${instrument.name}`} />

            <div className="space-y-5">
                <div className="bg-card border-border flex flex-wrap items-start justify-between gap-4 rounded-md border p-4">
                    <div className="space-y-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold tracking-tight">{instrument.symbol}</h1>
                            <span className="border-border bg-muted rounded border px-1.5 py-0.5 text-[11px] font-semibold">
                                {instrument.exchange.code}
                            </span>
                            <MarketStatusBadge status={instrument.exchange.clock} />
                        </div>
                        <p className="text-muted-foreground text-sm">{instrument.name}</p>
                        {instrument.quote && (
                            <DataFreshness
                                asOf={instrument.quote.as_of}
                                source={instrument.quote.source}
                                isDemo={instrument.quote.is_demo}
                            />
                        )}
                    </div>

                    <div className="flex flex-col items-end gap-2">
                        {instrument.quote && (
                            <div className="text-right">
                                <div className="num text-2xl font-semibold">
                                    <Money value={instrument.quote.close} currency={instrument.currency} />
                                </div>
                                <ChangeBadge
                                    change={instrument.quote.change}
                                    changePct={instrument.quote.change_pct}
                                    currency={instrument.currency}
                                    showValue
                                    pill
                                    className="mt-1"
                                />
                            </div>
                        )}

                        <Button type="button" size="sm" variant={inWatchlist ? 'secondary' : 'outline'} onClick={toggleWatchlist} className="gap-1.5 text-xs">
                            <Star className={cn('h-3.5 w-3.5', inWatchlist && 'fill-amber-400 text-amber-500')} />
                            <span>{inWatchlist ? t('stocks.watchlist.remove') : t('stocks.watchlist.add')}</span>
                        </Button>
                    </div>
                </div>

                <PriceChart bars={bars} height={380} />

                <div className="bg-card border-border grid grid-cols-5 gap-2 rounded-md border p-3">
                    {returnStrip.map((item) => (
                        <div key={item.label}>
                            <span className="text-muted-foreground block text-[11px]">{item.label}</span>
                            <span
                                className={cn(
                                    'num text-xs font-semibold',
                                    item.val === null
                                        ? 'text-muted-foreground'
                                        : Number(item.val) >= 0
                                          ? 'text-[var(--nadi-gain-ink)]'
                                          : 'text-[var(--nadi-loss-ink)]',
                                )}
                            >
                                {formatPct(item.val, locale)}
                            </span>
                        </div>
                    ))}
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <ModuleEmptyState title={t('stocks.section.fundamentals')} description={t('planned.screener.desc')} phase={2} slug="screener" />
                    <ModuleEmptyState title={t('stocks.section.score')} description={t('planned.opportunities.desc')} phase={2} />
                    <ModuleEmptyState title={t('stocks.section.safety')} description={t('planned.stock_safety.desc')} phase={3} slug="stock-safety" />
                    <ModuleEmptyState title={t('stocks.section.entry_exit')} description={t('planned.entry_exit.desc')} phase={3} slug="entry-exit" />
                    <ModuleEmptyState title={t('stocks.section.news')} description={t('planned.newsroom.desc')} phase={8} slug="newsroom" />
                    <ModuleEmptyState title={t('stocks.section.broker')} description={t('planned.broker_intelligence.desc')} phase={10} slug="broker-intelligence" />
                </div>
            </div>
        </AppLayout>
    );
}
