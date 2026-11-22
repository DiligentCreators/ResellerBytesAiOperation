import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { formatDateTime } from '@/lib/dates';

type FixRow = {
    id: string;
    date: string | null;
    installation: string | null;
    issue: string | null;
    mode: string;
    decision_label: string;
    approved_by: string | null;
    decided_by: string | null;
    rejected_by: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    result: string;
    minutes_saved: number | null;
};

type Props = {
    tenant: { id: string; name: string; timezone?: string };
    generated_at: string;
    rows: FixRow[];
};

export default function FixLogPrint({ tenant, generated_at, rows }: Props) {
    const timezone = tenant.timezone;

    useEffect(() => {
        const timer = window.setTimeout(() => window.print(), 250);
        return () => window.clearTimeout(timer);
    }, []);

    return (
        <>
            <Head title={`Fix log — ${tenant.name}`} />
            <div className="mx-auto max-w-5xl bg-white p-8 text-foreground print:p-0">
                <h1 className="text-2xl font-bold">Fix log</h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    {tenant.name} · generated{' '}
                    {formatDateTime(generated_at, timezone)}
                </p>

                <table className="mt-6 w-full text-left text-sm">
                    <thead className="border-b border-border">
                        <tr>
                            <th className="py-2 pr-3 font-medium">Date</th>
                            <th className="py-2 pr-3 font-medium">
                                Installation
                            </th>
                            <th className="py-2 pr-3 font-medium">Issue</th>
                            <th className="py-2 pr-3 font-medium">Mode</th>
                            <th className="py-2 pr-3 font-medium">Decision</th>
                            <th className="py-2 pr-3 font-medium">Result</th>
                            <th className="py-2 font-medium">Minutes saved</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={row.id}
                                className="border-b border-border/60"
                            >
                                <td className="py-2 pr-3 tabular-nums">
                                    {formatDateTime(row.date, timezone)}
                                </td>
                                <td className="py-2 pr-3">
                                    {row.installation ?? '—'}
                                </td>
                                <td className="py-2 pr-3">
                                    {row.issue ?? '—'}
                                </td>
                                <td className="py-2 pr-3">
                                    {row.mode.replace('_', '-')}
                                </td>
                                <td className="py-2 pr-3">
                                    <div className="space-y-0.5">
                                        <p>
                                            {row.decision_label}{' '}
                                            {row.decided_by ?? '—'}
                                        </p>
                                        {row.rejection_reason ? (
                                            <p className="text-xs text-muted-foreground">
                                                {row.rejection_reason}
                                            </p>
                                        ) : null}
                                    </div>
                                </td>
                                <td className="py-2 pr-3">{row.result}</td>
                                <td className="py-2 tabular-nums">
                                    {row.minutes_saved ?? '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}
