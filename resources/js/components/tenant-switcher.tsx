import { Link, usePage } from '@inertiajs/react';
import { Building2, ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';

export function TenantSwitcher() {
    const { auth } = usePage().props;
    const { isMobile, state } = useSidebar();
    const tenants = auth.tenants ?? [];

    if (tenants.length === 0) {
        return null;
    }

    const current = tenants[0];

    if (tenants.length === 1) {
        return (
            <div className="flex items-center gap-2.5 px-2 py-1.5">
                <div className="grid size-7 place-items-center rounded-md border border-border bg-white text-slate-brand shadow-xs">
                    <Building2 className="size-3.5" aria-hidden />
                </div>
                <div className="min-w-0 flex-1 group-data-[collapsible=icon]:hidden">
                    <p className="truncate text-sm font-medium">
                        {current.name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground capitalize">
                        {current.role}
                    </p>
                </div>
            </div>
        );
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                            aria-label="Switch workspace"
                        >
                            <div className="grid size-7 place-items-center rounded-md border border-border bg-white text-slate-brand shadow-xs">
                                <Building2 className="size-3.5" aria-hidden />
                            </div>
                            <div className="min-w-0 flex-1 text-left group-data-[collapsible=icon]:hidden">
                                <p className="truncate text-sm font-medium">
                                    {current.name}
                                </p>
                                <p className="truncate text-xs text-muted-foreground capitalize">
                                    {current.role}
                                </p>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4 text-slate-brand group-data-[collapsible=icon]:hidden" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-64 rounded-lg"
                        align="start"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'right'
                                  : 'bottom'
                        }
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Workspaces
                        </DropdownMenuLabel>
                        {tenants.map((tenant) => (
                            <DropdownMenuItem key={tenant.id} asChild>
                                <Link href={`/tenants/${tenant.id}/settings`}>
                                    <span className="truncate font-medium">
                                        {tenant.name}
                                    </span>
                                    <span className="ml-auto text-xs text-muted-foreground capitalize">
                                        {tenant.role}
                                    </span>
                                </Link>
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href={`/tenants/${current.id}/billing`}>
                                Billing for {current.name}
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
