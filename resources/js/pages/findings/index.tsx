import { Head, Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';

type Finding = { id: string; title: string; category: string; severity: string; status: string; created_at: string | null; integration_name: string; diagnostic_run_id: string };
type Props = { findings: { data: Finding[] } };

export default function FindingsIndex({ findings }: Props) {
    return (
        <>
            <Head title="Findings" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div><h1 className="text-2xl font-semibold tracking-tight">Findings</h1><p className="mt-1 text-sm text-muted-foreground">Evidence-backed operational issues identified from diagnostic snapshots.</p></div>
                <section className="overflow-hidden rounded-xl border bg-card">
                    {findings.data.length === 0 ? <div className="px-5 py-14 text-center"><ShieldAlert className="mx-auto size-8 text-muted-foreground" /><p className="mt-3 font-medium">No findings recorded</p><p className="mt-1 text-sm text-muted-foreground">Findings will appear here after diagnostic analysis has run.</p></div> : <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-muted/40 text-xs uppercase text-muted-foreground"><tr><th className="px-5 py-3 font-medium">Finding</th><th className="px-5 py-3 font-medium">Installation</th><th className="px-5 py-3 font-medium">Category</th><th className="px-5 py-3 font-medium">Severity</th><th className="px-5 py-3 font-medium">Status</th></tr></thead><tbody className="divide-y">{findings.data.map((finding) => <tr key={finding.id}><td className="px-5 py-4"><Link href={'/findings/' + finding.id} className="font-medium hover:text-primary hover:underline">{finding.title}</Link></td><td className="px-5 py-4 text-muted-foreground">{finding.integration_name}</td><td className="px-5 py-4 text-muted-foreground">{finding.category}</td><td className="px-5 py-4"><span className="rounded-full border px-2.5 py-1 text-xs capitalize">{finding.severity}</span></td><td className="px-5 py-4 text-muted-foreground capitalize">{finding.status}</td></tr>)}</tbody></table></div>}
                </section>
            </div>
        </>
    );
}
