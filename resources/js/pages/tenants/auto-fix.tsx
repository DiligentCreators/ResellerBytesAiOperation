import { Head, Link } from '@inertiajs/react';
import { Sparkles, Wrench } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';

type Installation = {
    id: string;
    name: string;
    status: string;
    auto_collect_enabled: boolean;
    last_heartbeat_at: string | null;
};

type Props = {
    tenant: { id: string; name: string };
    canManage: boolean;
    proEnabled: boolean;
    billingPlansUrl: string;
    integrationsUrl: string;
    installations: Installation[];
};

export default function TenantAutoFixSettings({
    tenant,
    canManage,
    proEnabled,
    billingPlansUrl,
    integrationsUrl,
    installations,
}: Props) {
    return (
        <>
            <Head title="Auto-fix" />
            <div className="space-y-6">
                <PageHeader
                    title="Auto-fix"
                    description={`One-click and automatic fix settings for ${tenant.name}.`}
                />

                {!proEnabled ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <div className="flex items-start gap-3">
                            <Sparkles
                                className="mt-0.5 size-5 text-brand"
                                aria-hidden
                            />
                            <div className="space-y-2">
                                <h2 className="text-sm font-semibold">
                                    Auto-fix is a Pro feature
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Upgrade to unlock one-click fixes and manage
                                    automatic collection per installation.
                                </p>
                                <Button asChild size="sm">
                                    <Link href={billingPlansUrl}>
                                        View plans
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </section>
                ) : (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            Pro one-click fixes are enabled
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Approve allowlisted fixes from Fix Center. Automatic
                            snapshot collection is controlled per installation.
                        </p>
                        <Button
                            asChild
                            size="sm"
                            variant="outline"
                            className="mt-4"
                        >
                            <Link href="/fix-center">
                                <Wrench className="size-4" aria-hidden />
                                Open Fix Center
                            </Link>
                        </Button>
                    </section>
                )}

                {installations.length === 0 ? (
                    <EmptyState
                        icon={Wrench}
                        title="No installations yet"
                        description="Connect a WHMCS installation before configuring auto-fix or automatic collection."
                        action={
                            canManage ? (
                                <Button size="sm" asChild>
                                    <Link href={integrationsUrl}>
                                        Connect an installation
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <section className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <div className="border-b border-border px-5 py-4">
                            <h2 className="text-sm font-semibold">
                                Installations
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Manage host connection and automatic collection
                                from Installations.
                            </p>
                        </div>
                        <ul className="divide-y divide-border">
                            {installations.map((installation) => (
                                <li
                                    key={installation.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                >
                                    <div>
                                        <p className="text-sm font-medium">
                                            {installation.name}
                                        </p>
                                        <div className="mt-1 flex flex-wrap items-center gap-2">
                                            <StatusBadge
                                                status={installation.status}
                                            />
                                            <StatusBadge
                                                status={
                                                    installation.auto_collect_enabled
                                                        ? 'active'
                                                        : 'pending'
                                                }
                                                tone={
                                                    installation.auto_collect_enabled
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            />
                                            <span className="text-xs text-muted-foreground">
                                                Auto-collect{' '}
                                                {installation.auto_collect_enabled
                                                    ? 'on'
                                                    : 'off'}
                                            </span>
                                        </div>
                                    </div>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={integrationsUrl}>
                                            Manage
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
