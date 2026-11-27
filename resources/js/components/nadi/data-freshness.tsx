import { DemoBadge } from '@/components/nadi/demo-badge';
import { formatDate } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

interface DataFreshnessProps {
    asOf?: string | null;
    source?: string | null;
    isDemo?: boolean;
    className?: string;
}

export function DataFreshness({ asOf, source, isDemo = false, className }: DataFreshnessProps) {
    const { t, locale } = useT();
    const { settings } = usePage<SharedData>().props;

    return (
        <div className={cn('text-muted-foreground flex flex-wrap items-center gap-2 text-xs', className)}>
            {isDemo && <DemoBadge />}
            {asOf && <span className="num">{t('app.as_of', { date: formatDate(asOf, locale, settings?.timezone) })}</span>}
            {source && <span>· {t('app.source', { source })}</span>}
        </div>
    );
}
