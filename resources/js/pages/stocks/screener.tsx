import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataTable, type DataTableColumn } from '@/components/nadi/data-table';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { Money } from '@/components/nadi/money';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatCompact, formatNumber, formatPct } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, BookmarkPlus, Download, Filter, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface ScreenerRow {
    id: number;
    symbol: string;
    name: string;
    sector: string | null;
    currency: string;
    country_code: string;
    exchange_code: string;
    close: string | null;
    change_pct: string | null;
    return_1m: string | null;
    volume: number | null;
    market_cap_idr: string | null;
    per: string | null;
    pbv: string | null;
    roe: string | null;
    dividend_yield: string | null;
    rsi_14: string | null;
    overall_score: string | null;
    is_demo: boolean;
}

interface SavedPreset {
    id: number;
    name: string;
    filters: Record<string, unknown>;
    sort_by: string;
    sort_dir: 'asc' | 'desc';
}

interface ScreenerProps {
    results: {
        data: ScreenerRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total: number;
    };
    exchanges: Array<{ code: string; name: string }>;
    sectors: string[];
    countries: string[];
    watchlistedIds: number[];
    savedScreeners: SavedPreset[];
    filters: Record<string, unknown> & { sort: string; dir: 'asc' | 'desc' };
}

export default function Screener({
    results,
    exchanges,
    sectors,
    countries,
    watchlistedIds,
    savedScreeners,
    filters,
}: ScreenerProps) {
    const { t, locale } = useT();
    const [form, setForm] = useState<Record<string, unknown>>(filters);
    const [presetOpen, setPresetOpen] = useState(false);
    const [presetName, setPresetName] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.screener'), href: '/screener' }];

    const buildQueryParams = (state: Record<string, unknown>) => {
        const cleaned: Record<string, string> = {};
        for (const [k, v] of Object.entries(state)) {
            if (v === null || v === undefined || v === '' || v === 'ALL' || v === false) {
                continue;
            }
            cleaned[k] = v === true ? '1' : String(v);
        }
        return cleaned;
    };

    const applyFilters = (overrides: Record<string, unknown> = {}) => {
        const next = { ...form, ...overrides };
        setForm(next);
        router.get('/screener', buildQueryParams(next), { preserveState: true, replace: true });
    };

    const resetFilters = () => {
        const empty = { sort: 'overall_score', dir: 'desc' };
        setForm(empty);
        router.get('/screener', empty, { preserveState: true, replace: true });
    };

    const toggleSort = (col: string) => {
        const nextDir = filters.sort === col && filters.dir === 'desc' ? 'asc' : 'desc';
        applyFilters({ sort: col, dir: nextDir });
    };

    const savePreset = (e: React.FormEvent) => {
        e.preventDefault();
        if (!presetName.trim()) return;
        router.post(
            '/screener/presets',
            {
                name: presetName.trim(),
                filters: buildQueryParams(form),
                sort_by: String(form.sort || 'overall_score'),
                sort_dir: String(form.dir || 'desc'),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPresetName('');
                    setPresetOpen(false);
                },
            },
        );
    };

    const loadPreset = (preset: SavedPreset) => {
        const next = {
            ...preset.filters,
            sort: preset.sort_by,
            dir: preset.sort_dir,
        };
        setForm(next);
        router.get('/screener', buildQueryParams(next), { preserveState: true });
    };

    const exportUrl = `/screener/export?${new URLSearchParams(buildQueryParams(form)).toString()}`;

    const renderSortHeader = (col: string, label: string) => (
        <button type="button" onClick={() => toggleSort(col)} className="hover:text-foreground inline-flex items-center gap-1 font-semibold">
            <span>{label}</span>
            {filters.sort === col && (filters.dir === 'asc' ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />)}
        </button>
    );

    const columns: DataTableColumn<ScreenerRow>[] = [
        {
            key: 'symbol',
            header: renderSortHeader('symbol', t('stocks.col.symbol')),
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
            key: 'overall_score',
            header: renderSortHeader('overall_score', 'Score'),
            align: 'right',
            cell: (r) => (
                <span className="num border-border bg-muted rounded border px-1.5 py-0.5 text-xs font-semibold">
                    {r.overall_score ?? '—'}
                </span>
            ),
        },
        {
            key: 'close',
            header: renderSortHeader('close', t('stocks.col.price')),
            align: 'right',
            cell: (r) => <Money value={r.close} currency={r.currency} />,
        },
        {
            key: 'change_pct',
            header: renderSortHeader('change_pct', '1D'),
            align: 'right',
            cell: (r) => <ChangeBadge changePct={r.change_pct} pill />,
        },
        {
            key: 'return_1m',
            header: renderSortHeader('return_1m', '1M'),
            align: 'right',
            cell: (r) => <span className="num text-xs">{formatPct(r.return_1m, locale)}</span>,
        },
        {
            key: 'per',
            header: renderSortHeader('per', 'PER'),
            align: 'right',
            cell: (r) => <span className="num text-xs">{r.per ? `${formatNumber(r.per, locale, 1, 1)}x` : '—'}</span>,
        },
        {
            key: 'pbv',
            header: renderSortHeader('pbv', 'PBV'),
            align: 'right',
            cell: (r) => <span className="num text-xs">{r.pbv ? `${formatNumber(r.pbv, locale, 2, 2)}x` : '—'}</span>,
        },
        {
            key: 'roe',
            header: renderSortHeader('roe', 'ROE'),
            align: 'right',
            cell: (r) => <span className="num text-xs">{formatPct(r.roe, locale, false)}</span>,
        },
        {
            key: 'dividend_yield',
            header: renderSortHeader('dividend_yield', 'Div Yield'),
            align: 'right',
            cell: (r) => <span className="num text-xs">{formatPct(r.dividend_yield, locale, false)}</span>,
        },
        {
            key: 'rsi_14',
            header: renderSortHeader('rsi_14', 'RSI'),
            align: 'right',
            cell: (r) => <span className="num text-xs">{formatNumber(r.rsi_14, locale, 1, 1)}</span>,
        },
        {
            key: 'market_cap_idr',
            header: renderSortHeader('market_cap_idr', 'MCap (IDR)'),
            align: 'right',
            cell: (r) => <span className="num text-xs">{formatCompact(r.market_cap_idr, locale)}</span>,
        },
        {
            key: 'star',
            header: '',
            align: 'center',
            cell: (r) => {
                const starred = watchlistedIds.includes(r.id);
                return (
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="h-7 w-7"
                        onClick={(e) => {
                            e.stopPropagation();
                            if (starred) {
                                router.delete(`/watchlist/items/${r.id}`, { preserveScroll: true });
                            } else {
                                router.post('/watchlist/items', { instrument_id: r.id }, { preserveScroll: true });
                            }
                        }}
                    >
                        <Star className={cn('h-3.5 w-3.5', starred ? 'fill-amber-400 text-amber-500' : 'text-muted-foreground')} />
                    </Button>
                );
            },
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('screener.title')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">{t('screener.title')}</h1>
                        <p className="text-muted-foreground text-xs">{t('screener.subtitle')}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" size="sm" variant="outline" className="gap-1.5 text-xs" onClick={() => setPresetOpen(true)}>
                            <BookmarkPlus className="h-3.5 w-3.5" />
                            <span>{t('screener.preset.save')}</span>
                        </Button>
                        <Button asChild size="sm" variant="outline" className="gap-1.5 text-xs">
                            <a href={exportUrl}>
                                <Download className="h-3.5 w-3.5" />
                                <span>{t('screener.export_csv')}</span>
                            </a>
                        </Button>
                    </div>
                </div>

                {/* Saved Presets Strip */}
                {savedScreeners.length > 0 && (
                    <div className="bg-card border-border flex flex-wrap items-center gap-2 rounded-md border px-3 py-2 text-xs">
                        <span className="text-muted-foreground font-medium">{t('screener.preset.saved_list')}:</span>
                        {savedScreeners.map((p) => (
                            <div key={p.id} className="border-border bg-muted/40 inline-flex items-center gap-1 rounded border px-2 py-0.5">
                                <button type="button" onClick={() => loadPreset(p)} className="font-medium hover:underline">
                                    {p.name}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => router.delete(`/screener/presets/${p.id}`, { preserveScroll: true })}
                                    className="text-muted-foreground hover:text-destructive ml-1"
                                    title="Hapus preset"
                                >
                                    <Trash2 className="h-3 w-3" />
                                </button>
                            </div>
                        ))}
                    </div>
                )}

                {/* Filter Grid */}
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilters();
                    }}
                    className="bg-card border-border space-y-3 rounded-md border p-4"
                >
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-1.5 text-xs font-semibold">
                            <Filter className="h-3.5 w-3.5" />
                            <span>{t('screener.filters')}</span>
                        </div>
                        <Button type="button" size="sm" variant="ghost" className="h-7 text-xs" onClick={resetFilters}>
                            {t('app.reset')}
                        </Button>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-5">
                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('stocks.col.exchange')}</Label>
                            <Select value={String(form.exchange || 'ALL')} onValueChange={(v) => setForm({ ...form, exchange: v === 'ALL' ? '' : v })}>
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
                            <Label className="text-[11px]">{t('stocks.col.sector')}</Label>
                            <Select value={String(form.sector || 'ALL')} onValueChange={(v) => setForm({ ...form, sector: v === 'ALL' ? '' : v })}>
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ALL">{t('app.all')}</SelectItem>
                                    {sectors.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {s}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('stocks.detail.country')}</Label>
                            <Select value={String(form.country || 'ALL')} onValueChange={(v) => setForm({ ...form, country: v === 'ALL' ? '' : v })}>
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ALL">{t('app.all')}</SelectItem>
                                    {countries.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('screener.per_max')}</Label>
                            <Input
                                type="number"
                                step="any"
                                value={String(form.per_max ?? '')}
                                onChange={(e) => setForm({ ...form, per_max: e.target.value })}
                                className="h-8 text-xs"
                            />
                        </div>

                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('screener.pbv_max')}</Label>
                            <Input
                                type="number"
                                step="any"
                                value={String(form.pbv_max ?? '')}
                                onChange={(e) => setForm({ ...form, pbv_max: e.target.value })}
                                className="h-8 text-xs"
                            />
                        </div>

                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('screener.roe_min')}</Label>
                            <Input
                                type="number"
                                step="any"
                                value={String(form.roe_min ?? '')}
                                onChange={(e) => setForm({ ...form, roe_min: e.target.value })}
                                className="h-8 text-xs"
                            />
                        </div>

                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('screener.rsi_min')}</Label>
                            <Input
                                type="number"
                                step="any"
                                value={String(form.rsi_min ?? '')}
                                onChange={(e) => setForm({ ...form, rsi_min: e.target.value })}
                                className="h-8 text-xs"
                            />
                        </div>

                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('screener.rsi_max')}</Label>
                            <Input
                                type="number"
                                step="any"
                                value={String(form.rsi_max ?? '')}
                                onChange={(e) => setForm({ ...form, rsi_max: e.target.value })}
                                className="h-8 text-xs"
                            />
                        </div>

                        <div className="space-y-1">
                            <Label className="text-[11px]">{t('screener.score_min')}</Label>
                            <Input
                                type="number"
                                step="any"
                                value={String(form.score_min ?? '')}
                                onChange={(e) => setForm({ ...form, score_min: e.target.value })}
                                className="h-8 text-xs"
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3 pt-1">
                        <div className="flex flex-wrap items-center gap-4 text-xs">
                            <label className="flex items-center gap-1.5">
                                <Checkbox
                                    checked={Boolean(form.profitable_only)}
                                    onCheckedChange={(c) => setForm({ ...form, profitable_only: Boolean(c) })}
                                />
                                <span>{t('screener.profitable_only')}</span>
                            </label>
                            <label className="flex items-center gap-1.5">
                                <Checkbox checked={Boolean(form.above_sma50)} onCheckedChange={(c) => setForm({ ...form, above_sma50: Boolean(c) })} />
                                <span>{t('screener.above_sma50')}</span>
                            </label>
                            <label className="flex items-center gap-1.5">
                                <Checkbox checked={Boolean(form.above_sma200)} onCheckedChange={(c) => setForm({ ...form, above_sma200: Boolean(c) })} />
                                <span>{t('screener.above_sma200')}</span>
                            </label>
                        </div>

                        <Button type="submit" size="sm" className="h-8">
                            {t('app.filter')} ({results.total})
                        </Button>
                    </div>
                </form>

                <DataTable
                    rows={results.data}
                    columns={columns}
                    rowKey={(r) => r.id}
                    onRowClick={(r) => router.visit(`/stocks/${r.exchange_code}/${r.symbol}`)}
                    emptyState={<EmptyState title={t('stocks.empty_title')} description={t('stocks.empty_desc')} />}
                />
            </div>

            <Dialog open={presetOpen} onOpenChange={setPresetOpen}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('screener.preset.save')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={savePreset} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>{t('screener.preset.name')}</Label>
                            <Input value={presetName} onChange={(e) => setPresetName(e.target.value)} required />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setPresetOpen(false)}>
                                {t('app.cancel')}
                            </Button>
                            <Button type="submit">{t('app.save')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
