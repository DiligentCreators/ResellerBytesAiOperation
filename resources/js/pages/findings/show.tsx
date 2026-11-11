import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ClipboardList } from 'lucide-react';

type Recommendation = { id: string; recommendation: string; rationale: string; risk_level: string; requires_manual_action: boolean };
type Finding = { id: string; title: string; category: string; severity: string; status: string; summary: string; evidence: Record<string, unknown>; confidence: string | null; created_at: string | null; integration_name: string; diagnostic_run_id: string; recommendations: Recommendation[] };
type Props = { finding: Finding };

export default function FindingShow({ finding }: Props) {
    return (
        <>
            <Head title="Finding Details" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div><Link href="/findings" className="mb-3 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> All findings</Link><div className="flex flex-wrap items-center gap-2"><h1 className="text-2xl font-semibold tracking-tight">{finding.title}</h1><span className="rounded-full border px-2.5 py-1 text-xs capitalize">{finding.severity}</span></div><p className="mt-1 text-sm text-muted-foreground">{finding.integration_name} · {finding.category} · {finding.status}</p></div>
                <section className="rounded-xl border bg-card p-5"><h2 className="font-semibold">Assessment</h2><p className="mt-3 whitespace-pre-wrap text-sm leading-7">{finding.summary}</p><p className="mt-3 text-xs text-muted-foreground">Confidence: {finding.confidence ?? 'Not provided'}</p><Link href={'/diagnostic-runs/' + finding.diagnostic_run_id} className="mt-3 inline-flex text-sm font-medium text-primary hover:underline">Open source diagnostic snapshot</Link></section>
                <section className="rounded-xl border bg-card p-5"><div className="flex items-center gap-2"><ClipboardList className="size-5" /><h2 className="font-semibold">Recommendations</h2></div>{finding.recommendations.length === 0 ? <p className="mt-3 text-sm text-muted-foreground">No recommendation has been recorded for this finding.</p> : <div className="mt-4 space-y-4">{finding.recommendations.map((item) => <div key={item.id} className="rounded-lg border p-4"><div className="flex flex-wrap items-center gap-2"><p className="font-medium">{item.recommendation}</p><span className="rounded-full border px-2 py-0.5 text-xs capitalize">{item.risk_level} risk</span></div><p className="mt-2 text-sm text-muted-foreground">{item.rationale}</p>{item.requires_manual_action && <p className="mt-3 text-xs font-medium text-amber-600">Manual action required — no changes are executed by this platform.</p>}</div>)}</div>}</section>
                <section className="overflow-hidden rounded-xl border bg-card"><div className="border-b p-5"><h2 className="font-semibold">Evidence</h2></div><pre className="max-h-[480px] overflow-auto p-5 text-xs leading-6"><code>{JSON.stringify(finding.evidence, null, 2)}</code></pre></section>
            </div>
        </>
    );
}
