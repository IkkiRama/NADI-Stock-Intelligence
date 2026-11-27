import { formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { AlertTriangle, HelpCircle, Shield, ShieldAlert, ShieldCheck } from 'lucide-react';

interface SafetyBadgeProps {
    category?: string | null;
    score?: string | null;
    safeguard?: boolean;
    compact?: boolean;
    className?: string;
}

export function SafetyBadge({ category, score, safeguard = false, compact = false, className }: SafetyBadgeProps) {
    const { t, locale } = useT();
    const cat = category || 'insufficient_data';

    const meta: Record<string, { icon: typeof ShieldCheck; style: string }> = {
        low: {
            icon: ShieldCheck,
            style: 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)] border-transparent',
        },
        moderate: {
            icon: Shield,
            style: 'bg-[var(--nadi-ice-bg)] text-[var(--nadi-ice-ink)] border-transparent',
        },
        elevated: {
            icon: AlertTriangle,
            style: 'bg-[var(--nadi-lavender)]/25 text-[var(--nadi-lavender-ink)] border-transparent',
        },
        high: {
            icon: ShieldAlert,
            style: 'bg-[var(--nadi-loss-bg)] text-[var(--nadi-loss-ink)] border-transparent',
        },
        insufficient_data: {
            icon: HelpCircle,
            style: 'bg-muted text-muted-foreground border-border',
        },
    };

    const chosen = meta[cat] ?? meta.insufficient_data;
    const Icon = chosen.icon;

    return (
        <span className={cn('inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-semibold', chosen.style, className)}>
            <Icon className="h-3 w-3 shrink-0" />
            <span>{t(`safety.category.${cat}`)}</span>
            {score !== null && score !== undefined && !compact && (
                <span className="num opacity-80">({formatNumber(score, locale, 0, 0)})</span>
            )}
            {safeguard && (
                <span className="bg-[var(--nadi-loss-ink)] text-white ml-0.5 rounded px-1 py-0 text-[9px] font-bold uppercase">
                    !
                </span>
            )}
        </span>
    );
}
