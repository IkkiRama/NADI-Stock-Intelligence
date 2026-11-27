import { EmptyState } from '@/components/nadi/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';
import { ScrollText, Search } from 'lucide-react';
import { useState } from 'react';

interface AuditRow {
    id: number;
    action: string;
    auditable_type: string | null;
    auditable_id: number | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip: string | null;
    created_at: string;
    user: { id: number; name: string; email: string } | null;
}

interface AuditLogsProps {
    logs: {
        data: AuditRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { action: string };
}

export default function AdminAuditLogs({ logs, filters }: AuditLogsProps) {
    const { t, locale } = useT();
    const [actionFilter, setActionFilter] = useState(filters.action || '');

    return (
        <AdminLayout breadcrumbs={[{ title: t('admin.nav.audit_logs'), href: '/admin/audit-logs' }]}>
            <Head title={t('admin.nav.audit_logs')} />

            <div className="space-y-4">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/admin/audit-logs', actionFilter ? { action: actionFilter } : {}, { preserveState: true });
                    }}
                    className="flex items-center gap-2"
                >
                    <div className="relative max-w-sm flex-1">
                        <Search className="text-muted-foreground absolute top-2.5 left-2.5 h-4 w-4" />
                        <Input
                            value={actionFilter}
                            onChange={(e) => setActionFilter(e.target.value)}
                            placeholder="Filter action (mis. user.updated)..."
                            className="h-9 pl-8 text-xs"
                        />
                    </div>
                    <Button type="submit" size="sm" className="h-9">
                        {t('app.filter')}
                    </Button>
                </form>

                {logs.data.length === 0 ? (
                    <EmptyState icon={ScrollText} title={t('admin.audit.empty')} />
                ) : (
                    <div className="bg-card border-border overflow-x-auto rounded-md border">
                        <table className="w-full border-collapse text-xs">
                            <thead>
                                <tr className="border-border bg-muted/50 border-b text-left">
                                    <th className="px-3 py-2 font-semibold">Waktu</th>
                                    <th className="px-3 py-2 font-semibold">Aktor</th>
                                    <th className="px-3 py-2 font-semibold">Aksi</th>
                                    <th className="px-3 py-2 font-semibold">Target</th>
                                    <th className="px-3 py-2 font-semibold">Perubahan</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {logs.data.map((log) => (
                                    <tr key={log.id}>
                                        <td className="num text-muted-foreground px-3 py-2.5 whitespace-nowrap">
                                            {formatDateTime(log.created_at, locale, 'Asia/Jakarta')}
                                        </td>
                                        <td className="px-3 py-2.5">{log.user?.email ?? 'system'}</td>
                                        <td className="px-3 py-2.5 font-mono font-semibold">{log.action}</td>
                                        <td className="px-3 py-2.5 font-mono">
                                            {log.auditable_type ? `${log.auditable_type}#${log.auditable_id}` : '—'}
                                        </td>
                                        <td className="text-muted-foreground max-w-md truncate px-3 py-2.5 font-mono text-[11px]">
                                            {JSON.stringify(log.new_values ?? {})}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
