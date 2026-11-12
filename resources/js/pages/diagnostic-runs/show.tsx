import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, FileJson } from 'lucide-react';

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

function date(value: string | null) {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'full',
              timeStyle: 'medium',
          }).format(new Date(value))
        : 'Not available';
}

export default function DiagnosticRunShow({ run }: Props) {
    return (
        <>
            <Head title="Diagnostic Snapshot" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Link
                        href="/diagnostic-runs"
                        className="mb-3 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" /> All diagnostic runs
                    </Link>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Diagnostic snapshot
                    </h1>
                    <p className="mt-1 font-mono text-xs break-all text-muted-foreground">
                        {run.snapshot_uuid}
                    </p>
                </div>
                <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2 xl:grid-cols-4">
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
                        <p className="mt-1 font-medium capitalize">
                            {run.status}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Collected
                        </p>
                        <p className="mt-1 text-sm">{date(run.collected_at)}</p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Received
                        </p>
                        <p className="mt-1 text-sm">{date(run.received_at)}</p>
                    </div>
                </section>
                <section className="rounded-xl border bg-card p-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="font-semibold">
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
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            '/diagnostic-runs/' +
                                                run.id +
                                                '/reanalyze',
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                                >
                                    {run.status === 'analysis_failed'
                                        ? 'Retry analysis'
                                        : 'Analyze snapshot'}
                                </button>
                            )}
                    </div>
                    {run.analysis_error_code && (
                        <p className="mt-3 text-xs text-destructive">
                            Safe error code: {run.analysis_error_code}
                        </p>
                    )}
                    {run.analysis_summary && (
                        <p className="mt-4 text-sm leading-7 whitespace-pre-wrap">
                            {run.analysis_summary}
                        </p>
                    )}
                    {run.status === 'analyzed' &&
                        run.findings.length === 0 &&
                        run.no_findings_reason && (
                            <p className="mt-3 text-sm text-muted-foreground">
                                {run.no_findings_reason}
                            </p>
                        )}
                    {!run.ai_configured && run.status === 'received' && (
                        <p className="mt-3 text-xs text-amber-600">
                            Configure OPENAI_API_KEY in the Forge environment
                            before requesting analysis.
                        </p>
                    )}
                </section>
                <section className="rounded-xl border bg-card">
                    <div className="border-b p-5">
                        <h2 className="font-semibold">
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
                        <div className="divide-y">
                            {run.findings.map((finding) => (
                                <Link
                                    key={finding.id}
                                    href={'/findings/' + finding.id}
                                    className="block p-5 hover:bg-muted/40"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="font-medium">
                                            {finding.title}
                                        </p>
                                        <span className="rounded-full border px-2 py-0.5 text-xs capitalize">
                                            {finding.severity}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {finding.summary}
                                    </p>
                                </Link>
                            ))}
                        </div>
                    )}
                </section>
                <section className="overflow-hidden rounded-xl border bg-card">
                    <div className="flex items-center gap-2 border-b p-5">
                        <FileJson className="size-5" />
                        <div>
                            <h2 className="font-semibold">
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
