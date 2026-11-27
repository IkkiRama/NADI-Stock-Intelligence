import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { EmptyState } from '@/components/nadi/empty-state';
import { MetricCard } from '@/components/nadi/metric-card';
import { PriceChart } from '@/components/nadi/price-chart';
import AppLayout from '@/layouts/app-layout';
import { formatPct, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type OhlcvBar } from '@/types';
import { Head } from '@inertiajs/react';

interface IndexShowProps {
    index: {
        id: number;
        code: string;
        name: string;
        country_code: string;
        currency: string;
        index_type: string;
        exchange: { code: string; name: string; timezone: string } | null;
        quote: {
            close: string;
            prev_close: string | null;
            change: string | null;
            change_pct: string | null;
            as_of: string;
            is_demo: boolean;
            source: string;
        } | null;
    };
    stats: {
        high_52w: string | null;
        low_52w: string | null;
        return_1m: string | null;
        return_3m: string | null;
        return_6m: string | null;
        return_ytd: string | null;
        return_1y: string | null;
    };
    bars: OhlcvBar[];
    constituents: unknown[];
}

export default function IndexShow({ index, stats, bars }: IndexShowProps) {
    const { t, locale } = useT();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.global_markets'), href: '/markets' },
        { title: index.code, href: `/markets/indices/${index.code}` },
    ];

    const returnPeriods = [
        { label: t('markets.return_1m'), val: stats.return_1m },
        { label: t('markets.return_3m'), val: stats.return_3m },
        { label: t('markets.return_6m'), val: stats.return_6m },
        { label: t('markets.return_ytd'), val: stats.return_ytd },
        { label: t('markets.return_1y'), val: stats.return_1y },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${index.code} — ${index.name}`} />

            <div className="space-y-5">
                <div className="bg-card border-border flex flex-wrap items-start justify-between gap-4 rounded-md border p-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-semibold tracking-tight">{index.code}</h1>
                            <span className="border-border bg-muted rounded border px-1.5 py-0.5 text-[11px] font-medium">
                                {index.country_code} · {index.currency}
                            </span>
                            <span className="border-border bg-muted rounded border px-1.5 py-0.5 text-[11px]">
                                {t(`markets.index_type.${index.index_type}`)}
                            </span>
                        </div>
                        <p className="text-muted-foreground mt-0.5 text-sm">{index.name}</p>
                        {index.quote && (
                            <DataFreshness
                                asOf={index.quote.as_of}
                                source={index.quote.source}
                                isDemo={index.quote.is_demo}
                                className="mt-2"
                            />
                        )}
                    </div>

                    {index.quote && (
                        <div className="text-right">
                            <div className="num text-2xl font-semibold">{formatPrice(index.quote.close, index.currency, locale)}</div>
                            <ChangeBadge
                                change={index.quote.change}
                                changePct={index.quote.change_pct}
                                currency={index.currency}
                                showValue
                                pill
                                className="mt-1"
                            />
                        </div>
                    )}
                </div>

                <PriceChart bars={bars} showVolume={false} height={380} />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label={t('markets.high_52w')}
                        value={formatPrice(stats.high_52w, index.currency, locale)}
                    />
                    <MetricCard
                        label={t('markets.low_52w')}
                        value={formatPrice(stats.low_52w, index.currency, locale)}
                    />
                    <div className="bg-card border-border rounded-md border p-[var(--nadi-card-p)] sm:col-span-2">
                        <div className="text-muted-foreground mb-2 text-xs font-medium">Imbal Hasil Periode</div>
                        <div className="grid grid-cols-5 gap-2">
                            {returnPeriods.map((p) => (
                                <div key={p.label}>
                                    <span className="text-muted-foreground block text-[11px]">{p.label}</span>
                                    <span
                                        className={cn(
                                            'num text-xs font-semibold',
                                            p.val === null
                                                ? 'text-muted-foreground'
                                                : Number(p.val) >= 0
                                                  ? 'text-[var(--nadi-gain-ink)]'
                                                  : 'text-[var(--nadi-loss-ink)]',
                                        )}
                                    >
                                        {formatPct(p.val, locale)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="space-y-2">
                    <h2 className="text-sm font-semibold">{t('markets.constituents_title')}</h2>
                    <EmptyState
                        title={t('markets.constituents_title')}
                        description={t('markets.constituents_empty')}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
