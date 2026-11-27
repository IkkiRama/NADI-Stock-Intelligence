import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowRight, Clock3 } from 'lucide-react';

interface ModuleEmptyStateProps {
    title: string;
    description: string;
    phase: number;
    slug?: string;
    className?: string;
}

export function ModuleEmptyState({ title, description, phase, slug, className }: ModuleEmptyStateProps) {
    const { t } = useT();

    return (
        <div className={cn('border-border bg-card flex flex-col justify-between rounded-md border p-[var(--nadi-card-p)]', className)}>
            <div>
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold">{title}</h3>
                    <span className="border-border bg-muted text-muted-foreground inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-medium">
                        <Clock3 className="h-3 w-3" />
                        {t('app.phase_badge', { phase })}
                    </span>
                </div>
                <p className="text-muted-foreground mt-2 text-xs leading-relaxed">{description}</p>
            </div>
            {slug && (
                <div className="mt-3 pt-2">
                    <Link
                        href={`/segera/${slug}`}
                        className="text-foreground hover:text-muted-foreground inline-flex items-center gap-1 text-xs font-medium underline-offset-4 hover:underline"
                    >
                        <span>{t('app.coming_soon')}</span>
                        <ArrowRight className="h-3 w-3" />
                    </Link>
                </div>
            )}
        </div>
    );
}
