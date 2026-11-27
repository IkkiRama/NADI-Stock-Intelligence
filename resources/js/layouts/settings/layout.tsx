import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const { t } = useT();
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

    const sidebarNavItems = [
        { title: t('settings.nav.preferences'), url: '/settings/preferences' },
        { title: t('settings.nav.profile'), url: '/settings/profile' },
        { title: t('settings.nav.password'), url: '/settings/password' },
        { title: t('settings.nav.appearance'), url: '/settings/appearance' },
    ];

    return (
        <div className="space-y-6">
            <Heading title={t('settings.title')} description={t('settings.subtitle')} />

            <div className="flex flex-col space-y-6 lg:flex-row lg:space-y-0 lg:space-x-10">
                <aside className="w-full max-w-xl lg:w-52">
                    <nav className="flex flex-col space-y-1">
                        {sidebarNavItems.map((item) => (
                            <Button
                                key={item.url}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted font-semibold': currentPath === item.url,
                                })}
                            >
                                <Link href={item.url} prefetch>
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-4 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="space-y-8">{children}</section>
                </div>
            </div>
        </div>
    );
}
