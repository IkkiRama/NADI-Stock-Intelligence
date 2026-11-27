import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataFreshness } from '@/components/nadi/data-freshness';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { MarketStatusBadge } from '@/components/nadi/market-status-badge';
import { Sparkline } from '@/components/nadi/sparkline';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatPct, formatPrice, formatTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type ExchangeClockItem, type IndexCardItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface MarketsIndexProps {
    indicesByCountry: Record<string, IndexCardItem[]>;
    clocks: ExchangeClockItem[];
}

export default function MarketsIndex({ indicesByCountry, clocks }: MarketsIndexProps) {
    const { t, locale } = useT();

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.global_markets'), href: '/markets' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('markets.title')} />

            <div className="space-y-6">
                <div>
                    <h1 className="text-lg font-semibold tracking-tight">{t('markets.title')}</h1>
                    <p className="text-muted-foreground text-xs">{t('markets.subtitle')}</p>
                </div>

                <section className="space-y-4">
                    <h2 className="text-sm font-semibold">{t('markets.indices_heading')}</h2>
                    <div className="space-y-4">
                        {Object.entries(indicesByCountry).map(([country, items]) => (
                            <div key={country} className="space-y-2">
                                <h3 className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">{country}</h3>
                                <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                                    {items.map((idx) => (
                                        <Link
                                            key={idx.code}
                                            href={`/markets/indices/${idx.code}`}
                                            className="bg-card border-border hover:border-foreground/30 rounded-md border p-4 transition-colors"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="text-sm font-semibold">{idx.code}</span>
                                                        {idx.is_demo && <DemoBadge />}
                                                    </div>
                                                    <p className="text-muted-foreground truncate text-xs">{idx.name}</p>
                                                </div>
                                                <Sparkline
                                                    values={idx.sparkline}
                                                    positive={idx.change_pct !== null ? Number(idx.change_pct) >= 0 : null}
                                                    width={80}
                                                    height={28}
                                                />
                                            </div>

                                            <div className="mt-3 flex items-baseline justify-between">
                                                <span className="num text-lg font-semibold">
                                                    {formatPrice(idx.close, idx.currency, locale)}
                                                </span>
                                                <ChangeBadge change={idx.change} changePct={idx.change_pct} currency={idx.currency} pill />
                                            </div>

                                            <div className="border-border mt-3 grid grid-cols-3 gap-2 border-t pt-2.5 text-[11px]">
                                                <div>
                                                    <span className="text-muted-foreground block">{t('markets.return_1m')}</span>
                                                    <span className={cn('num font-medium', Number(idx.return_1m) >= 0 ? 'text-[var(--nadi-gain-ink)]' : 'text-[var(--nadi-loss-ink)]')}>
                                                        {formatPct(idx.return_1m, locale)}
                                                    </span>
                                                </div>
                                                <div>
                                                    <span className="text-muted-foreground block">{t('markets.return_ytd')}</span>
                                                    <span className={cn('num font-medium', Number(idx.return_ytd) >= 0 ? 'text-[var(--nadi-gain-ink)]' : 'text-[var(--nadi-loss-ink)]')}>
                                                        {formatPct(idx.return_ytd, locale)}
                                                    </span>
                                                </div>
                                                <div>
                                                    <span className="text-muted-foreground block">{t('markets.return_1y')}</span>
                                                    <span className={cn('num font-medium', Number(idx.return_1y) >= 0 ? 'text-[var(--nadi-gain-ink)]' : 'text-[var(--nadi-loss-ink)]')}>
                                                        {formatPct(idx.return_1y, locale)}
                                                    </span>
                                                </div>
                                            </div>

                                            <DataFreshness asOf={idx.as_of} source={idx.source} className="mt-2" />
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="space-y-3">
                    <h2 className="text-sm font-semibold">{t('markets.clocks_heading')}</h2>
                    <div className="border-border bg-card overflow-x-auto rounded-md border">
                        <table className="w-full border-collapse text-xs">
                            <thead>
                                <tr className="border-border bg-muted/50 border-b text-left">
                                    <th className="px-3 py-2 font-semibold">Bursa</th>
                                    <th className="px-3 py-2 font-semibold">Status</th>
                                    <th className="px-3 py-2 font-semibold">Waktu Lokal</th>
                                    <th className="px-3 py-2 font-semibold">{t('clock.next_open')}</th>
                                    <th className="px-3 py-2 font-semibold">Kalender Libur</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {clocks.map((ex) => (
                                    <tr key={ex.code}>
                                        <td className="px-3 py-2.5 font-medium">
                                            <Link href={`/stocks?exchange=${ex.code}`} className="hover:underline">
                                                {ex.code}
                                            </Link>{' '}
                                            <span className="text-muted-foreground font-normal">· {ex.name}</span>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <MarketStatusBadge status={ex.status} />
                                        </td>
                                        <td className="num px-3 py-2.5">
                                            {formatTime(ex.status.local_time, locale, ex.status.timezone)}{' '}
                                            <span className="text-muted-foreground">({ex.status.timezone})</span>
                                        </td>
                                        <td className="num px-3 py-2.5">
                                            {ex.status.next_open_at
                                                ? formatDateTime(ex.status.next_open_at, locale, ex.status.timezone)
                                                : '—'}
                                        </td>
                                        <td className="text-muted-foreground px-3 py-2.5">
                                            {ex.status.holidays_loaded ? 'Tersedia' : t('dashboard.holidays_not_loaded')}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
