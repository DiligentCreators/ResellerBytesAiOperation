import { Link, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

export function NotificationsBell() {
    const { auth } = usePage().props;
    const tenantId = auth.tenants?.[0]?.id;

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
                    <Link href={`/tenants/${tenantId}/notifications`}>
                        <Bell className="size-4 text-slate-brand" aria-hidden />
                    </Link>
                </Button>
            </TooltipTrigger>
            <TooltipContent>Notifications</TooltipContent>
        </Tooltip>
    );
}
