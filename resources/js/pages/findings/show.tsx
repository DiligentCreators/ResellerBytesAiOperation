import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ClipboardList } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';

type Recommendation = {
    id: string;
    recommendation: string;
    rationale: string;
    risk_level: string;
    requires_manual_action: boolean;
};
type Finding = {
    id: string;
    title: string;
    category: string;
    severity: string;
    status: string;
    summary: string;
    evidence: Record<string, unknown>;
    confidence: string | null;
    created_at: string | null;
    integration_name: string;
    diagnostic_run_id: string;
    recommendations: Recommendation[];
};
type Props = { finding: Finding };

export default function FindingShow({ finding }: Props) {
    return (
        <>
            <Head title="Finding Details" />
            <div className="space-y-6">
                <Link
                    href="/findings"
                    className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden />
                    All findings
                </Link>
                <PageHeader
                    title={finding.title}
                    description={`${finding.integration_name} · ${finding.category}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge status={finding.severity} />
                            <StatusBadge status={finding.status} />
                        </div>
                    }
                />

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <h2 className="text-sm font-semibold">Assessment</h2>
                    <p className="mt-3 text-sm leading-7 whitespace-pre-wrap">
                        {finding.summary}
                    </p>
                    <p className="mt-3 text-xs text-muted-foreground">
                        Confidence: {finding.confidence ?? 'Not provided'}
                    </p>
                    <Link
                        href={'/diagnostic-runs/' + finding.diagnostic_run_id}
                        className="mt-3 inline-flex text-sm font-medium text-primary hover:underline"
                    >
                        Open source diagnostic snapshot
                    </Link>
                </section>

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <div className="mb-4 flex items-center gap-2">
                        <ClipboardList
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">
                            Recommendations
                        </h2>
                    </div>
                    {finding.recommendations.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No recommendation has been recorded for this
                            finding.
                        </p>
                    ) : (
                        <div className="space-y-4">
                            {finding.recommendations.map((item) => (
                                <div
                                    key={item.id}
                                    className="rounded-lg border border-border bg-surface/50 p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="font-medium">
                                            {item.recommendation}
                                        </p>
                                        <StatusBadge status={item.risk_level} />
                                    </div>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {item.rationale}
                                    </p>
                                    {item.requires_manual_action ? (
                                        <p className="mt-3 text-xs font-medium text-warning-fg">
                                            Manual action required — no changes
                                            are executed by this platform.
                                        </p>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                    <div className="border-b border-border px-5 py-4">
                        <h2 className="text-sm font-semibold">Evidence</h2>
                    </div>
                    <pre className="max-h-[480px] overflow-auto p-5 text-xs leading-6">
                        <code>{JSON.stringify(finding.evidence, null, 2)}</code>
                    </pre>
                </section>
            </div>
        </>
    );
}
