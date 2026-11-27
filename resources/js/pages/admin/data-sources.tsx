import { DemoBadge } from '@/components/nadi/demo-badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';

interface DataSourceRow {
    id: number;
    code: string;
    name: string;
    type: string;
    datasets: string[];
    is_enabled: boolean;
    is_demo: boolean;
    attribution: string | null;
    license_notes: string | null;
    last_success_at: string | null;
}

export default function AdminDataSources({ dataSources }: { dataSources: DataSourceRow[] }) {
    const { t, locale } = useT();

    const toggle = (src: DataSourceRow) => {
        router.patch(`/admin/data-sources/${src.id}`, { is_enabled: !src.is_enabled }, { preserveScroll: true });
    };

    return (
        <AdminLayout breadcrumbs={[{ title: t('admin.nav.data_sources'), href: '/admin/data-sources' }]}>
            <Head title={t('admin.nav.data_sources')} />

            <div className="space-y-3">
                {dataSources.map((src) => (
                    <div key={src.id} className="bg-card border-border flex flex-wrap items-start justify-between gap-4 rounded-md border p-4">
                        <div className="space-y-1">
                            <div className="flex items-center gap-2">
                                <h2 className="text-sm font-semibold">{src.name}</h2>
                                <span className="bg-muted rounded px-1.5 py-0.5 font-mono text-[11px]">{src.code}</span>
                                {src.is_demo && <DemoBadge />}
                            </div>
                            {src.attribution && <p className="text-xs font-medium">{src.attribution}</p>}
                            {src.license_notes && <p className="text-muted-foreground max-w-2xl text-xs">{src.license_notes}</p>}
                            <div className="text-muted-foreground flex flex-wrap gap-3 pt-1 text-[11px]">
                                <span>Datasets: {src.datasets.join(', ')}</span>
                                <span>Last sync: {src.last_success_at ? formatDateTime(src.last_success_at, locale, 'Asia/Jakarta') : '—'}</span>
                            </div>
                        </div>

                        <Button
                            type="button"
                            size="sm"
                            variant={src.is_enabled ? 'default' : 'outline'}
                            onClick={() => toggle(src)}
                            className="text-xs"
                        >
                            {src.is_enabled ? t('admin.sources.enabled') : t('admin.sources.disabled')}
                        </Button>
                    </div>
                ))}
            </div>
        </AdminLayout>
    );
}
