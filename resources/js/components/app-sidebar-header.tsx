import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useAppearance } from '@/hooks/use-appearance';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Keyboard, Monitor, Moon, PanelRight, Search, Sun } from 'lucide-react';

interface AppSidebarHeaderProps {
    breadcrumbs?: BreadcrumbItemType[];
    onOpenPalette: () => void;
    onOpenShortcuts: () => void;
    hasAside?: boolean;
    onOpenAsideMobile?: () => void;
}

export function AppSidebarHeader({
    breadcrumbs = [],
    onOpenPalette,
    onOpenShortcuts,
    hasAside = false,
    onOpenAsideMobile,
}: AppSidebarHeaderProps) {
    const { t } = useT();
    const { settings } = usePage<SharedData>().props;
    const { appearance, updateAppearance } = useAppearance(settings?.appearance);

    const setTheme = (mode: 'light' | 'dark' | 'system') => {
        updateAppearance(mode);
        router.patch('/settings/preferences', { appearance: mode }, { preserveScroll: true });
    };

    const ThemeIcon = appearance === 'dark' ? Moon : appearance === 'light' ? Sun : Monitor;

    return (
        <header className="border-border bg-card/80 sticky top-0 z-20 flex h-14 shrink-0 items-center justify-between gap-2 border-b px-4 backdrop-blur-xs">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <div className="min-w-0 truncate">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>

            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={onOpenPalette}
                    className="border-input bg-background text-muted-foreground hover:border-foreground/30 flex h-9 min-w-[40px] items-center justify-between gap-3 rounded-md border px-2.5 text-xs transition-colors sm:w-64 md:w-72"
                    aria-label={t('topbar.search_placeholder')}
                >
                    <span className="flex min-w-0 items-center gap-2">
                        <Search className="h-3.5 w-3.5 shrink-0" />
                        <span className="hidden truncate sm:inline">{t('topbar.search_placeholder')}</span>
                    </span>
                    <kbd className="border-border bg-muted hidden rounded border px-1.5 py-0.5 font-mono text-[10px] sm:inline">Ctrl+K</kbd>
                </button>

                {hasAside && onOpenAsideMobile && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="h-9 w-9 xl:hidden"
                        onClick={onOpenAsideMobile}
                        title={t('topbar.context_panel')}
                    >
                        <PanelRight className="h-4 w-4" />
                    </Button>
                )}

                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="hidden h-9 w-9 sm:inline-flex"
                    onClick={onOpenShortcuts}
                    title={t('topbar.shortcuts')}
                >
                    <Keyboard className="h-4 w-4" />
                </Button>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button type="button" variant="ghost" size="icon" className="h-9 w-9" aria-label="Theme">
                            <ThemeIcon className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => setTheme('light')}>
                            <Sun className="mr-2 h-4 w-4" />
                            <span>{t('topbar.theme.light')}</span>
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => setTheme('dark')}>
                            <Moon className="mr-2 h-4 w-4" />
                            <span>{t('topbar.theme.dark')}</span>
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => setTheme('system')}>
                            <Monitor className="mr-2 h-4 w-4" />
                            <span>{t('topbar.theme.system')}</span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
