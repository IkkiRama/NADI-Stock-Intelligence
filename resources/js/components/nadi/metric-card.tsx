import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface MetricCardProps {
    label: string;
    value: ReactNode;
    subtext?: ReactNode;
    badge?: ReactNode;
    className?: string;
}

export function MetricCard({ label, value, subtext, badge, className }: MetricCardProps) {
    return (
        <div className={cn('bg-card border-border rounded-md border p-[var(--nadi-card-p)]', className)}>
            <div className="flex items-center justify-between gap-2">
                <span className="text-muted-foreground text-xs font-medium tracking-tight">{label}</span>
                {badge}
            </div>
            <div className="num mt-1.5 text-xl font-semibold tracking-tight">{value}</div>
            {subtext && <div className="text-muted-foreground mt-1 text-xs">{subtext}</div>}
        </div>
    );
}
