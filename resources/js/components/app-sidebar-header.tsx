import { Breadcrumbs } from '@/components/breadcrumbs';
import { NotificationsBell } from '@/components/notifications-bell';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between gap-2 border-b border-border bg-white/80 px-4 backdrop-blur transition-[width,height] ease-linear md:px-8">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex items-center gap-1">
                <NotificationsBell />
            </div>
        </header>
    );
}
