import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Inbox, type LucideIcon } from 'lucide-react';

interface EmptyStateProps {
    icon?: LucideIcon;
    title: string;
    description?: string;
    actionLabel?: string;
    actionHref?: string;
    onAction?: () => void;
    className?: string;
}

export function EmptyState({
    icon: Icon = Inbox,
    title,
    description,
    actionLabel,
    actionHref,
    onAction,
    className,
}: EmptyStateProps) {
    return (
        <div className={cn('border-border bg-card flex flex-col items-center justify-center rounded-md border border-dashed px-4 py-8 text-center', className)}>
            <div className="bg-muted text-muted-foreground mb-3 flex h-9 w-9 items-center justify-center rounded-md">
                <Icon className="h-4 w-4" />
            </div>
            <p className="text-sm font-medium">{title}</p>
            {description && <p className="text-muted-foreground mt-1 max-w-md text-xs leading-relaxed">{description}</p>}
            {actionLabel && actionHref && (
                <Button asChild size="sm" variant="outline" className="mt-4">
                    <Link href={actionHref}>{actionLabel}</Link>
                </Button>
            )}
            {actionLabel && onAction && !actionHref && (
                <Button size="sm" variant="outline" className="mt-4" onClick={onAction}>
                    {actionLabel}
                </Button>
            )}
        </div>
    );
}
