import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    Bot,
    CreditCard,
    Download,
    FileText,
    Layers,
    LayoutGrid,
    LineChart,
    Mail,
    Receipt,
    Server,
    Settings,
    ShieldAlert,
    ShieldCheck,
    SlidersHorizontal,
    Users,
    Wrench,
    BellRing,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TenantSwitcher } from '@/components/tenant-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
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
    const primaryTenantId = auth.tenants?.[0]?.id;

    const overviewItems: NavItem[] = [
        { title: 'Overview', href: dashboard(), icon: LayoutGrid },
    ];

    const reportsItems: NavItem[] = [
        { title: 'Reports', href: '/reports', icon: FileText },
        { title: 'Health trends', href: '/reports/trends', icon: LineChart },
    ];

    const diagnosticsItems: NavItem[] = [
        { title: 'Fix Center', href: '/fix-center', icon: Wrench },
        { title: 'Diagnostic Runs', href: '/diagnostic-runs', icon: Activity },
        { title: 'Findings', href: '/findings', icon: ShieldAlert },
        { title: 'Safe Actions', href: '/safe-actions', icon: ShieldCheck },
    ];

    const integrationsItems: NavItem[] = [
        { title: 'Integrations', href: '/integrations', icon: Server },
    ];

    if (isCustomerWorkspace || hasWorkspace) {
        integrationsItems.push({
            title: 'WHMCS Addon',
            href: '/whmcs-addon/setup',
            icon: Download,
        });
    }

    const billingItems: NavItem[] = [];
    if (primaryTenantId) {
        billingItems.push(
            {
                title: 'Plans',
                href: '/billing/plans',
                icon: Layers,
            },
            {
                title: 'Billing settings',
                href: `/tenants/${primaryTenantId}/billing`,
                icon: CreditCard,
            },
            {
                title: 'Invoices',
                href: `/tenants/${primaryTenantId}/billing/invoices`,
                icon: Receipt,
            },
        );
    }

    const teamItems: NavItem[] = [];
    if (primaryTenantId) {
        teamItems.push(
            {
                title: 'Members',
                href: `/tenants/${primaryTenantId}/memberships`,
                icon: Users,
            },
            {
                title: 'Workspace',
                href: `/tenants/${primaryTenantId}/settings`,
                icon: Settings,
            },
            {
                title: 'Notifications',
                href: `/tenants/${primaryTenantId}/notifications`,
                icon: FileText,
            },
            {
                title: 'Alert channels',
                href: `/tenants/${primaryTenantId}/alerts`,
                icon: BellRing,
            },
        );
    }

    const platformItems: NavItem[] = [];

    if (permissions.includes('billing')) {
        platformItems.push(
            {
                title: 'Billing admin',
                href: '/platform/billing',
                icon: CreditCard,
            },
            {
                title: 'Plan catalog',
                href: '/platform/billing/plans',
                icon: Layers,
            },
            {
                title: 'Kelviq gateway',
                href: '/platform/billing/gateway',
                icon: Receipt,
            },
        );
    }

    if (permissions.includes('billing.settings.manage')) {
        platformItems.push({
            title: 'Billing settings',
            href: '/platform/billing/settings',
            icon: Settings,
        });
    }

    if (permissions.includes('settings')) {
        platformItems.push(
            {
                title: 'Platform settings',
                href: '/platform/settings',
                icon: SlidersHorizontal,
            },
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
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader className="border-b border-sidebar-border">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <TenantSwitcher />
            </SidebarHeader>
            <SidebarContent>
                <NavMain items={overviewItems} label="Overview" />
                {(isCustomerWorkspace || hasWorkspace) && (
                    <>
                        <NavMain items={reportsItems} label="Reports" />
                        <NavMain items={diagnosticsItems} label="Diagnostics" />
                        <NavMain
                            items={integrationsItems}
                            label="Integrations"
                        />
                    </>
                )}
                {billingItems.length > 0 && (
                    <NavMain items={billingItems} label="Billing" />
                )}
                {teamItems.length > 0 && (
                    <NavMain items={teamItems} label="Team" />
                )}
                {auth.is_platform_operator && platformItems.length > 0 && (
                    <>
                        <SidebarSeparator />
                        <NavMain items={platformItems} label="Platform" />
                    </>
                )}
            </SidebarContent>
            <SidebarFooter className="border-t border-sidebar-border">
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
