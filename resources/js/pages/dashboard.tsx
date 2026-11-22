import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowDownRight,
    ArrowUpRight,
    CheckCircle2,
    Circle,
    Clock3,
    Download,
    HeartPulse,
    LayoutGrid,
    Server,
    ShieldAlert,
    Wrench,
} from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { HealthSparkline } from '@/components/health-sparkline';
import { PageHeader } from '@/components/page-header';
import { SeverityBadge } from '@/components/severity-badge';
import { StatCard } from '@/components/stat-card';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';
import {
    dismissGettingStarted,
    show as onboarding,
} from '@/actions/App/Http/Controllers/OnboardingController';
import { edit as editSecurity } from '@/routes/security';

type DashboardStats = {
    active_integrations: number;
    total_runs: number;
    open_findings: number;
    critical_findings: number;
    awaiting_safe_actions?: number;
    needs_recheck?: number;
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
        first_scan: boolean;
    };
};

type GettingStartedChecklist = {
    visible: boolean;
    dismissed: boolean;
    completed: boolean;
    tenant_id: string;
    items: {
        alert_channel: boolean;
        first_fix: boolean;
        invite_teammate: boolean;
    };
};

type HeartbeatState = 'unknown' | 'ok' | 'stale' | 'down' | string;

type Installation = {
    id: string;
    name: string;
    status: string;
    addon_version: string | null;
    last_seen_at: string | null;
    last_heartbeat_at?: string | null;
    heartbeat_state?: HeartbeatState;
    runs_count: number;
    open_findings: number;
};

type NeedsYouNowItem = {
    id: string;
    title: string;
    severity: string;
    issue_key: string | null;
    fix_mode: string | null;
    priority: number;
    installation: string | null;
    primary_action: 'fix_now' | 'start_guide' | 'review';
};

type Props = {
    stats: DashboardStats;
    latestRuns: DiagnosticRun[];
    installations?: Installation[];
    health?: {
        score?: number | null;
        change_vs_last_week?: number | null;
        sparkline?: Array<{ date: string; score: number }>;
        last_scan_clean: boolean;
    };
    needsYouNow?: NeedsYouNowItem[];
    timeSaved?: {
        hours: number;
        hours_display: string;
        value_usd: number;
        value_display: string;
        hourly_rate_usd: number;
    } | null;
    recovered?: {
        amount: number;
        display: string;
    } | null;
    addon: {
        version: string;
        download_available: boolean;
        ai_configured: boolean;
    };
    onboarding?: OnboardingChecklist | null;
    gettingStarted?: GettingStartedChecklist | null;
};

function heartbeatBadge(state: HeartbeatState): {
    label: string;
    tone: 'success' | 'warning' | 'danger' | 'neutral';
} {
    switch (state) {
        case 'ok':
            return { label: 'Healthy', tone: 'success' };
        case 'stale':
            return { label: 'Stale', tone: 'warning' };
        case 'down':
            return { label: 'Down', tone: 'danger' };
        default:
            return { label: 'Waiting', tone: 'neutral' };
    }
}

