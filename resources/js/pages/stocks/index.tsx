import { ChangeBadge } from '@/components/nadi/change-badge';
import { DataTable, type DataTableColumn } from '@/components/nadi/data-table';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { Money } from '@/components/nadi/money';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatCompact } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Search, Star } from 'lucide-react';
import { useState } from 'react';

interface StockRow {
    id: number;
    symbol: string;
    name: string;
    sector: string | null;
    currency: string;
    country_code: string;
    exchange_code: string;
    close: string | null;
    change: string | null;
    change_pct: string | null;
    volume: number | null;
    as_of: string | null;
    is_demo: boolean;
}

interface StocksIndexProps {
    stocks: {
        data: StockRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total: number;
    };
    exchanges: Array<{ code: string; name: string }>;
    sectors: string[];
    watchlistedIds: number[];
    filters: {
        exchange: string;
        sector: string;
        q: string;
        sort: string;
        dir: 'asc' | 'desc';
    };
}

export default function StocksIndex({ stocks, exchanges, sectors, watchlistedIds, filters }: StocksIndexProps) {
    const { t, locale } = useT();
    const [qInput, setQInput] = useState(filters.q || '');

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.stock_explorer'), href: '/stocks' }];

    const applyFilter = (overrides: Record<string, string>) => {
        const next = {
            exchange: filters.exchange || '',
            sector: filters.sector || '',
            q: qInput,
            sort: filters.sort || 'symbol',
            dir: filters.dir || 'asc',
            ...overrides,
        };

        const cleaned: Record<string, string> = {};
        for (const [k, v] of Object.entries(next)) {
            if (v && v !== 'ALL') {
                cleaned[k] = v;
            }
        }

        router.get('/stocks', cleaned, { preserveState: true, replace: true });
    };

    const toggleSort = (col: string) => {
        const nextDir = filters.sort === col && filters.dir === 'asc' ? 'desc' : 'asc';
        applyFilter({ sort: col, dir: nextDir });
    };

    const toggleWatchlist = (e: React.MouseEvent, stock: StockRow) => {
        e.stopPropagation();
        if (watchlistedIds.includes(stock.id)) {
            router.delete(`/watchlist/items/${stock.id}`, { preserveScroll: true });
        } else {
            router.post('/watchlist/items', { instrument_id: stock.id }, { preserveScroll: true });
        }
    };

    const renderSortHeader = (col: string, label: string) => (
        <button
            type="button"
            onClick={() => toggleSort(col)}
            className="hover:text-foreground inline-flex items-center gap-1 font-semibold"
        >
            <span>{label}</span>
            {filters.sort === col && (filters.dir === 'asc' ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />)}
        </button>
    );

    const columns: DataTableColumn<StockRow>[] = [
        {
            key: 'symbol',
            header: renderSortHeader('symbol', t('stocks.col.symbol')),
            sticky: true,
            cell: (row) => (
                <Link href={`/stocks/${row.exchange_code}/${row.symbol}`} className="flex items-center gap-2 font-semibold hover:underline">
                    <span>{row.symbol}</span>
                    {row.is_demo && <DemoBadge />}
                </Link>
            ),
        },
        {
            key: 'name',
            header: renderSortHeader('name', t('stocks.col.name')),
            cell: (row) => <span className="max-w-[240px] truncate block text-xs">{row.name}</span>,
        },
        {
            key: 'exchange',
            header: t('stocks.col.exchange'),
            cell: (row) => <span className="border-border bg-muted rounded border px-1.5 py-0.5 text-[11px] font-medium">{row.exchange_code}</span>,
        },
        {
            key: 'sector',
            header: t('stocks.col.sector'),
            cell: (row) => <span className="text-muted-foreground text-xs">{row.sector ?? '—'}</span>,
        },
        {
            key: 'close',
            header: renderSortHeader('close', t('stocks.col.price')),
            align: 'right',
            cell: (row) => <Money value={row.close} currency={row.currency} className="font-medium" />,
        },
        {
            key: 'change_pct',
            header: renderSortHeader('change_pct', t('stocks.col.change')),
            align: 'right',
            cell: (row) => <ChangeBadge changePct={row.change_pct} pill />,
        },
        {
            key: 'volume',
            header: renderSortHeader('volume', t('stocks.col.volume')),
            align: 'right',
            cell: (row) => <span className="num text-xs">{formatCompact(row.volume, locale)}</span>,
        },
        {
            key: 'actions',
            header: '',
            align: 'center',
            cell: (row) => {
                const starred = watchlistedIds.includes(row.id);
                return (
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="h-8 w-8"
                        onClick={(e) => toggleWatchlist(e, row)}
                        title={starred ? t('stocks.watchlist.remove') : t('stocks.watchlist.add')}
                    >
                        <Star className={cn('h-4 w-4', starred ? 'fill-amber-400 text-amber-500' : 'text-muted-foreground')} />
                    </Button>
                );
            },
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('stocks.title')} />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold tracking-tight">{t('stocks.title')}</h1>
                    <p className="text-muted-foreground text-xs">{t('stocks.subtitle')}</p>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilter({ q: qInput });
                    }}
                    className="bg-card border-border flex flex-wrap items-center gap-2 rounded-md border p-3"
                >
                    <div className="relative min-w-[200px] flex-1">
                        <Search className="text-muted-foreground absolute top-2.5 left-2.5 h-4 w-4" />
                        <Input
                            value={qInput}
                            onChange={(e) => setQInput(e.target.value)}
                            placeholder={t('stocks.search_placeholder')}
                            className="h-9 pl-8 text-xs"
                        />
                    </div>

                    <Select value={filters.exchange || 'ALL'} onValueChange={(v) => applyFilter({ exchange: v })}>
                        <SelectTrigger className="h-9 w-36 text-xs">
                            <SelectValue placeholder={t('stocks.col.exchange')} />
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

                    <Select value={filters.sector || 'ALL'} onValueChange={(v) => applyFilter({ sector: v })}>
                        <SelectTrigger className="h-9 w-48 text-xs">
                            <SelectValue placeholder={t('stocks.col.sector')} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="ALL">{t('app.all')}</SelectItem>
                            {sectors.map((sec) => (
                                <SelectItem key={sec} value={sec}>
                                    {sec}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Button type="submit" size="sm" className="h-9">
                        {t('app.search')}
                    </Button>
                </form>

                <DataTable
                    rows={stocks.data}
                    columns={columns}
                    rowKey={(r) => r.id}
                    onRowClick={(r) => router.visit(`/stocks/${r.exchange_code}/${r.symbol}`)}
                    emptyState={<EmptyState title={t('stocks.empty_title')} description={t('stocks.empty_desc')} />}
                />

                {stocks.links.length > 3 && (
                    <div className="flex flex-wrap items-center justify-end gap-1">
                        {stocks.links.map((link, idx) => (
                            <Button
                                key={idx}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url, { preserveState: true })}
                                className="h-8 px-2.5 text-xs"
                            >
                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
