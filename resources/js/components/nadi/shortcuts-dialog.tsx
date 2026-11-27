import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';

interface ShortcutsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function ShortcutsDialog({ open, onOpenChange }: ShortcutsDialogProps) {
    const { t } = useT();

    const rows = [
        { keys: ['Ctrl / ⌘ + K', '/'], label: t('shortcuts.open_palette') },
        { keys: ['Ctrl / ⌘ + B'], label: t('shortcuts.toggle_sidebar') },
        { keys: ['?'], label: t('shortcuts.show_help') },
        { keys: ['G', 'O'], label: t('shortcuts.go_overview') },
        { keys: ['G', 'M'], label: t('shortcuts.go_markets') },
        { keys: ['G', 'E'], label: t('shortcuts.go_explorer') },
        { keys: ['G', 'S'], label: t('shortcuts.go_settings') },
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('shortcuts.title')}</DialogTitle>
                    <DialogDescription>{t('shortcuts.desc')}</DialogDescription>
                </DialogHeader>
                <div className="divide-border divide-y text-sm">
                    {rows.map((row) => (
                        <div key={row.label} className="flex items-center justify-between py-2.5">
                            <span className="text-foreground text-xs">{row.label}</span>
                            <div className="flex items-center gap-1">
                                {row.keys.map((k) => (
                                    <kbd
                                        key={k}
                                        className="border-border bg-muted text-muted-foreground rounded border px-1.5 py-0.5 font-mono text-[11px]"
                                    >
                                        {k}
                                    </kbd>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
