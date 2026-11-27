import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';

interface FlagRow {
    id: number;
    key: string;
    enabled: boolean;
    description: string | null;
}

export default function AdminFeatureFlags({ flags }: { flags: FlagRow[] }) {
    const { t } = useT();

    const toggle = (flag: FlagRow) => {
        router.patch(`/admin/feature-flags/${flag.id}`, { enabled: !flag.enabled }, { preserveScroll: true });
    };

    return (
        <AdminLayout breadcrumbs={[{ title: t('admin.nav.feature_flags'), href: '/admin/feature-flags' }]}>
            <Head title={t('admin.nav.feature_flags')} />

            <div className="space-y-3">
                {flags.map((flag) => (
                    <div key={flag.id} className="bg-card border-border flex items-center justify-between gap-4 rounded-md border p-4">
                        <div>
                            <div className="font-mono text-xs font-semibold">{flag.key}</div>
                            {flag.description && <p className="text-muted-foreground mt-1 text-xs">{flag.description}</p>}
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant={flag.enabled ? 'default' : 'outline'}
                            onClick={() => toggle(flag)}
                            className="text-xs"
                        >
                            {flag.enabled ? t('admin.sources.enabled') : t('admin.sources.disabled')}
                        </Button>
                    </div>
                ))}
            </div>
        </AdminLayout>
    );
}
