import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataTable, type DataTableColumn } from '@/components/nadi/data-table';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { Money } from '@/components/nadi/money';
import { SafetyBadge } from '@/components/nadi/safety-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatNumber, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Eye, Sparkles } from 'lucide-react';
import { useState } from 'react';

interface OpportunityRow {
    id: number;
    symbol: string;
    name: string;
    sector: string | null;
    currency: string;
    exchange_code: string;
    close: string | null;
    change_pct: string | null;
    overall_score: string | null;
    safety_risk_score: string | null;
    safety_category: string;
    horizon: string;
    stance: 'actionable_setup' | 'watchlist_setup';
    entry_low: string | null;
    entry_high: string | null;
    stop_loss: string | null;
    target_1: string | null;
    net_rr: string | null;
    as_of: string | null;
    is_demo: boolean;
}

interface OpportunitiesProps {
    opportunities: {
        data: OpportunityRow[];
        total: number;
    };
    exchanges: Array<{ code: string; name: string }>;
    filters: {
        horizon: string;
        exchange: string;
        stance: string;
        min_score: string;
        max_risk: string;
    };
}

export default function Opportunities({ opportunities, exchanges, filters }: OpportunitiesProps) {
    const { t, locale } = useT();
    const [form, setForm] = useState(filters);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.opportunities'), href: '/opportunities' }];

    const apply = (overrides: Partial<typeof form> = {}) => {
        const next = { ...form, ...overrides };
        setForm(next);
        router.get('/opportunities', next, { preserveState: true, replace: true });
    };

    const columns: DataTableColumn<OpportunityRow>[] = [
        {
            key: 'symbol',
            header: t('stocks.col.symbol'),
            sticky: true,
            cell: (r) => (
                <Link href={`/stocks/${r.exchange_code}/${r.symbol}`} className="flex items-center gap-1.5 font-semibold hover:underline">
                    <span>{r.symbol}</span>
                    <span className="text-muted-foreground text-[10px] font-normal">{r.exchange_code}</span>
                    {r.is_demo && <DemoBadge />}
                </Link>
            ),
        },
        {
            key: 'stance',
            header: 'Setup',
            cell: (r) => {
                const actionable = r.stance === 'actionable_setup';
                return (
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-semibold',
                            actionable
                                ? 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)]'
                                : 'bg-[var(--nadi-ice-bg)] text-[var(--nadi-ice-ink)]',
                        )}
                    >
                        {actionable ? <CheckCircle2 className="h-3 w-3" /> : <Eye className="h-3 w-3" />}
                        <span>{t(`signals.stance.${r.stance}`)}</span>
                    </span>
                );
            },
        },
        {
            key: 'close',
            header: t('stocks.col.price'),
            align: 'right',
            cell: (r) => (
                <div className="text-right">
                    <Money value={r.close} currency={r.currency} />
                    <div>
                        <ChangeBadge changePct={r.change_pct} />
                    </div>
                </div>
            ),
        },
        {
            key: 'overall_score',
            header: 'Score',
            align: 'right',
            cell: (r) => <span className="num font-semibold">{r.overall_score ?? '—'}</span>,
        },
        {
            key: 'safety',
            header: t('screener.col.safety'),
            cell: (r) => <SafetyBadge category={r.safety_category} score={r.safety_risk_score} />,
        },
        {
            key: 'entry_zone',
            header: t('signals.entry_zone'),
            align: 'right',
            cell: (r) => (
                <span className="num text-xs">
                    {r.entry_low && r.entry_high
                        ? `${formatPrice(r.entry_low, r.currency, locale)} – ${formatPrice(r.entry_high, r.currency, locale)}`
                        : '—'}
                </span>
            ),
        },
        {
            key: 'stop_loss',
            header: t('signals.stop_loss'),
            align: 'right',
            cell: (r) => <span className="num text-[var(--nadi-loss-ink)] text-xs">{formatPrice(r.stop_loss, r.currency, locale)}</span>,
        },
        {
            key: 'target_1',
            header: t('signals.target_1'),
            align: 'right',
            cell: (r) => <span className="num text-[var(--nadi-gain-ink)] text-xs">{formatPrice(r.target_1, r.currency, locale)}</span>,
        },
        {
            key: 'net_rr',
            header: 'Net R:R',
            align: 'right',
            cell: (r) => <span className="num font-semibold">{r.net_rr ? `${formatNumber(r.net_rr, locale, 2, 2)}x` : '—'}</span>,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('opportunities.title')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className="bg-[var(--nadi-sage)]/25 text-[var(--nadi-gain-ink)] flex h-9 w-9 items-center justify-center rounded-md">
                            <Sparkles className="h-4 w-4" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold tracking-tight">{t('opportunities.title')}</h1>
                            <p className="text-muted-foreground text-xs">{t('opportunities.subtitle')}</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-1 rounded-md border p-1">
                        {(['short_term', 'swing', 'long_term'] as const).map((h) => (
                            <Button
                                key={h}
                                type="button"
                                size="sm"
                                variant={form.horizon === h ? 'default' : 'ghost'}
                                className="h-7 px-2.5 text-xs"
                                onClick={() => apply({ horizon: h })}
                            >
                                {t(`signals.horizon.${h}`)}
                            </Button>
                        ))}
                    </div>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply();
                    }}
                    className="bg-card border-border grid gap-3 rounded-md border p-4 sm:grid-cols-2 md:grid-cols-5"
                >
                    <div className="space-y-1">
                        <Label className="text-[11px]">{t('stocks.col.exchange')}</Label>
                        <Select value={form.exchange} onValueChange={(v) => apply({ exchange: v })}>
                            <SelectTrigger className="h-8 text-xs">
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

                    <div className="space-y-1">
                        <Label className="text-[11px]">Status Setup</Label>
                        <Select value={form.stance} onValueChange={(v) => apply({ stance: v })}>
                            <SelectTrigger className="h-8 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('app.all')}</SelectItem>
                                <SelectItem value="actionable_setup">{t('signals.stance.actionable_setup')}</SelectItem>
                                <SelectItem value="watchlist_setup">{t('signals.stance.watchlist_setup')}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <Label className="text-[11px]">{t('screener.score_min')}</Label>
                        <Input
                            type="number"
                            value={form.min_score}
                            onChange={(e) => setForm({ ...form, min_score: e.target.value })}
                            className="num h-8 text-xs"
                        />
                    </div>

                    <div className="space-y-1">
                        <Label className="text-[11px]">{t('screener.max_safety_risk')}</Label>
                        <Input
                            type="number"
                            value={form.max_risk}
                            onChange={(e) => setForm({ ...form, max_risk: e.target.value })}
                            className="num h-8 text-xs"
                        />
                    </div>

                    <div className="flex items-end">
                        <Button type="submit" size="sm" className="h-8 w-full text-xs">
                            {t('app.filter')} ({opportunities.total})
                        </Button>
                    </div>
                </form>

                <DataTable
                    rows={opportunities.data}
                    columns={columns}
                    rowKey={(r) => r.id}
                    onRowClick={(r) => router.visit(`/entry-exit?exchange=${r.exchange_code}&symbol=${r.symbol}&horizon=${r.horizon}`)}
                    emptyState={<EmptyState title={t('opportunities.empty_title')} description={t('opportunities.empty_desc')} />}
                />
            </div>
        </AppLayout>
    );
}