export default function Dashboard({
    stats,
    latestRuns,
    installations = [],
    health,
    needsYouNow = [],
    timeSaved = null,
    recovered = null,
    addon,
    onboarding: onboardingState,
    gettingStarted = null,
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

    const score = health?.score ?? null;
    const scoreChange = health?.change_vs_last_week ?? null;
    const sparkline = health?.sparkline ?? [];

    return (
        <>
            <Head title="Overview" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Overview"
                    description={`Good to see you, ${firstName}. Read-only health visibility for your WHMCS installation.`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/reports/trends">Trends</Link>
                            </Button>
                            <Button asChild>
                                <Link href="/whmcs-addon/setup">
                                    <Download className="size-4" aria-hidden />
                                    WHMCS Addon & Setup
                                </Link>
                            </Button>
                        </div>
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
                                            ['first_scan', 'First scan'],
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

                {gettingStarted?.visible ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 className="text-sm font-semibold">
                                    Getting started
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    A few quick wins after onboarding.
                                </p>
                                <ul className="mt-3 space-y-2 text-sm">
                                    {(
                                        [
                                            [
                                                'alert_channel',
                                                'Add an alert channel',
                                                tenantId
                                                    ? `/tenants/${tenantId}/alerts`
                                                    : '/dashboard',
                                            ],
                                            [
                                                'first_fix',
                                                'Run your first fix',
                                                '/fix-center',
                                            ],
                                            [
                                                'invite_teammate',
                                                'Invite a teammate',
                                                tenantId
                                                    ? `/tenants/${tenantId}/memberships`
                                                    : '/dashboard',
                                            ],
                                        ] as const
                                    ).map(([key, label, href]) => (
                                        <li
                                            key={key}
                                            className="flex items-center justify-between gap-3"
                                        >
                                            <span className="flex items-center gap-2">
                                                {gettingStarted.items[key] ? (
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
                                            </span>
                                            {!gettingStarted.items[key] ? (
                                                <Link
                                                    href={href}
                                                    className="text-sm font-medium text-primary hover:underline"
                                                >
                                                    Open
                                                </Link>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(dismissGettingStarted.url())
                                }
                            >
                                Dismiss
                            </Button>
                        </div>
                    </section>
                ) : null}

                {isEmptyWorkspace && !onboardingState?.needed ? (
                    <EmptyState
                        icon={LayoutGrid}
                        title="Your workspace is ready"
                        description={
                            auth.two_factor_enabled
                                ? 'Connect your first WHMCS installation to start seeing diagnostic activity here.'
                                : 'Connect your first WHMCS installation after the workspace owner confirms two-factor authentication (TOTP).'
                        }
                        action={
                            auth.two_factor_enabled ? (
                                <Button size="sm" asChild>
                                    <Link href="/integrations">
                                        Connect an installation
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

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <CardTitle className="text-sm font-medium text-muted-foreground">
                                        Health score
                                    </CardTitle>
                                    <p className="mt-2 text-3xl font-semibold tracking-tight">
                                        {score != null ? score : '—'}
                                    </p>
                                </div>
                                <HeartPulse
                                    className="size-4 text-slate-brand"
                                    aria-hidden
                                />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3 pt-0">
                            <HealthSparkline
                                points={sparkline}
                                className="text-brand"
                            />
                            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                {scoreChange != null ? (
                                    <span
                                        className={
                                            scoreChange >= 0
                                                ? 'inline-flex items-center gap-0.5 text-success-fg'
                                                : 'inline-flex items-center gap-0.5 text-danger-fg'
                                        }
                                    >
                                        {scoreChange >= 0 ? (
                                            <ArrowUpRight
                                                className="size-3.5"
                                                aria-hidden
                                            />
                                        ) : (
                                            <ArrowDownRight
                                                className="size-3.5"
                                                aria-hidden
                                            />
                                        )}
                                        {scoreChange >= 0 ? '+' : ''}
                                        {scoreChange} vs last week
                                    </span>
                                ) : (
                                    <span>No week-over-week change yet</span>
                                )}
                                <Link
                                    href="/reports/trends"
                                    className="text-brand underline-offset-2 hover:underline"
                                >
                                    View trends
                                </Link>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="md:col-span-1 xl:col-span-1">
                        <CardHeader className="pb-2">
                            <div className="flex items-start justify-between gap-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Needs you now
                                </CardTitle>
                                <Wrench
                                    className="size-4 text-slate-brand"
                                    aria-hidden
                                />
                            </div>
                        </CardHeader>
                        <CardContent className="pt-0">
                            {needsYouNow.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing urgent — keep an eye on the next
                                    scan.
                                </p>
                            ) : (
                                <ul className="space-y-3">
                                    {needsYouNow.map((item) => (
                                        <li
                                            key={item.id}
                                            className="rounded-md border border-border/70 px-3 py-2"
                                        >
                                            <p className="text-sm font-medium leading-snug">
                                                {item.title}
                                            </p>
                                            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                <span>
                                                    {item.installation ??
                                                        'Installation'}
                                                </span>
                                                <SeverityBadge
                                                    severity={item.severity}
                                                />
                                            </div>
                                            <div className="mt-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link href="/fix-center">
                                                        {item.primary_action ===
                                                        'start_guide'
                                                            ? 'Start guide'
                                                            : item.primary_action ===
                                                                'fix_now'
                                                              ? 'Fix now'
                                                              : 'Review'}
                                                    </Link>
                                                </Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <div className="flex items-start justify-between gap-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Time saved this month
                                </CardTitle>
                                <Clock3
                                    className="size-4 text-slate-brand"
                                    aria-hidden
                                />
                            </div>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <p className="text-3xl font-semibold tracking-tight">
                                {timeSaved
                                    ? `${timeSaved.hours_display}h`
                                    : '0.0h'}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {timeSaved
                                    ? `≈ $${timeSaved.value_display} at $${timeSaved.hourly_rate_usd}/hr`
                                    : 'Verified fixes will appear here'}
                            </p>
                            {recovered ? (
                                <p className="mt-3 rounded-md bg-success-soft px-2.5 py-1.5 text-xs text-success-fg">
                                    Recovered this month: ${recovered.display}
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <div className="flex items-start justify-between gap-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Installations
                                </CardTitle>
                                <Server
                                    className="size-4 text-slate-brand"
                                    aria-hidden
                                />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3 pt-0">
                            {installations.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No installations connected yet.
                                </p>
                            ) : (
                                installations.slice(0, 4).map((install) => {
                                    const heartbeat = heartbeatBadge(
                                        install.heartbeat_state ?? 'unknown',
                                    );
                                    return (
                                        <div
                                            key={install.id}
                                            className="flex items-start justify-between gap-2"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {install.name}
                                                </p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    Last heartbeat{' '}
                                                    {formatDateTime(
                                                        install.last_heartbeat_at ??
                                                            null,
                                                        timezone,
                                                    )}
                                                </p>
                                            </div>
                                            <StatusBadge
                                                status={heartbeat.label}
                                                tone={heartbeat.tone}
                                            />
                                        </div>
                                    );
                                })
                            )}
                            {installations.length > 4 ? (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="px-0"
                                    asChild
                                >
                                    <Link href="/integrations">
                                        View all installations
                                    </Link>
                                </Button>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                {installations.length > 1 ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 className="text-sm font-semibold">
                                    Multi-install ops board
                                </h2>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Cross-install priority for your WHMCS
                                    estate — the hero demo for ops buyers.
                                </p>
                            </div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href="/integrations">Manage</Link>
                            </Button>
                        </div>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {installations.map((install) => (
                                <div
                                    key={install.id}
                                    className="rounded-md border border-border bg-surface/40 px-3 py-3"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="truncate text-sm font-medium">
                                            {install.name}
                                        </p>
                                        <StatusBadge status={install.status} />
                                    </div>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {install.open_findings} active findings
                                        · {install.runs_count} runs
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Last seen{' '}
                                        {formatDateTime(
                                            install.last_seen_at,
                                            timezone,
                                        )}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>
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
                        label="Safe Actions awaiting apply"
                        value={stats.awaiting_safe_actions ?? 0}
                        description="Approved remediations waiting in the WHMCS addon"
                        icon={ShieldAlert}
                    />
                    <StatCard
                        label="Applied, needs verify"
                        value={stats.needs_recheck ?? 0}
                        description="Applied remediations waiting for runtime proof"
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
                                                            className="hover:underline"
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
                                                    <td className="px-4">
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
                            <CardTitle>At a glance</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-xs font-medium text-muted-foreground">
                                    Open findings
                                </p>
                                <div className="mt-1 flex items-center gap-2">
                                    <StatusBadge
                                        status="open"
                                        tone="warning"
                                    />
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
                                        <Link href="/fix-center">
                                            Open Fix Center
                                        </Link>
                                    </Button>
                                ) : health?.last_scan_clean ? (
                                    <p className="mt-2 text-xs text-success-fg">
                                        Last scan clean — no active findings.
                                    </p>
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
