import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

export function NavMain({
    items,
    label,
}: {
    items: NavItem[];
    label?: string;
}) {
    const { isCurrentUrl } = useCurrentUrl();

    if (items.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-3 pt-4">
            {label ? (
                <SidebarGroupLabel className="mb-1 px-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                    {label}
                </SidebarGroupLabel>
            ) : null}
            <SidebarMenu>
                {items.map((item) => {
                    const href =
                        typeof item.href === 'string'
                            ? item.href
                            : item.href.url;
                    const active =
                        isCurrentUrl(href) ||
                        (item.children?.some((child) =>
                            isCurrentUrl(
                                child.href.split('#')[0] ?? child.href,
                            ),
                        ) ??
                            false);

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={active && !item.children?.length}
                                tooltip={{ children: item.title }}
                                className={cn(
                                    'h-8 text-sm font-medium text-[#334155] hover:bg-[#EDF1F5] hover:text-foreground dark:text-muted-foreground',
                                    active &&
                                        'bg-white text-foreground shadow-xs ring-1 ring-border dark:bg-card',
                                )}
                            >
                                <Link href={item.href} prefetch>
                                    {item.icon && (
                                        <item.icon
                                            className={cn(
                                                'size-4 text-slate-brand',
                                                active && 'text-brand',
                                            )}
                                            aria-hidden
                                        />
                                    )}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                            {item.children && item.children.length > 0 ? (
                                <SidebarMenuSub>
                                    {item.children.map((child) => {
                                        const childPath =
                                            child.href.split('#')[0] ??
                                            child.href;
                                        const childActive =
                                            isCurrentUrl(childPath);

                                        return (
                                            <SidebarMenuSubItem
                                                key={child.title}
                                            >
                                                <SidebarMenuSubButton
                                                    asChild
                                                    isActive={childActive}
                                                    size="sm"
                                                >
                                                    <Link href={child.href}>
                                                        <span>
                                                            {child.title}
                                                        </span>
                                                    </Link>
                                                </SidebarMenuSubButton>
                                            </SidebarMenuSubItem>
                                        );
                                    })}
                                </SidebarMenuSub>
                            ) : null}
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
