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
import type { NavChildItem, NavItem } from '@/types';

function childPath(href: string): string {
    return href.split('#')[0] ?? href;
}

export function NavMain({
    items,
    label,
}: {
    items: NavItem[];
    label?: string;
}) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    if (items.length === 0) {
        return null;
    }

    const pathIsActive = (path: string): boolean => {
        if (isCurrentUrl(path)) {
            return true;
        }

        // Highlight parents for detail routes (e.g. /findings/123)
        // without treating /reports as a prefix of /reports/trends.
        return path !== '/' && isCurrentOrParentUrl(`${path}/`);
    };

    const childOrDescendantActive = (child: NavChildItem): boolean => {
        if (pathIsActive(childPath(child.href))) {
            return true;
        }

        return (
            child.children?.some((nested) =>
                pathIsActive(childPath(nested.href)),
            ) ?? false
        );
    };

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
                        pathIsActive(href) ||
                        (item.children?.some((child) =>
                            childOrDescendantActive(child),
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
                                        if (
                                            child.children &&
                                            child.children.length > 0
                                        ) {
                                            return (
                                                <SidebarMenuSubItem
                                                    key={child.title}
                                                >
                                                    <p className="px-2 pt-2 pb-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                                        {child.title}
                                                    </p>
                                                    <SidebarMenuSub className="mx-0 border-l-0 px-0">
                                                        {child.children.map(
                                                            (nested) => {
                                                                const nestedActive =
                                                                    pathIsActive(
                                                                        childPath(
                                                                            nested.href,
                                                                        ),
                                                                    );

                                                                return (
                                                                    <SidebarMenuSubItem
                                                                        key={
                                                                            nested.title
                                                                        }
                                                                    >
                                                                        <SidebarMenuSubButton
                                                                            asChild
                                                                            isActive={
                                                                                nestedActive
                                                                            }
                                                                            size="sm"
                                                                        >
                                                                            <Link
                                                                                href={
                                                                                    nested.href
                                                                                }
                                                                            >
                                                                                <span>
                                                                                    {
                                                                                        nested.title
                                                                                    }
                                                                                </span>
                                                                            </Link>
                                                                        </SidebarMenuSubButton>
                                                                    </SidebarMenuSubItem>
                                                                );
                                                            },
                                                        )}
                                                    </SidebarMenuSub>
                                                </SidebarMenuSubItem>
                                            );
                                        }

                                        const childActive = pathIsActive(
                                            childPath(child.href),
                                        );

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
