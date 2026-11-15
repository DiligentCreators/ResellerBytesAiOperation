import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    Download,
    LayoutDashboard,
    Mail,
    Server,
    ShieldAlert,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Overview', href: dashboard(), icon: LayoutDashboard },
    { title: 'Integrations', href: '/integrations', icon: Server },
    { title: 'Diagnostic Runs', href: '/diagnostic-runs', icon: Activity },
    { title: 'Findings', href: '/findings', icon: ShieldAlert },
    {
        title: 'WHMCS Addon & Setup',
        href: '/whmcs-addon/setup',
        icon: Download,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'WHMCS Documentation',
        href: 'https://docs.whmcs.com/8-13/addon-modules/',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props as {
        auth: { is_platform_admin?: boolean; central_permissions?: string[] };
    };
    const permissions = auth.central_permissions ?? [];
    const navItems = [...mainNavItems];

    if (permissions.includes('settings')) {
        navItems.push({
            title: 'Mail Settings',
            href: '/platform/mail-settings',
            icon: Mail,
        });
    }

    if (auth.is_platform_admin) {
        navItems.push({
            title: 'Central Staff',
            href: '/platform/staff',
            icon: Users,
        });
    }

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>
            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
