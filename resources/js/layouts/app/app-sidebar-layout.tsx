import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { CommandPalette } from '@/components/nadi/command-palette';
import { DemoBanner } from '@/components/nadi/demo-banner';
import { ShortcutsDialog } from '@/components/nadi/shortcuts-dialog';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useAppearance } from '@/hooks/use-appearance';
import { useKeyboardShortcuts } from '@/hooks/use-keyboard-shortcuts';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { type ReactNode, useCallback, useEffect, useState } from 'react';

interface AppSidebarLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    aside?: ReactNode;
}

export default function AppSidebarLayout({ children, breadcrumbs = [], aside }: AppSidebarLayoutProps) {
    const { settings } = usePage<SharedData>().props;
    const { t } = useT();
    const [paletteOpen, setPaletteOpen] = useState(false);
    const [shortcutsOpen, setShortcutsOpen] = useState(false);
    const [mobileAsideOpen, setMobileAsideOpen] = useState(false);

    useAppearance(settings?.appearance);

    useEffect(() => {
        if (typeof document !== 'undefined' && settings?.density) {
            document.documentElement.dataset.density = settings.density;
        }
    }, [settings?.density]);

    const openPalette = useCallback(() => setPaletteOpen(true), []);
    const openShortcuts = useCallback(() => setShortcutsOpen(true), []);

    useKeyboardShortcuts({
        onOpenPalette: openPalette,
        onOpenHelp: openShortcuts,
    });

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0">
                <DemoBanner />
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    onOpenPalette={openPalette}
                    onOpenShortcuts={openShortcuts}
                    hasAside={Boolean(aside)}
                    onOpenAsideMobile={() => setMobileAsideOpen(true)}
                />

                <div className="mx-auto flex w-full max-w-screen-2xl flex-1 gap-4 p-4 md:p-6">
                    <main className="min-w-0 flex-1">{children}</main>

                    {aside && (
                        <aside className="hidden w-80 shrink-0 space-y-4 xl:block">
                            {aside}
                        </aside>
                    )}
                </div>

                {aside && (
                    <Sheet open={mobileAsideOpen} onOpenChange={setMobileAsideOpen}>
                        <SheetContent side="right" className="w-[88vw] max-w-sm overflow-y-auto">
                            <SheetHeader>
                                <SheetTitle>{t('topbar.context_panel')}</SheetTitle>
                            </SheetHeader>
                            <div className="mt-4 space-y-4">{aside}</div>
                        </SheetContent>
                    </Sheet>
                )}

                <CommandPalette open={paletteOpen} onOpenChange={setPaletteOpen} />
                <ShortcutsDialog open={shortcutsOpen} onOpenChange={setShortcutsOpen} />
            </AppContent>
        </AppShell>
    );
}
