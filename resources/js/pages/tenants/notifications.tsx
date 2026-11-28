import { Head, Link, router } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';
import { index as alertsIndex } from '@/routes/tenants/alerts';
import { read as markNotificationRead } from '@/routes/tenants/notifications';

type Notification = {
    id: number;
    event_type?: string | null;
    type?: string | null;
    title?: string | null;
    message?: string | null;
    payload?: Record<string, unknown> | null;
    read_at?: string | null;
    created_at?: string | null;
};

type Props = {
    tenant?: { id?: string; name?: string; timezone?: string | null } | null;
    notifications?: {
        data?: Notification[] | null;
        links?: { url: string | null; label: string; active: boolean }[] | null;
    } | null;
};

function notificationTitle(notification: Notification): string {
    if (
        typeof notification.title === 'string' &&
        notification.title.trim() !== ''
    ) {
        return notification.title;
    }

    const eventType =
        (typeof notification.event_type === 'string' &&
            notification.event_type) ||
        (typeof notification.type === 'string' && notification.type) ||
        '';

    if (eventType !== '') {
        return eventType.replaceAll('.', ' · ');
    }

    return 'Notification';
}

function notificationMessage(notification: Notification): string | null {
    if (
        typeof notification.message === 'string' &&
        notification.message.trim() !== ''
    ) {
        return notification.message;
    }

    const payload = notification.payload;
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    for (const key of ['message', 'body', 'title'] as const) {
        const value = payload[key];
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }
    }

    return null;
}

export default function TenantNotifications({ tenant, notifications }: Props) {
    const workspaceTimezone = useWorkspaceTimezone();
    const tenantId = typeof tenant?.id === 'string' ? tenant.id : '';
    const tenantName =
        typeof tenant?.name === 'string' && tenant.name.trim() !== ''
            ? tenant.name
            : 'this workspace';
    const timezone =
        (typeof tenant?.timezone === 'string' && tenant.timezone.trim() !== ''
            ? tenant.timezone
            : undefined) || workspaceTimezone;
    const items = Array.isArray(notifications?.data) ? notifications.data : [];
    const links = Array.isArray(notifications?.links)
        ? notifications.links
        : [];

    return (
        <>
            <Head title="Notifications" />
            <div className="space-y-6">
                <PageHeader
                    title="Notifications"
                    description={`Workspace alerts for ${tenantName}.`}
                />

                {items.length === 0 ? (
                    <EmptyState
                        icon={Bell}
                        title="No notifications yet"
                        description="Operational alerts for this workspace will show up here."
                        action={
                            tenantId ? (
                                <Button size="sm" asChild>
                                    <Link href={alertsIndex.url(tenantId)}>
                                        Add an alert channel
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <ul className="divide-y divide-border">
                            {items.map((notification, index) => {
                                const id =
                                    typeof notification?.id === 'number'
                                        ? notification.id
                                        : index;
                                const isUnread = !notification?.read_at;
                                const message =
                                    notificationMessage(notification);

                                return (
                                    <li
                                        key={id}
                                        className={`flex items-start justify-between gap-4 px-5 py-4 ${
                                            isUnread
                                                ? 'bg-primary-soft/40'
                                                : 'bg-card'
                                        }`}
                                    >
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">
                                                {notificationTitle(
                                                    notification,
                                                )}
                                            </p>
                                            {message ? (
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {message}
                                                </p>
                                            ) : null}
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    notification?.created_at,
                                                    timezone,
                                                )}
                                                {isUnread
                                                    ? ' · Unread'
                                                    : ' · Read'}
                                            </p>
                                        </div>
                                        {isUnread && tenantId ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                onClick={() =>
                                                    router.post(
                                                        markNotificationRead.url(
                                                            {
                                                                tenant: tenantId,
                                                                notification:
                                                                    id,
                                                            },
                                                        ),
                                                    )
                                                }
                                            >
                                                Mark read
                                            </Button>
                                        ) : null}
                                    </li>
                                );
                            })}
                        </ul>
                        <Pagination links={links} />
                    </div>
                )}
            </div>
        </>
    );
}
