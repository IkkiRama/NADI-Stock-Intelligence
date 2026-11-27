import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Link } from '@inertiajs/react';
import { Database, Flag, LayoutDashboard, ScrollText, ShieldAlert, ShieldCheck, Sliders, UploadCloud, Users } from 'lucide-react';
import { type ReactNode } from 'react';

interface AdminLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

export default function AdminLayout({ children, breadcrumbs = [] }: AdminLayoutProps) {
    const { t } = useT();
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

    const nav = [
        { title: t('admin.nav.dashboard'), href: '/admin', icon: LayoutDashboard, exact: true },
        { title: t('admin.nav.users'), href: '/admin/users', icon: Users, exact: false },
        { title: t('admin.nav.data_sources'), href: '/admin/data-sources', icon: Database, exact: false },
        { title: t('admin.nav.imports'), href: '/admin/imports', icon: UploadCloud, exact: false },
        { title: t('admin.nav.scoring'), href: '/admin/scoring', icon: Sliders, exact: false },
        { title: t('admin.nav.safety_rules'), href: '/admin/safety-rules', icon: ShieldAlert, exact: false },
        { title: t('admin.nav.feature_flags'), href: '/admin/feature-flags', icon: Flag, exact: false },
        { title: t('admin.nav.audit_logs'), href: '/admin/audit-logs', icon: ScrollText, exact: false },
    ];

    return (
        <AppLayout breadcrumbs={[{ title: t('nav.admin'), href: '/admin' }, ...breadcrumbs]}>
            <div className="space-y-5">
                <div className="border-border bg-card flex flex-col gap-3 rounded-md border p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-2.5">
                        <div className="bg-[var(--nadi-lavender)]/20 text-[var(--nadi-lavender-ink)] flex h-9 w-9 items-center justify-center rounded-md">
                            <ShieldCheck className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-base font-semibold tracking-tight">{t('admin.title')}</h1>
                            <p className="text-muted-foreground text-xs">{t('admin.subtitle')}</p>
                        </div>
                    </div>
                </div>

                <nav className="border-border flex flex-wrap gap-1 border-b pb-2">
                    {nav.map((item) => {
                        const Icon = item.icon;
                        const active = item.exact ? currentPath === item.href : currentPath.startsWith(item.href);

                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                                    active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                )}
                            >
                                <Icon className="h-3.5 w-3.5" />
                                <span>{item.title}</span>
                            </Link>
                        );
                    })}
                </nav>

                <div>{children}</div>
            </div>
        </AppLayout>
    );
}
