import { DemoBadge } from '@/components/nadi/demo-badge';
import { MetricCard } from '@/components/nadi/metric-card';
import AdminLayout from '@/layouts/admin-layout';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { Head } from '@inertiajs/react';

interface AdminDashboardProps {
    stats: {
        users: number;
        instruments: number;
        indices: number;
        daily_bars: number;
        audit_logs: number;
    };
    dataSources: Array<{
        id: number;
        code: string;
        name: string;
        type: string;
        is_enabled: boolean;
        is_demo: boolean;
        last_success_at: string | null;
        last_failure_at: string | null;
    }>;
    latestRefresh: string | null;
    systemHealth?: {
        version: string;
        php_version: string;
        environment: string;
        db_driver: string;
        session_driver: string;
        cache_driver: string;
        queue_driver: string;
        demo_mode: boolean;
        csp_enabled: boolean;
        vite_manifest_present: boolean;
    };
}

export default function AdminDashboard({ stats, dataSources, latestRefresh, systemHealth }: AdminDashboardProps) {
    const { t, locale } = useT();

    return (
        <AdminLayout breadcrumbs={[{ title: t('admin.nav.dashboard'), href: '/admin' }]}>
            <Head title={t('admin.nav.dashboard')} />

            <div className="space-y-5">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <MetricCard label={t('admin.stats.users')} value={formatNumber(stats.users, locale, 0, 0)} />
                    <MetricCard label={t('admin.stats.instruments')} value={formatNumber(stats.instruments, locale, 0, 0)} />
                    <MetricCard label={t('admin.stats.indices')} value={formatNumber(stats.indices, locale, 0, 0)} />
                    <MetricCard label={t('admin.stats.daily_bars')} value={formatNumber(stats.daily_bars, locale, 0, 0)} />
                    <MetricCard label={t('admin.stats.audit_logs')} value={formatNumber(stats.audit_logs, locale, 0, 0)} />
                </div>

                <div className="bg-card border-border rounded-md border p-4">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold">{t('admin.nav.data_sources')}</h2>
                        <span className="text-muted-foreground num text-xs">
                            Quote Refresh: {latestRefresh ? formatDateTime(latestRefresh, locale, 'Asia/Jakarta') : '—'}
                        </span>
                    </div>

                    <div className="divide-border divide-y text-xs">
                        {dataSources.map((src) => (
                            <div key={src.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                                <div className="flex items-center gap-2">
                                    <span className="font-semibold">{src.name}</span>
                                    <span className="text-muted-foreground font-mono">({src.code})</span>
                                    {src.is_demo && <DemoBadge />}
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="text-muted-foreground num">
                                        {src.last_success_at ? formatDateTime(src.last_success_at, locale, 'Asia/Jakarta') : '—'}
                                    </span>
                                    <span
                                        className={
                                            src.is_enabled
                                                ? 'bg-[var(--nadi-gain-bg)] text-[var(--nadi-gain-ink)] rounded px-2 py-0.5 font-medium'
                                                : 'bg-muted text-muted-foreground rounded px-2 py-0.5 font-medium'
                                        }
                                    >
                                        {src.is_enabled ? t('admin.sources.enabled') : t('admin.sources.disabled')}
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {systemHealth && (
                    <div className="bg-card border-border rounded-md border p-4">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold">{t('admin.health.title')}</h2>
                            <span className="bg-muted rounded px-2 py-0.5 font-mono text-[11px] font-semibold">
                                v{systemHealth.version} · {systemHealth.environment}
                            </span>
                        </div>
                        <div className="grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                            <div className="border-border rounded border p-2.5">
                                <span className="text-muted-foreground block text-[11px]">{t('admin.health.runtime')}</span>
                                <span className="font-mono font-medium">PHP {systemHealth.php_version} · {systemHealth.db_driver}</span>
                            </div>
                            <div className="border-border rounded border p-2.5">
                                <span className="text-muted-foreground block text-[11px]">{t('admin.health.drivers')}</span>
                                <span className="font-mono font-medium">
                                    {systemHealth.session_driver} / {systemHealth.cache_driver} / {systemHealth.queue_driver}
                                </span>
                            </div>
                            <div className="border-border rounded border p-2.5">
                                <span className="text-muted-foreground block text-[11px]">{t('admin.health.csp')}</span>
                                <span className="font-mono font-medium">
                                    {systemHealth.csp_enabled ? t('admin.health.csp_active') : 'Off'}
                                </span>
                            </div>
                            <div className="border-border rounded border p-2.5">
                                <span className="text-muted-foreground block text-[11px]">{t('admin.health.assets')}</span>
                                <span className="font-mono font-medium">
                                    {systemHealth.vite_manifest_present ? t('admin.health.assets_ready') : 'Missing'}
                                </span>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
