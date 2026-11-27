import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Clock3 } from 'lucide-react';

interface PlannedModuleProps {
    module: {
        slug: string;
        phase: number;
        title_key: string;
        description_key: string;
    };
}

export default function PlannedModule({ module }: PlannedModuleProps) {
    const { t } = useT();
    const title = t(module.title_key);

    const breadcrumbs: BreadcrumbItem[] = [
        { title, href: `/segera/${module.slug}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="mx-auto max-w-2xl py-6">
                <div className="bg-card border-border rounded-md border p-6">
                    <div className="flex items-center justify-between gap-2">
                        <span className="border-border bg-muted text-muted-foreground inline-flex items-center gap-1.5 rounded border px-2 py-0.5 text-xs font-medium">
                            <Clock3 className="h-3.5 w-3.5" />
                            <span>{t('app.phase_badge', { phase: module.phase })}</span>
                        </span>
                        <span className="text-muted-foreground text-xs">{t('app.coming_soon')}</span>
                    </div>

                    <h1 className="mt-4 text-xl font-semibold tracking-tight">{title}</h1>
                    <p className="text-muted-foreground mt-2 text-sm leading-relaxed">{t(module.description_key)}</p>
                    <p className="text-muted-foreground mt-4 border-t pt-4 text-xs">
                        {t('planned.module_notice', { phase: module.phase })}
                    </p>

                    <div className="mt-5">
                        <Button asChild size="sm" variant="outline">
                            <Link href="/dashboard">
                                <ArrowLeft className="mr-1.5 h-3.5 w-3.5" />
                                <span>{t('planned.back_to_overview')}</span>
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
