import { Head, Link } from '@inertiajs/react';
import { Activity } from 'lucide-react';

type Run = { id: string; snapshot_uuid: string; status: string; whmcs_version: string; received_at: string | null; findings_count: number; integration_name: string };
type Props = { runs: { data: Run[] } };

function date(value: string | null) {
    return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Not available';
}

export default function DiagnosticRunsIndex({ runs }: Props) {
    return (
        <>
            <Head title="Diagnostic Runs" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div><h1 className="text-2xl font-semibold tracking-tight">Diagnostic Runs</h1><p className="mt-1 text-sm text-muted-foreground">Sanitized diagnostic snapshots received from your WHMCS installation.</p></div>
                <section className="overflow-hidden rounded-xl border bg-card">
                    {runs.data.length === 0 ? <div className="px-5 py-14 text-center"><Activity className="mx-auto size-8 text-muted-foreground" /><p className="mt-3 font-medium">No diagnostic runs yet</p><p className="mt-1 text-sm text-muted-foreground">Once the WHMCS addon submits a snapshot, it will appear here.</p></div> : (
                        <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-muted/40 text-xs uppercase text-muted-foreground"><tr><th className="px-5 py-3 font-medium">Installation</th><th className="px-5 py-3 font-medium">WHMCS</th><th className="px-5 py-3 font-medium">Received</th><th className="px-5 py-3 font-medium">Findings</th><th className="px-5 py-3 font-medium">Status</th><th className="px-5 py-3" /></tr></thead><tbody className="divide-y">{runs.data.map((run) => <tr key={run.id}><td className="px-5 py-4 font-medium">{run.integration_name}</td><td className="px-5 py-4 text-muted-foreground">{run.whmcs_version}</td><td className="px-5 py-4 text-muted-foreground">{date(run.received_at)}</td><td className="px-5 py-4">{run.findings_count}</td><td className="px-5 py-4"><span className="rounded-full border px-2.5 py-1 text-xs capitalize">{run.status}</span></td><td className="px-5 py-4"><Link href={'/diagnostic-runs/' + run.id} className="text-primary hover:underline">Details</Link></td></tr>)}</tbody></table></div>
                    )}
                </section>
            </div>
        </>
    );
}
