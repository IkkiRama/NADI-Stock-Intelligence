import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export function DemoBadge({ className }: { className?: string }) {
    const { t } = useT();

    return (
        <span
            className={cn(
                'inline-flex items-center rounded border border-[var(--nadi-lavender)]/60 bg-[var(--nadi-lavender)]/20 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-[var(--nadi-lavender-ink)] uppercase',
                className,
            )}
        >
            {t('app.demo_badge')}
        </span>
    );
}
