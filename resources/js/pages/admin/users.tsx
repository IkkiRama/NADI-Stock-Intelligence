import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';

interface AdminUserRow {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    last_login_at: string | null;
    roles: string[];
}

interface UsersProps {
    users: {
        data: AdminUserRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    roles: Array<{ id: number; slug: string; name: string }>;
    filters: { q: string };
}

export default function AdminUsers({ users, roles, filters }: UsersProps) {
    const { t, locale } = useT();
    const { auth } = usePage<SharedData>().props;
    const [q, setQ] = useState(filters.q || '');

    const updateUser = (user: AdminUserRow, nextRoles: string[], nextActive: boolean) => {
        router.patch(
            `/admin/users/${user.id}`,
            {
                roles: nextRoles,
                is_active: nextActive,
            },
            { preserveScroll: true },
        );
    };

    const toggleRole = (user: AdminUserRow, slug: string, checked: boolean) => {
        const set = new Set(user.roles);
        if (checked) {
            set.add(slug);
        } else {
            set.delete(slug);
        }
        const nextRoles = Array.from(set);
        if (nextRoles.length === 0) {
            return;
        }
        updateUser(user, nextRoles, user.is_active);
    };

    return (
        <AdminLayout breadcrumbs={[{ title: t('admin.nav.users'), href: '/admin/users' }]}>
            <Head title={t('admin.nav.users')} />

            <div className="space-y-4">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/admin/users', q ? { q } : {}, { preserveState: true });
                    }}
                    className="flex items-center gap-2"
                >
                    <div className="relative max-w-sm flex-1">
                        <Search className="text-muted-foreground absolute top-2.5 left-2.5 h-4 w-4" />
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Cari nama atau email..."
                            className="h-9 pl-8 text-xs"
                        />
                    </div>
                    <Button type="submit" size="sm" className="h-9">
                        {t('app.search')}
                    </Button>
                </form>

                <div className="bg-card border-border overflow-x-auto rounded-md border">
                    <table className="w-full border-collapse text-xs">
                        <thead>
                            <tr className="border-border bg-muted/50 border-b text-left">
                                <th className="px-3 py-2 font-semibold">Pengguna</th>
                                <th className="px-3 py-2 font-semibold">{t('admin.users.roles')}</th>
                                <th className="px-3 py-2 font-semibold">Login Terakhir</th>
                                <th className="px-3 py-2 font-semibold">{t('admin.users.toggle_active')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-border divide-y">
                            {users.data.map((u) => {
                                const isSelf = auth.user?.id === u.id;
                                return (
                                    <tr key={u.id}>
                                        <td className="px-3 py-2.5">
                                            <div className="font-semibold">{u.name}</div>
                                            <div className="text-muted-foreground">{u.email}</div>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <div className="flex flex-wrap items-center gap-3">
                                                {roles.map((r) => {
                                                    const has = u.roles.includes(r.slug);
                                                    const disableSelfAdmin = isSelf && r.slug === 'admin' && has;
                                                    return (
                                                        <label key={r.slug} className="flex items-center gap-1.5">
                                                            <Checkbox
                                                                checked={has}
                                                                disabled={disableSelfAdmin}
                                                                onCheckedChange={(checked) => toggleRole(u, r.slug, Boolean(checked))}
                                                            />
                                                            <span>{r.slug}</span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                        </td>
                                        <td className="num text-muted-foreground px-3 py-2.5">
                                            {u.last_login_at ? formatDateTime(u.last_login_at, locale, 'Asia/Jakarta') : '—'}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={u.is_active ? 'outline' : 'destructive'}
                                                disabled={isSelf}
                                                onClick={() => updateUser(u, u.roles, !u.is_active)}
                                                className="h-7 text-xs"
                                            >
                                                {u.is_active ? t('admin.users.status_active') : t('admin.users.status_inactive')}
                                            </Button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </AdminLayout>
    );
}
