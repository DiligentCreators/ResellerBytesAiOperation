import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    Bot,
    CreditCard,
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

const footerNavItems: NavItem[] = [
    {
        title: 'WHMCS Documentation',
        href: 'https://docs.whmcs.com/8-13/addon-modules/',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const permissions = auth.central_permissions ?? [];
    const hasWorkspace = (auth.tenants?.length ?? 0) > 0;
    const isCustomerWorkspace = hasWorkspace || !auth.is_platform_operator;

    const workspaceNavItems: NavItem[] = [
        { title: 'Overview', href: dashboard(), icon: LayoutDashboard },
        { title: 'Integrations', href: '/integrations', icon: Server },
        { title: 'Diagnostic Runs', href: '/diagnostic-runs', icon: Activity },
        { title: 'Findings', href: '/findings', icon: ShieldAlert },
    ];

    if (isCustomerWorkspace || hasWorkspace) {
        workspaceNavItems.push({
            title: 'WHMCS Addon & Setup',
            href: '/whmcs-addon/setup',
            icon: Download,
        });
    }

    const primaryTenantId = auth.tenants?.[0]?.id;
    if (primaryTenantId) {
        workspaceNavItems.push({
            title: 'Billing',
            href: `/tenants/${primaryTenantId}/billing`,
            icon: CreditCard,
        });
    }

    const platformItems: NavItem[] = [];

    if (permissions.includes('billing')) {
        platformItems.push({
            title: 'Billing',
            href: '/platform/billing',
            icon: CreditCard,
        });
    }

    if (permissions.includes('settings')) {
        platformItems.push(
            {
                title: 'Mail Settings',
                href: '/platform/mail-settings',
                icon: Mail,
            },
            {
                title: 'AI & Addon Signing',
                href: '/platform/ai-settings',
                icon: Bot,
            },
        );
    }

    if (auth.is_platform_admin) {
        platformItems.push({
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
                <NavMain
                    items={workspaceNavItems}
                    label={
                        auth.is_platform_operator && !hasWorkspace
                            ? 'Operations'
                            : 'Workspace'
                    }
                />
                {auth.is_platform_operator && (
                    <NavMain items={platformItems} label="Platform" />
                )}
            </SidebarContent>
            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
