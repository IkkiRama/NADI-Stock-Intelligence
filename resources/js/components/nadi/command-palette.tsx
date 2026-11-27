import { ChangeBadge } from '@/components/nadi/change-badge';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { useAppearance } from '@/hooks/use-appearance';
import { formatPrice } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Command } from 'cmdk';
import { ArrowRight, Compass, Globe2, History, Languages, LayoutGrid, Monitor, Moon, Search, SlidersHorizontal, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';

interface SearchResponse {
    query: string;
    recent_views: Array<{ kind: string; title: string; subtitle: string; href: string }>;
    recent_searches: string[];
    stocks: Array<{
        id: number;
        symbol: string;
        name: string;
        exchange_code: string;
        currency: string;
        close: string | null;
        change_pct: string | null;
        href: string;
    }>;
    indices: Array<{
        id: number;
        code: string;
        name: string;
        currency: string;
        close: string | null;
        change_pct: string | null;
        href: string;
    }>;
    exchanges: Array<{
        id: number;
        code: string;
        name: string;
        href: string;
    }>;
    pages: Array<{
        title: string;
        href: string;
        phase: number | null;
        status: string;
    }>;
}

interface CommandPaletteProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function CommandPalette({ open, onOpenChange }: CommandPaletteProps) {
    const { t, locale } = useT();
    const { settings } = usePage<SharedData>().props;
    const { updateAppearance } = useAppearance(settings?.appearance);

    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const [data, setData] = useState<SearchResponse | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            setError(false);
            try {
                const res = await fetch(`/search?q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!res.ok) {
                    throw new Error('Search request failed');
                }
                const json = (await res.json()) as SearchResponse;
                setData(json);
            } catch (err) {
                if ((err as Error).name !== 'AbortError') {
                    setError(true);
                }
            } finally {
                setLoading(false);
            }
        }, query ? 200 : 0);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, query]);

    const go = (href: string) => {
        onOpenChange(false);
        router.visit(href);
    };

    const updatePreference = (payload: Record<string, string>) => {
        onOpenChange(false);
        if (payload.appearance) {
            updateAppearance(payload.appearance as 'light' | 'dark' | 'system');
        }
        router.patch('/settings/preferences', payload, {
            preserveScroll: true,
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="overflow-hidden p-0 sm:max-w-xl">
                <DialogTitle className="sr-only">Command Palette</DialogTitle>
                <DialogDescription className="sr-only">{t('palette.placeholder')}</DialogDescription>

                <Command shouldFilter={false} className="bg-popover text-popover-foreground flex flex-col">
                    <div className="border-border flex items-center border-b px-3">
                        <Search className="text-muted-foreground mr-2 h-4 w-4 shrink-0" />
                        <Command.Input
                            value={query}
                            onValueChange={setQuery}
                            placeholder={t('palette.placeholder')}
                            className="placeholder:text-muted-foreground h-11 w-full bg-transparent text-sm outline-none"
                        />
                    </div>

                    <Command.List className="max-h-[420px] overflow-y-auto p-2 text-sm">
                        {loading && <div className="text-muted-foreground px-3 py-4 text-xs">{t('app.loading')}</div>}
                        {error && <div className="text-destructive px-3 py-4 text-xs">{t('palette.error')}</div>}

                        {!loading && !error && data && (
                            <>
                                {data.stocks.length === 0 &&
                                    data.indices.length === 0 &&
                                    data.exchanges.length === 0 &&
                                    data.pages.length === 0 &&
                                    data.recent_views.length === 0 && (
                                        <div className="text-muted-foreground px-3 py-6 text-center text-xs">{t('palette.empty')}</div>
                                    )}

                                {data.recent_views.length > 0 && (
                                    <Command.Group heading={t('palette.group.recent')} className="mb-2">
                                        {data.recent_views.map((item) => (
                                            <Command.Item
                                                key={`${item.kind}-${item.href}`}
                                                onSelect={() => go(item.href)}
                                                className="aria-selected:bg-accent flex cursor-pointer items-center justify-between rounded px-2.5 py-2"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <History className="text-muted-foreground h-3.5 w-3.5" />
                                                    <span className="font-medium">{item.title}</span>
                                                    <span className="text-muted-foreground truncate text-xs">{item.subtitle}</span>
                                                </div>
                                                <ArrowRight className="text-muted-foreground h-3.5 w-3.5" />
                                            </Command.Item>
                                        ))}
                                    </Command.Group>
                                )}

                                {data.recent_searches.length > 0 && (
                                    <Command.Group heading={t('palette.group.searches')} className="mb-2">
                                        {data.recent_searches.map((term) => (
                                            <Command.Item
                                                key={`search-${term}`}
                                                onSelect={() => setQuery(term)}
                                                className="aria-selected:bg-accent flex cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-xs"
                                            >
                                                <Search className="text-muted-foreground h-3 w-3" />
                                                <span>{term}</span>
                                            </Command.Item>
                                        ))}
                                    </Command.Group>
                                )}

                                {data.stocks.length > 0 && (
                                    <Command.Group heading={t('palette.group.stocks')} className="mb-2">
                                        {data.stocks.map((stock) => (
                                            <Command.Item
                                                key={`stock-${stock.id}`}
                                                onSelect={() => go(stock.href)}
                                                className="aria-selected:bg-accent flex cursor-pointer items-center justify-between rounded px-2.5 py-2"
                                            >
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <span className="bg-muted rounded px-1.5 py-0.5 text-[11px] font-semibold">{stock.exchange_code}</span>
                                                    <span className="font-semibold">{stock.symbol}</span>
                                                    <span className="text-muted-foreground truncate text-xs">{stock.name}</span>
                                                </div>
                                                <div className="ml-3 flex shrink-0 items-center gap-2">
                                                    {stock.close && (
                                                        <span className="num text-xs font-medium">{formatPrice(stock.close, stock.currency, locale)}</span>
                                                    )}
                                                    <ChangeBadge changePct={stock.change_pct} />
                                                </div>
                                            </Command.Item>
                                        ))}
                                    </Command.Group>
                                )}

                                {data.indices.length > 0 && (
                                    <Command.Group heading={t('palette.group.indices')} className="mb-2">
                                        {data.indices.map((idx) => (
                                            <Command.Item
                                                key={`idx-${idx.id}`}
                                                onSelect={() => go(idx.href)}
                                                className="aria-selected:bg-accent flex cursor-pointer items-center justify-between rounded px-2.5 py-2"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Globe2 className="text-muted-foreground h-3.5 w-3.5" />
                                                    <span className="font-semibold">{idx.code}</span>
                                                    <span className="text-muted-foreground text-xs">{idx.name}</span>
                                                </div>
                                                <ChangeBadge changePct={idx.change_pct} />
                                            </Command.Item>
                                        ))}
                                    </Command.Group>
                                )}

                                {data.exchanges.length > 0 && (
                                    <Command.Group heading={t('palette.group.exchanges')} className="mb-2">
                                        {data.exchanges.map((ex) => (
                                            <Command.Item
                                                key={`ex-${ex.id}`}
                                                onSelect={() => go(ex.href)}
                                                className="aria-selected:bg-accent flex cursor-pointer items-center justify-between rounded px-2.5 py-2"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Compass className="text-muted-foreground h-3.5 w-3.5" />
                                                    <span className="font-medium">{ex.code}</span>
                                                    <span className="text-muted-foreground text-xs">{ex.name}</span>
                                                </div>
                                            </Command.Item>
                                        ))}
                                    </Command.Group>
                                )}

                                {data.pages.length > 0 && (
                                    <Command.Group heading={t('palette.group.pages')} className="mb-2">
                                        {data.pages.map((p) => (
                                            <Command.Item
                                                key={`page-${p.href}`}
                                                onSelect={() => go(p.href)}
                                                className="aria-selected:bg-accent flex cursor-pointer items-center justify-between rounded px-2.5 py-2"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <LayoutGrid className="text-muted-foreground h-3.5 w-3.5" />
                                                    <span>{p.title}</span>
                                                </div>
                                                {p.status === 'planned' && p.phase && (
                                                    <span className="text-muted-foreground text-[11px]">{t('app.phase_badge', { phase: p.phase })}</span>
                                                )}
                                            </Command.Item>
                                        ))}
                                    </Command.Group>
                                )}
                            </>
                        )}

                        <Command.Group heading={t('palette.group.actions')} className="border-border mt-1 border-t pt-2">
                            <Command.Item
                                onSelect={() => updatePreference({ appearance: 'light' })}
                                className="aria-selected:bg-accent flex cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-xs"
                            >
                                <Sun className="h-3.5 w-3.5" />
                                <span>{t('palette.action.theme_light')}</span>
                            </Command.Item>
                            <Command.Item
                                onSelect={() => updatePreference({ appearance: 'dark' })}
                                className="aria-selected:bg-accent flex cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-xs"
                            >
                                <Moon className="h-3.5 w-3.5" />
                                <span>{t('palette.action.theme_dark')}</span>
                            </Command.Item>
                            <Command.Item
                                onSelect={() => updatePreference({ appearance: 'system' })}
                                className="aria-selected:bg-accent flex cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-xs"
                            >
                                <Monitor className="h-3.5 w-3.5" />
                                <span>{t('palette.action.theme_system')}</span>
                            </Command.Item>
                            <Command.Item
                                onSelect={() => updatePreference({ locale: locale === 'id' ? 'en' : 'id' })}
                                className="aria-selected:bg-accent flex cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-xs"
                            >
                                <Languages className="h-3.5 w-3.5" />
                                <span>{locale === 'id' ? t('palette.action.lang_en') : t('palette.action.lang_id')}</span>
                            </Command.Item>
                            <Command.Item
                                onSelect={() => updatePreference({ density: settings?.density === 'compact' ? 'comfortable' : 'compact' })}
                                className="aria-selected:bg-accent flex cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-xs"
                            >
                                <SlidersHorizontal className="h-3.5 w-3.5" />
                                <span>
                                    {settings?.density === 'compact' ? t('palette.action.density_comfortable') : t('palette.action.density_compact')}
                                </span>
                            </Command.Item>
                            <Command.Item
                                onSelect={() => updatePreference({ analysis_mode: settings?.analysis_mode === 'pro' ? 'simple' : 'pro' })}
                                className="aria-selected:bg-accent flex cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-xs"
                            >
                                <SlidersHorizontal className="h-3.5 w-3.5" />
                                <span>
                                    {settings?.analysis_mode === 'pro' ? t('palette.action.mode_simple') : t('palette.action.mode_pro')}
                                </span>
                            </Command.Item>
                        </Command.Group>
                    </Command.List>
                </Command>
            </DialogContent>
        </Dialog>
    );
}
