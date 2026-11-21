import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, FileJson } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';

type Finding = {
    id: string;
    title: string;
    severity: string;
    status: string;
    summary: string;
};
type Run = {
    id: string;
    snapshot_uuid: string;
    schema_version: string;
    addon_version: string;
    status: string;
    collected_at: string | null;
    received_at: string | null;
    integration_name: string;
    payload: Record<string, unknown>;
    findings: Finding[];
    ai_configured: boolean;
    analysis_summary: string | null;
    analysis_error_code: string | null;
    no_findings_reason: string | null;
};
type Props = { run: Run };

export default function DiagnosticRunShow({ run }: Props) {
    const timezone = useWorkspaceTimezone();

    return (
        <>
            <Head title="Diagnostic Snapshot" />
            <div className="space-y-6">
                <Link
                    href="/diagnostic-runs"
                    className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden />
                    All diagnostic runs
                </Link>
                <PageHeader
                    title="Diagnostic snapshot"
                    description={run.snapshot_uuid}
                    actions={<StatusBadge status={run.status} />}
                />

                <section className="grid gap-4 rounded-lg border border-border bg-card p-5 shadow-xs sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Installation
                        </p>
                        <p className="mt-1 font-medium">
                            {run.integration_name}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">Status</p>
                        <p className="mt-1">
                            <StatusBadge status={run.status} />
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Collected
                        </p>
                        <p className="mt-1 text-sm">
                            {formatDateTime(run.collected_at, timezone)}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Received
                        </p>
                        <p className="mt-1 text-sm">
                            {formatDateTime(run.received_at, timezone)}
                        </p>
                    </div>
                </section>

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-sm font-semibold">
                                AI diagnostic analysis
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {run.status === 'analyzed'
                                    ? 'Analysis completed. Recommendations are advisory and require manual review.'
                                    : run.status === 'queued'
                                      ? 'Analysis is queued and waiting for a worker.'
                                      : run.status === 'analyzing'
                                        ? 'Analysis is currently being processed by the queue worker.'
                                        : run.status === 'analysis_failed'
                                          ? 'The previous analysis attempt failed. The source snapshot remains intact.'
                                          : 'This snapshot has been received but has not yet been analyzed.'}
                            </p>
                        </div>
                        {run.ai_configured &&
                            ['received', 'analysis_failed'].includes(
                                run.status,
                            ) && (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            '/diagnostic-runs/' +
                                                run.id +
                                                '/reanalyze',
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {run.status === 'analysis_failed'
                                        ? 'Retry analysis'
                                        : 'Analyze snapshot'}
                                </Button>
                            )}
                    </div>
                    {run.analysis_error_code ? (
                        <p className="mt-3 text-xs text-destructive">
                            Safe error code: {run.analysis_error_code}
                        </p>
                    ) : null}
                    {run.analysis_summary ? (
                        <p className="mt-4 text-sm leading-7 whitespace-pre-wrap">
                            {run.analysis_summary}
                        </p>
                    ) : null}
                    {run.status === 'analyzed' &&
                    run.findings.length === 0 &&
                    run.no_findings_reason ? (
                        <p className="mt-3 text-sm text-muted-foreground">
                            {run.no_findings_reason}
                        </p>
                    ) : null}
                    {!run.ai_configured && run.status === 'received' ? (
                        <p className="mt-3 text-xs text-warning-fg">
                            AI analysis is unavailable until the platform
                            operator configures the OpenAI API key.
                        </p>
                    ) : null}
                </section>

                <section className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                    <div className="border-b border-border px-5 py-4">
                        <h2 className="text-sm font-semibold">
                            Findings from this run
                        </h2>
                    </div>
                    {run.findings.length === 0 ? (
                        <p className="p-5 text-sm text-muted-foreground">
                            No findings have been generated for this snapshot
                            yet. An empty list does not mean the installation is
                            healthy until analysis completes.
                        </p>
                    ) : (
                        <div className="divide-y divide-border">
                            {run.findings.map((finding) => (
                                <Link
                                    key={finding.id}
                                    href={'/findings/' + finding.id}
                                    className="block p-5 hover:bg-surface/70"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="font-medium">
                                            {finding.title}
                                        </p>
                                        <StatusBadge
                                            status={finding.severity}
                                        />
                                    </div>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {finding.summary}
                                    </p>
                                </Link>
                            ))}
                        </div>
                    )}
                </section>

                <section className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                    <div className="flex items-center gap-2 border-b border-border px-5 py-4">
                        <FileJson
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <div>
                            <h2 className="text-sm font-semibold">
                                Sanitized diagnostic payload
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Schema {run.schema_version} · Addon{' '}
                                {run.addon_version}
                            </p>
                        </div>
                    </div>
                    <pre className="max-h-[640px] overflow-auto p-5 text-xs leading-6">
                        <code>{JSON.stringify(run.payload, null, 2)}</code>
                    </pre>
                </section>
            </div>
        </>
    );
}
