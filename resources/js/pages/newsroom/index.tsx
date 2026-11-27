import { ChangeBadge } from '@/components/nadi/change-badge';
import { DemoBadge } from '@/components/nadi/demo-badge';
import { EmptyState } from '@/components/nadi/empty-state';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ExternalLink, Newspaper, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';

interface NewsInstrumentTag {
    id: number;
    symbol: string;
    name: string;
    exchange_code: string;
    currency: string;
    close: string | null;
    change_pct: string | null;
}

interface NewsArticleItem {
    id: number;
    slug: string;
    title: string;
    summary: string;
    body: string | null;
    url: string | null;
    source_name: string;
    category: string;
    country_code: string | null;
    language: string;
    impact_tag: string;
    published_at: string | null;
    is_demo: boolean;
    instruments: NewsInstrumentTag[];
}

interface NewsroomProps {
    articles: {
        data: NewsArticleItem[];
        current_page: number;
        last_page: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        scope: string;
        category: string;
        country: string;
        impact: string;
        symbol: string;
        q: string;
        article: string;
    };
    categories: string[];
    impacts: string[];
    countries: string[];
    instruments: Array<{ id: number; symbol: string; name: string; exchange_code: string }>;
    canPublish: boolean;
}

export default function NewsroomPage({
    articles,
    filters,
    categories,
    impacts,
    countries,
    instruments,
    canPublish,
}: NewsroomProps) {
    const { t, locale } = useT();
    const { settings, flash } = usePage<SharedData>().props;

    const [searchQ, setSearchQ] = useState(filters.q);
    const [selectedArticle, setSelectedArticle] = useState<NewsArticleItem | null>(() => {
        if (filters.article) {
            return articles.data.find((a) => a.slug === filters.article) ?? articles.data[0] ?? null;
        }
        return articles.data[0] ?? null;
    });
    const [publishOpen, setPublishOpen] = useState(false);

    // Publish form state (admin only)
    const [pubTitle, setPubTitle] = useState('');
    const [pubSummary, setPubSummary] = useState('');
    const [pubBody, setPubBody] = useState('');
    const [pubSource, setPubSource] = useState('NADI Editorial');
    const [pubUrl, setPubUrl] = useState('');
    const [pubCategory, setPubCategory] = useState(categories[0] ?? 'market_recap');
    const [pubCountry, setPubCountry] = useState('ID');
    const [pubImpact, setPubImpact] = useState('neutral');
    const [pubInstrumentId, setPubInstrumentId] = useState<string>('');
    const [publishing, setPublishing] = useState(false);

    useEffect(() => {
        if (filters.article) {
            const match = articles.data.find((a) => a.slug === filters.article);
            if (match) {
                setSelectedArticle(match);
                return;
            }
        }
        setSelectedArticle((prev) => {
            if (prev && articles.data.some((a) => a.id === prev.id)) {
                return prev;
            }
            return articles.data[0] ?? null;
        });
    }, [articles.data, filters.article]);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.newsroom'), href: '/newsroom' }];

    const navigateFilters = (overrides: Record<string, string>) => {
        const next: Record<string, string> = {
            scope: overrides.scope ?? filters.scope,
            category: overrides.category ?? filters.category,
            country: overrides.country ?? filters.country,
            impact: overrides.impact ?? filters.impact,
            q: overrides.q ?? searchQ,
        };
        if (overrides.symbol ?? filters.symbol) {
            next.symbol = overrides.symbol ?? filters.symbol;
        }
        router.get('/newsroom', next, { preserveState: true, preserveScroll: true });
    };

    const submitPublish = (e: React.FormEvent) => {
        e.preventDefault();
        setPublishing(true);
        router.post(
            '/newsroom/articles',
            {
                title: pubTitle,
                summary: pubSummary,
                body: pubBody || null,
                source_name: pubSource,
                url: pubUrl || null,
                category: pubCategory,
                country_code: pubCountry === 'NONE' ? null : pubCountry,
                impact_tag: pubImpact,
                instrument_ids: pubInstrumentId ? [Number(pubInstrumentId)] : [],
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPubTitle('');
                    setPubSummary('');
                    setPubBody('');
                    setPubUrl('');
                    setPubInstrumentId('');
                    setPublishOpen(false);
                },
                onFinish: () => setPublishing(false),
            },
        );
    };

    const renderImpactBadge = (impact: string) => {
        const cls =
            impact === 'positive'
                ? 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)]'
                : impact === 'caution'
                  ? 'bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]'
                  : 'bg-muted text-muted-foreground';
        return (
            <span className={cn('rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase', cls)}>
                {t(`news.impact.${impact}`)}
            </span>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('news.title')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-2.5">
                        <div className="bg-[var(--nadi-ice)]/35 text-foreground flex h-9 w-9 items-center justify-center rounded-md">
                            <Newspaper className="h-4 w-4" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold tracking-tight">{t('news.title')}</h1>
                            <p className="text-muted-foreground text-xs">{t('news.subtitle')}</p>
                        </div>
                    </div>

                    {canPublish && (
                        <Button type="button" size="sm" className="h-8 gap-1 text-xs" onClick={() => setPublishOpen(true)}>
                            <Plus className="h-3.5 w-3.5" />
                            <span>{t('news.publish_btn')}</span>
                        </Button>
                    )}
                </div>

                {flash?.status && (
                    <div className="bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)] rounded-md px-3 py-2 text-xs font-medium">
                        Status: {flash.status}
                    </div>
                )}

                {/* Scope Tabs & Filter Strip */}
                <div className="bg-card border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                    <div className="flex flex-wrap items-center gap-1">
                        {(
                            [
                                ['all', t('news.scope.all')],
                                ['portfolio', t('news.scope.portfolio')],
                                ['watchlist', t('news.scope.watchlist')],
                            ] as const
                        ).map(([key, label]) => (
                            <Button
                                key={key}
                                type="button"
                                size="sm"
                                variant={filters.scope === key ? 'secondary' : 'ghost'}
                                className="h-7 text-xs"
                                onClick={() => navigateFilters({ scope: key })}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.category} onValueChange={(v) => navigateFilters({ category: v })}>
                            <SelectTrigger className="h-8 w-36 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('news.filter.all_categories')}</SelectItem>
                                {categories.map((c) => (
                                    <SelectItem key={c} value={c}>
                                        {t(`news.cat.${c}`)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={filters.country} onValueChange={(v) => navigateFilters({ country: v })}>
                            <SelectTrigger className="h-8 w-28 text-xs">
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

                        <Select value={filters.impact} onValueChange={(v) => navigateFilters({ impact: v })}>
                            <SelectTrigger className="h-8 w-32 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('news.filter.all_impacts')}</SelectItem>
                                {impacts.map((imp) => (
                                    <SelectItem key={imp} value={imp}>
                                        {t(`news.impact.${imp}`)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                navigateFilters({ q: searchQ });
                            }}
                            className="flex items-center gap-1"
                        >
                            <div className="relative">
                                <Search className="text-muted-foreground absolute top-2 left-2.5 h-3.5 w-3.5" />
                                <Input
                                    value={searchQ}
                                    onChange={(e) => setSearchQ(e.target.value)}
                                    placeholder={t('news.search_placeholder')}
                                    className="h-8 w-44 pl-8 text-xs"
                                />
                            </div>
                            <Button type="submit" size="sm" variant="outline" className="h-8 text-xs">
                                {t('app.search')}
                            </Button>
                        </form>
                    </div>
                </div>

                {articles.data.length === 0 ? (
                    <EmptyState icon={Newspaper} title={t('news.empty.title')} description={t('news.empty.desc')} />
                ) : (
                    <div className="grid gap-4 lg:grid-cols-12">
                        {/* Editorial Feed */}
                        <div className="space-y-2 lg:col-span-5">
                            {articles.data.map((article) => {
                                const isActive = selectedArticle?.id === article.id;
                                return (
                                    <button
                                        key={article.id}
                                        type="button"
                                        onClick={() => setSelectedArticle(article)}
                                        className={cn(
                                            'bg-card border-border hover:border-foreground/30 w-full rounded-md border p-3 text-left transition-colors',
                                            isActive && 'border-foreground/50 ring-1 ring-foreground/15',
                                        )}
                                    >
                                        <div className="mb-1 flex flex-wrap items-center justify-between gap-1.5 text-[11px]">
                                            <div className="flex items-center gap-1.5">
                                                <span className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 font-medium">
                                                    {t(`news.cat.${article.category}`)}
                                                </span>
                                                {renderImpactBadge(article.impact_tag)}
                                                {article.is_demo && <DemoBadge />}
                                            </div>
                                            <span className="text-muted-foreground num">
                                                {formatDateTime(article.published_at, locale, settings?.timezone)}
                                            </span>
                                        </div>
                                        <h2 className="text-sm font-semibold leading-snug">{article.title}</h2>
                                        <p className="text-muted-foreground mt-1 line-clamp-2 text-xs">{article.summary}</p>
                                        <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-[11px]">
                                            <span className="text-muted-foreground">{article.source_name}</span>
                                            <div className="flex flex-wrap gap-1">
                                                {article.instruments.map((inst) => (
                                                    <span key={inst.id} className="bg-muted rounded px-1.5 py-0.5 font-medium">
                                                        {inst.symbol}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    </button>
                                );
                            })}

                            {/* Pagination */}
                            {articles.last_page > 1 && (
                                <div className="flex items-center justify-between pt-2 text-xs">
                                    <span className="text-muted-foreground">
                                        Page {articles.current_page} / {articles.last_page}
                                    </span>
                                    <div className="flex gap-1">
                                        {articles.links.map((lnk, idx) =>
                                            lnk.url ? (
                                                <Button
                                                    key={idx}
                                                    type="button"
                                                    size="sm"
                                                    variant={lnk.active ? 'default' : 'outline'}
                                                    className="h-7 px-2 text-xs"
                                                    onClick={() => router.get(lnk.url!, {}, { preserveScroll: true })}
                                                >
                                                    {lnk.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                                                </Button>
                                            ) : null,
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Reading Pane */}
                        <div className="lg:col-span-7">
                            {selectedArticle ? (
                                <article className="bg-card border-border sticky top-4 rounded-md border p-5 space-y-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex flex-wrap items-center gap-1.5 text-xs">
                                            <span className="bg-muted rounded px-2 py-0.5 font-medium">
                                                {t(`news.cat.${selectedArticle.category}`)}
                                            </span>
                                            {selectedArticle.country_code && (
                                                <span className="border-border rounded border px-1.5 py-0.5 text-[11px]">
                                                    {selectedArticle.country_code}
                                                </span>
                                            )}
                                            {renderImpactBadge(selectedArticle.impact_tag)}
                                            {selectedArticle.is_demo && <DemoBadge />}
                                        </div>
                                        <span className="text-muted-foreground num text-xs">
                                            {formatDateTime(selectedArticle.published_at, locale, settings?.timezone)}
                                        </span>
                                    </div>

                                    <h2 className="text-base font-semibold leading-snug">{selectedArticle.title}</h2>
                                    <div className="text-muted-foreground flex items-center justify-between text-xs">
                                        <span>{selectedArticle.source_name}</span>
                                        {selectedArticle.url && (
                                            <a
                                                href={selectedArticle.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                                            >
                                                <span>{t('news.open_source')}</span>
                                                <ExternalLink className="h-3 w-3" />
                                            </a>
                                        )}
                                    </div>

                                    <div className="border-border bg-muted/25 rounded border p-3 text-xs leading-relaxed">
                                        {selectedArticle.summary}
                                    </div>

                                    {selectedArticle.body && (
                                        <div className="text-foreground space-y-2 whitespace-pre-line text-xs leading-relaxed">
                                            {selectedArticle.body}
                                        </div>
                                    )}

                                    {selectedArticle.instruments.length > 0 && (
                                        <div className="border-border space-y-2 border-t pt-3">
                                            <h3 className="text-muted-foreground text-xs font-semibold">{t('news.tagged_tickers')}</h3>
                                            <div className="flex flex-wrap gap-2">
                                                {selectedArticle.instruments.map((inst) => (
                                                    <Link
                                                        key={inst.id}
                                                        href={`/stocks/${inst.exchange_code}/${inst.symbol}`}
                                                        className="border-border bg-muted/30 hover:bg-muted/60 inline-flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs transition-colors"
                                                    >
                                                        <span className="font-semibold">
                                                            {inst.symbol} · {inst.exchange_code}
                                                        </span>
                                                        {inst.close && (
                                                            <span className="num text-muted-foreground">
                                                                {formatPrice(inst.close, inst.currency, locale)}
                                                            </span>
                                                        )}
                                                        <ChangeBadge changePct={inst.change_pct} />
                                                    </Link>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </article>
                            ) : (
                                <EmptyState icon={Newspaper} title={t('news.empty.select')} />
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* Admin Editorial Publish Dialog */}
            <Dialog open={publishOpen} onOpenChange={setPublishOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('news.publish_btn')}</DialogTitle>
                        <DialogDescription>{t('news.publish_desc')}</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitPublish} className="space-y-3 text-xs">
                        <div>
                            <Label className="text-xs">{t('news.field.title')}</Label>
                            <Input value={pubTitle} onChange={(e) => setPubTitle(e.target.value)} required className="mt-1 h-8 text-xs" />
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <Label className="text-xs">{t('news.field.source')}</Label>
                                <Input value={pubSource} onChange={(e) => setPubSource(e.target.value)} required className="mt-1 h-8 text-xs" />
                            </div>
                            <div>
                                <Label className="text-xs">{t('news.field.category')}</Label>
                                <Select value={pubCategory} onValueChange={setPubCategory}>
                                    <SelectTrigger className="mt-1 h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {categories.map((c) => (
                                            <SelectItem key={c} value={c}>
                                                {t(`news.cat.${c}`)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                            <div>
                                <Label className="text-xs">{t('news.field.country')}</Label>
                                <Select value={pubCountry} onValueChange={setPubCountry}>
                                    <SelectTrigger className="mt-1 h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="NONE">—</SelectItem>
                                        {countries.map((c) => (
                                            <SelectItem key={c} value={c}>
                                                {c}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">{t('news.field.impact')}</Label>
                                <Select value={pubImpact} onValueChange={setPubImpact}>
                                    <SelectTrigger className="mt-1 h-8 text-xs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {impacts.map((imp) => (
                                            <SelectItem key={imp} value={imp}>
                                                {t(`news.impact.${imp}`)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">{t('news.field.ticker')}</Label>
                                <Select value={pubInstrumentId} onValueChange={setPubInstrumentId}>
                                    <SelectTrigger className="mt-1 h-8 text-xs">
                                        <SelectValue placeholder="—" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {instruments.map((inst) => (
                                            <SelectItem key={inst.id} value={String(inst.id)}>
                                                {inst.symbol} · {inst.exchange_code}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div>
                            <Label className="text-xs">{t('news.field.summary')}</Label>
                            <textarea
                                value={pubSummary}
                                onChange={(e) => setPubSummary(e.target.value)}
                                required
                                rows={2}
                                className="border-input bg-background mt-1 w-full rounded-md border px-2.5 py-1.5 text-xs"
                            />
                        </div>
                        <div>
                            <Label className="text-xs">{t('news.field.body')}</Label>
                            <textarea
                                value={pubBody}
                                onChange={(e) => setPubBody(e.target.value)}
                                rows={3}
                                className="border-input bg-background mt-1 w-full rounded-md border px-2.5 py-1.5 text-xs"
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" size="sm" onClick={() => setPublishOpen(false)}>
                                {t('app.cancel')}
                            </Button>
                            <Button type="submit" size="sm" disabled={publishing}>
                                {t('news.publish_btn')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
