import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import TwoFactorEncouragementBanner from '@/components/two-factor-encouragement-banner';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className="min-w-0 overflow-x-clip bg-surface"
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <TwoFactorEncouragementBanner />
                <div className="mx-auto w-full max-w-[1200px] flex-1 px-4 py-6 md:px-8 md:py-8">
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
