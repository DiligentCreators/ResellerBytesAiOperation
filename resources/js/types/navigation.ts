import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavChildItem = {
    title: string;
    href: string;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    children?: NavChildItem[];
};

export type SharedNavChild = {
    title: string;
    href: string;
};

export type SharedNavItem = {
    title: string;
    href: string;
    children?: SharedNavChild[];
};

export type SharedNavigation = {
    customer: SharedNavItem[];
    platform: SharedNavItem[];
};
