import { Link, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { index as notificationsIndex } from '@/routes/tenants/notifications';

function resolveTenantId(
    url: string,
    tenants: { id?: string }[] | undefined,
): string | null {
    const pathMatch = url.match(/^\/tenants\/([^/?#]+)/);
    const fromPath = pathMatch?.[1];

    if (fromPath && fromPath.trim() !== '') {
        return fromPath;
    }

    const first = tenants?.[0]?.id;

    return typeof first === 'string' && first.trim() !== '' ? first : null;
}

export function NotificationsBell() {
    const page = usePage();
    const { auth } = page.props;
    const tenantId = resolveTenantId(page.url, auth.tenants);

    if (!tenantId) {
        return null;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    asChild
                    aria-label="Workspace notifications"
                >
                    <Link href={notificationsIndex.url(tenantId)}>
                        <Bell className="size-4 text-slate-brand" aria-hidden />
                    </Link>
                </Button>
            </TooltipTrigger>
            <TooltipContent>Notifications</TooltipContent>
        </Tooltip>
    );
}
