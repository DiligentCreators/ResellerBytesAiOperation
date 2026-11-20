import { Head, router } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/pagination';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';

type Notification = {
    id: number;
    type: string;
    payload: Record<string, unknown> | null;
    read_at: string | null;
    created_at: string | null;
};

type Props = {
    tenant: { id: string; name: string };
    notifications: {
        data: Notification[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export default function TenantNotifications({ tenant, notifications }: Props) {
    const timezone = useWorkspaceTimezone();

    return (
        <>
            <Head title="Notifications" />
            <div className="space-y-6">
                <PageHeader
                    title="Notifications"
                    description={`Workspace alerts for ${tenant.name}.`}
                />

                {notifications.data.length === 0 ? (
                    <EmptyState
                        icon={Bell}
                        title="No notifications yet"
                        description="Operational alerts for this workspace will show up here."
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <ul className="divide-y divide-border">
                            {notifications.data.map((notification) => (
                                <li
                                    key={notification.id}
                                    className={`flex items-start justify-between gap-4 px-5 py-4 ${
                                        notification.read_at
                                            ? 'bg-card'
                                            : 'bg-primary-soft/40'
                                    }`}
                                >
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium">
                                            {notification.type.replaceAll(
                                                '.',
                                                ' · ',
                                            )}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {formatDateTime(
                                                notification.created_at,
                                                timezone,
                                            )}
                                        </p>
                                    </div>
                                    {!notification.read_at ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="secondary"
                                            onClick={() =>
                                                router.post(
                                                    `/tenants/${tenant.id}/notifications/${notification.id}/read`,
                                                )
                                            }
                                        >
                                            Mark read
                                        </Button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                        <Pagination links={notifications.links} />
                    </div>
                )}
            </div>
        </>
    );
}
