import { useT } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';

export function DemoBanner() {
    const { demoMode } = usePage<SharedData>().props;
    const { t } = useT();

    if (!demoMode) {
        return null;
    }

    return (
        <div className="border-b border-[var(--nadi-lavender)]/40 bg-[var(--nadi-lavender)]/15 px-4 py-1 text-xs text-[var(--nadi-lavender-ink)]">
            <div className="mx-auto flex max-w-screen-2xl items-center justify-center gap-2 text-center font-medium">
                <Info className="h-3.5 w-3.5 shrink-0" />
                <span>{t('app.demo_banner')}</span>
            </div>
        </div>
    );
}
