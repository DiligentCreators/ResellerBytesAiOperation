import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BellRing,
    BookOpen,
    Bot,
    CreditCard,
    Download,
    FileText,
    Home,
    Layers,
    LayoutGrid,
    Mail,
    Plug,
    Receipt,
    Settings,
    Shield,
    SlidersHorizontal,
    Users,
    Wrench,
    type LucideIcon,
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
import type { NavItem, SharedNavItem } from '@/types';

const footerNavItems: NavItem[] = [
    {
        title: 'WHMCS Documentation',
        href: 'https://docs.whmcs.com/8-13/addon-modules/',
        icon: BookOpen,
    },
];

const customerIcons: Record<string, LucideIcon> = {
    Home,
    'Fix issues': Wrench,
    'My WHMCS': Plug,
    Reports: FileText,
    Settings,
};

const platformIcons: Record<string, LucideIcon> = {
    'Admin > Money': CreditCard,
    'Admin > System': SlidersHorizontal,
    'Admin > People': Users,
    'Overview / MRR': LayoutGrid,
    'Plan catalog': Layers,
    Gateway: Receipt,
    'Billing settings': CreditCard,
    'Platform settings': SlidersHorizontal,
    Mail,
    'AI and signing': Bot,
    Staff: Users,
};

function withIcons(
    items: SharedNavItem[],
    icons: Record<string, LucideIcon>,
): NavItem[] {
    return items.map((item) => ({
        title: item.title,
        href: item.href,
        icon: icons[item.title] ?? Activity,
        children: item.children,
    }));
}

export function AppSidebar() {
    const { auth, navigation } = usePage().props;
    const customerItems = withIcons(navigation?.customer ?? [], customerIcons);
    const platformItems = withIcons(navigation?.platform ?? [], {
        ...platformIcons,
        // Fallbacks for any renamed platform links.
        'Central Staff': Users,
        'Kelviq gateway': Receipt,
        'Billing admin': CreditCard,
        'Mail Settings': Mail,
        'AI & Addon Signing': Bot,
        Alerts: BellRing,
        Download,
        Shield,
    });

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
                {customerItems.length > 0 ? (
                    <NavMain items={customerItems} />
                ) : null}
                {auth.is_platform_operator && platformItems.length > 0 ? (
                    <>
                        {customerItems.length > 0 ? <SidebarSeparator /> : null}
                        <NavMain items={platformItems} label="Platform" />
                    </>
                ) : null}
            </SidebarContent>
            <SidebarFooter className="border-t border-sidebar-border">
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
