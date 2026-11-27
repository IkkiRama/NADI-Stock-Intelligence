import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type MarketClockStatus } from '@/types';

export function MarketStatusBadge({ status, className }: { status: MarketClockStatus; className?: string }) {
    const { t } = useT();

    const styleMap: Record<MarketClockStatus['state'], string> = {
        open: 'border-[var(--nadi-sage)] bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)]',
        break: 'border-[var(--nadi-ice)] bg-[var(--nadi-ice-bg)] text-[var(--nadi-ice-ink)]',
        closed: 'border-border bg-muted text-muted-foreground',
        weekend: 'border-border bg-muted text-muted-foreground',
        holiday: 'border-[var(--nadi-coral)] bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)]',
    };

    const dotMap: Record<MarketClockStatus['state'], string> = {
        open: 'bg-[var(--nadi-gain-ink)]',
        break: 'bg-[var(--nadi-ice-ink)]',
        closed: 'bg-muted-foreground',
        weekend: 'bg-muted-foreground',
        holiday: 'bg-[var(--nadi-loss-ink)]',
    };

    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded border px-1.5 py-0.5 text-[11px] font-medium', styleMap[status.state], className)}>
            <span className={cn('h-1.5 w-1.5 rounded-full', dotMap[status.state])} aria-hidden="true" />
            <span>{status.holiday_name ? `${t(`clock.${status.state}`)} · ${status.holiday_name}` : t(`clock.${status.state}`)}</span>
        </span>
    );
}
