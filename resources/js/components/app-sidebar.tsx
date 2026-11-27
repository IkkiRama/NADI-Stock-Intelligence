import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useT } from '@/lib/i18n';
import { nadiNavigationGroups } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Settings, ShieldCheck } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const page = usePage<SharedData>();
    const { auth } = page.props;
    const { t } = useT();
    const currentPath = page.url.split('?')[0];

    const isActiveHref = (href: string) => {
        if (href === '/dashboard') {
            return currentPath === '/dashboard';
        }
        return currentPath === href || currentPath.startsWith(`${href}/`);
    };

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {nadiNavigationGroups.map((group) => (
                    <SidebarGroup key={group.groupKey} className="px-2 py-1">
                        <SidebarGroupLabel>{t(group.groupKey)}</SidebarGroupLabel>
                        <SidebarMenu>
                            {group.items.map((item) => {
                                const Icon = item.icon;
                                const active = isActiveHref(item.href);

                                return (
                                    <SidebarMenuItem key={item.key}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={active}
                                            tooltip={t(item.key)}
                                            className={cn(item.status === 'planned' && 'text-muted-foreground')}
                                        >
                                            <Link href={item.href} prefetch className="flex items-center justify-between">
                                                <span className="flex min-w-0 items-center gap-2">
                                                    <Icon className="h-4 w-4 shrink-0" />
                                                    <span className="truncate">{t(item.key)}</span>
                                                </span>
                                                {item.status === 'planned' && (
                                                    <span className="border-border bg-muted/70 text-muted-foreground ml-auto shrink-0 rounded border px-1 py-0.2 text-[9px] font-medium group-data-[collapsible=icon]:hidden">
                                                        P{item.phase}
                                                    </span>
                                                )}
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                );
                            })}
                        </SidebarMenu>
                    </SidebarGroup>
                ))}
            </SidebarContent>

            <SidebarFooter>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton asChild isActive={currentPath.startsWith('/settings')} tooltip={t('nav.settings')}>
                            <Link href="/settings/preferences" prefetch>
                                <Settings className="h-4 w-4 shrink-0" />
                                <span>{t('nav.settings')}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    {auth?.can?.accessAdmin && (
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild isActive={currentPath.startsWith('/admin')} tooltip={t('nav.admin')}>
                                <Link href="/admin" prefetch>
                                    <ShieldCheck className="h-4 w-4 shrink-0" />
                                    <span>{t('nav.admin')}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    )}
                </SidebarMenu>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
