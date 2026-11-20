import { usePage } from '@inertiajs/react';

export function useWorkspaceTimezone(): string | undefined {
    const { auth } = usePage().props;

    return auth.tenants?.[0]?.timezone || undefined;
}
