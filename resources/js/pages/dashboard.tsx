import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    Circle,
    Download,
    LayoutGrid,
    Server,
    ShieldAlert,
} from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';
import { show as onboarding } from '@/routes/onboarding';
import { edit as editSecurity } from '@/routes/security';

type DashboardStats = {
    active_integrations: number;
    total_runs: number;
    open_findings: number;
    critical_findings: number;
};

type DiagnosticRun = {
    id: string;
    snapshot_uuid: string;
    status: string;
    collected_at: string | null;
    received_at: string | null;
    whmcs_version: string;
    findings_count: number;
    integration_name: string;
};

type OnboardingChecklist = {
    needed: boolean;
    tenant_id: string | null;
    steps: {
        workspace: boolean;
        plan: boolean;
        whmcs: boolean;
    };
};

type Props = {
    stats: DashboardStats;
    latestRuns: DiagnosticRun[];
    addon: {
        version: string;
        download_available: boolean;
        ai_configured: boolean;
    };
    onboarding?: OnboardingChecklist | null;
};

export default function Dashboard({
    stats,
    latestRuns,
    addon,
    onboarding: onboardingState,
}: Props) {
    const { auth } = usePage().props;
    const timezone = useWorkspaceTimezone();
    const firstName = auth.user?.name?.split(' ')[0] ?? 'there';
    const tenantId = auth.tenants?.[0]?.id;
    const isPlatformOperator =
        auth.is_platform_admin || auth.is_platform_operator;
    const isEmptyWorkspace =
        stats.active_integrations === 0 &&
        stats.total_runs === 0 &&
        stats.open_findings === 0;

    return (
        <>
            <Head title="Overview" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Overview"
                    description={`Good to see you, ${firstName}. Read-only health visibility for your WHMCS installation.`}
                    actions={
                        <Button asChild>
                            <Link href="/whmcs-addon/setup">
                                <Download className="size-4" aria-hidden />
                                WHMCS Addon & Setup
                            </Link>
                        </Button>
                    }
                />

                {onboardingState?.needed && (
                    <section className="rounded-lg border border-dashed border-input bg-brand-50/60 p-5">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 className="text-sm font-semibold">
                                    Finish workspace setup
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Complete onboarding to connect billing and
                                    WHMCS.
                                </p>
                                <ul className="mt-3 space-y-1.5 text-sm">
                                    {(
                                        [
                                            ['workspace', 'Workspace details'],
                                            ['plan', 'Choose a plan'],
                                            ['whmcs', 'Connect WHMCS'],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <li
                                            key={key}
                                            className="flex items-center gap-2"
                                        >
                                            {onboardingState.steps[key] ? (
                                                <CheckCircle2
                                                    className="size-4 text-success-fg"
                                                    aria-hidden
                                                />
                                            ) : (
                                                <Circle
                                                    className="size-4 text-slate-brand"
                                                    aria-hidden
                                                />
                                            )}
                                            {label}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <Button asChild size="sm">
                                <Link href={onboarding()}>
                                    Continue onboarding
                                </Link>
                            </Button>
                        </div>
                    </section>
                )}

                {isEmptyWorkspace && !onboardingState?.needed ? (
                    <EmptyState
                        icon={LayoutGrid}
                        title="Your workspace is ready"
                        description={
                            auth.two_factor_enabled
                                ? 'Connect your first WHMCS integration to start seeing diagnostic activity here.'
                                : 'Connect your first WHMCS integration after the workspace owner confirms two-factor authentication (TOTP).'
                        }
                        action={
                            auth.two_factor_enabled ? (
                                <Button size="sm" asChild>
                                    <Link href="/integrations">
                                        Connect a service
                                    </Link>
                                </Button>
                            ) : (
                                <Button size="sm" asChild>
                                    <Link href={editSecurity()}>
                                        Enable 2FA
                                    </Link>
                                </Button>
                            )
                        }
                    />
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Active integrations"
                        value={stats.active_integrations}
                        description="Connected WHMCS installations"
                        icon={Server}
                    />
                    <StatCard
                        label="Diagnostic runs"
                        value={stats.total_runs}
                        description="Snapshots received by the platform"
                        icon={Activity}
                    />
                    <StatCard
                        label="Open findings"
                        value={stats.open_findings}
                        description="Issues awaiting review"
                        icon={AlertTriangle}
                    />
                    <StatCard
                        label="Critical findings"
                        value={stats.critical_findings}
                        description="Open findings requiring priority review"
                        icon={ShieldAlert}
                    />
                </div>

                <div className="grid gap-4 xl:grid-cols-3">
                    <Card className="xl:col-span-2">
                        <CardHeader>
                            <div>
                                <CardTitle>Recent diagnostic runs</CardTitle>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Latest snapshots received from WHMCS.
                                </p>
                            </div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href="/diagnostic-runs">
                                    View all
                                    <ArrowUpRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent className="p-0">
                            {latestRuns.length === 0 ? (
                                <EmptyState
                                    className="rounded-none border-0 border-t border-dashed"
                                    icon={Activity}
                                    title="No operations yet"
                                    description="Deploy the addon, create an integration, then submit your first manual snapshot."
                                    action={
                                        <Button size="sm" asChild>
                                            <Link href="/whmcs-addon/setup">
                                                View setup instructions
                                            </Link>
                                        </Button>
                                    }
                                />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <caption className="sr-only">
                                            Recent diagnostic runs
                                        </caption>
                                        <thead className="border-b border-border bg-surface text-xs font-medium text-muted-foreground">
                                            <tr>
                                                <th
                                                    scope="col"
                                                    className="h-9 px-4"
                                                >
                                                    Installation
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="h-9 px-4"
                                                >
                                                    WHMCS
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="h-9 px-4"
                                                >
                                                    Received
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="h-9 px-4"
                                                >
                                                    Findings
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="h-9 px-4"
                                                >
                                                    Status
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {latestRuns.map((run) => (
                                                <tr
                                                    key={run.id}
                                                    className="h-11 border-b border-border last:border-0 hover:bg-surface/70"
                                                >
                                                    <td className="px-4 font-medium">
                                                        <Link
                                                            href={`/diagnostic-runs/${run.id}`}
                                                            className="text-primary hover:underline"
                                                        >
                                                            {
                                                                run.integration_name
                                                            }
                                                        </Link>
                                                    </td>
                                                    <td className="px-4 text-muted-foreground">
                                                        {run.whmcs_version}
                                                    </td>
                                                    <td className="px-4 text-muted-foreground">
                                                        {formatDateTime(
                                                            run.received_at,
                                                            timezone,
                                                        )}
                                                    </td>
                                                    <td className="px-4 tabular-nums">
                                                        {run.findings_count}
                                                    </td>
                                                    <td className="px-4">
                                                        <StatusBadge
                                                            status={run.status}
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Health & setup</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-xs font-medium text-muted-foreground">
                                    Open findings by severity
                                </p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <StatusBadge
                                        status="critical"
                                        tone="danger"
                                    />
                                    <span className="text-sm tabular-nums">
                                        {stats.critical_findings}
                                    </span>
                                    <StatusBadge status="open" tone="warning" />
                                    <span className="text-sm tabular-nums">
                                        {stats.open_findings}
                                    </span>
                                </div>
                                {stats.open_findings > 0 ? (
                                    <Button
                                        variant="link"
                                        className="mt-2 h-auto px-0"
                                        asChild
                                    >
                                        <Link href="/findings">
                                            Review findings
                                        </Link>
                                    </Button>
                                ) : (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        No open findings yet.
                                    </p>
                                )}
                            </div>
                            <div className="border-t border-border pt-4">
                                <p className="text-xs font-medium text-muted-foreground">
                                    Integration status
                                </p>
                                <p className="mt-1 text-sm">
                                    {stats.active_integrations > 0
                                        ? `${stats.active_integrations} active integration${stats.active_integrations === 1 ? '' : 's'}`
                                        : 'No active integrations'}
                                </p>
                                <Button
                                    variant="link"
                                    className="mt-1 h-auto px-0"
                                    asChild
                                >
                                    <Link href="/integrations">
                                        Manage integrations
                                    </Link>
                                </Button>
                            </div>
                            {tenantId ? (
                                <div className="border-t border-border pt-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Plan & usage
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Review entitlements, invoices, and
                                        billing details for this workspace.
                                    </p>
                                    <Button
                                        variant="link"
                                        className="mt-1 h-auto px-0"
                                        asChild
                                    >
                                        <Link
                                            href={`/tenants/${tenantId}/billing`}
                                        >
                                            Open billing
                                        </Link>
                                    </Button>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div>
                            <CardTitle>WHMCS diagnostic addon</CardTitle>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Version {addon.version} · Manual-only ·
                                Read-only collector
                            </p>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p className="text-sm text-muted-foreground">
                            AI analysis:{' '}
                            {addon.ai_configured
                                ? 'Configured'
                                : 'API key not configured — snapshots can still be received, but analysis will remain pending.'}
                        </p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            {addon.download_available ? (
                                <Button variant="secondary" size="sm" asChild>
                                    <a href="/whmcs-addon/download">
                                        <Download
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Download ZIP
                                    </a>
                                </Button>
                            ) : isPlatformOperator ? (
                                <p className="max-w-md text-sm text-muted-foreground">
                                    ZIP packaging needs PHP&apos;s{' '}
                                    <code className="rounded bg-muted px-1 py-0.5 text-xs">
                                        ext-zip
                                    </code>{' '}
                                    on this server. Enable it for local/admin
                                    downloads, then refresh.
                                </p>
                            ) : null}
                            <Button size="sm" asChild>
                                <Link href="/whmcs-addon/setup">
                                    Installation guide
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
