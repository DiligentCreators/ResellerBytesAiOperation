import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowUpRight,
    Download,
    Server,
    ShieldAlert,
} from 'lucide-react';

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

type Props = {
    stats: DashboardStats;
    latestRuns: DiagnosticRun[];
    addon: {
        version: string;
        download_available: boolean;
        ai_configured: boolean;
    };
};

function formatDate(value: string | null): string {
    if (!value) return 'Not available';

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function StatCard({
    label,
    value,
    description,
    icon: Icon,
}: {
    label: string;
    value: number;
    description: string;
    icon: typeof Activity;
}) {
    return (
        <div className="rounded-xl border bg-card p-5">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium text-muted-foreground">
                    {label}
                </p>
                <Icon className="size-4 text-muted-foreground" />
            </div>
            <p className="mt-3 text-3xl font-semibold tracking-tight">
                {value.toLocaleString()}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">{description}</p>
        </div>
    );
}

export default function Dashboard({ stats, latestRuns, addon }: Props) {
    return (
        <>
            <Head title="Operations Overview" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Operations Overview
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Read-only health visibility for your WHMCS
                            installation.
                        </p>
                    </div>
                    <Link
                        href="/whmcs-addon/setup"
                        className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        <Download className="size-4" />
                        WHMCS Addon & Setup
                    </Link>
                </div>

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

                <section className="overflow-hidden rounded-xl border bg-card">
                    <div className="flex flex-col gap-2 border-b p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="font-semibold">
                                Recent diagnostic runs
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Latest snapshots received from WHMCS.
                            </p>
                        </div>
                        <Link
                            href="/diagnostic-runs"
                            className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                        >
                            View all <ArrowUpRight className="size-4" />
                        </Link>
                    </div>
                    {latestRuns.length === 0 ? (
                        <div className="px-5 py-12 text-center">
                            <Activity className="mx-auto size-8 text-muted-foreground" />
                            <h3 className="mt-3 font-medium">
                                No diagnostic data received yet
                            </h3>
                            <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                                Deploy the operations API, create an
                                integration, then install the WHMCS addon to
                                submit your first manual snapshot.
                            </p>
                            <Link
                                href="/whmcs-addon/setup"
                                className="mt-4 inline-flex text-sm font-medium text-primary hover:underline"
                            >
                                View setup instructions
                            </Link>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/40 text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-5 py-3 font-medium">
                                            Installation
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            WHMCS
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Received
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Findings
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {latestRuns.map((run) => (
                                        <tr key={run.id}>
                                            <td className="px-5 py-4 font-medium">
                                                {run.integration_name}
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {run.whmcs_version}
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {formatDate(run.received_at)}
                                            </td>
                                            <td className="px-5 py-4">
                                                {run.findings_count}
                                            </td>
                                            <td className="px-5 py-4">
                                                <span className="rounded-full border px-2.5 py-1 text-xs capitalize">
                                                    {run.status.replaceAll(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="rounded-xl border bg-card p-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="font-semibold">
                                WHMCS diagnostic addon
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Version {addon.version} · Manual-only ·
                                Read-only collector
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {addon.download_available ? (
                                <a
                                    href="/whmcs-addon/download"
                                    className="inline-flex h-9 items-center gap-2 rounded-md border px-3 text-sm font-medium hover:bg-muted"
                                >
                                    <Download className="size-4" /> Download ZIP
                                </a>
                            ) : (
                                <span className="text-sm text-muted-foreground">
                                    ZIP download unavailable until PHP ZIP
                                    support is enabled.
                                </span>
                            )}
                            <Link
                                href="/whmcs-addon/setup"
                                className="inline-flex h-9 items-center rounded-md bg-secondary px-3 text-sm font-medium hover:bg-secondary/80"
                            >
                                Installation guide
                            </Link>
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}
