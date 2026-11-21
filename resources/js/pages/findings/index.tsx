import { Head, Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';

type Finding = {
    id: string;
    title: string;
    category: string;
    severity: string;
    status: string;
    created_at: string | null;
    integration_name: string;
    diagnostic_run_id: string;
};
type Props = {
    findings: {
        data: Finding[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export default function FindingsIndex({ findings }: Props) {
    return (
        <>
            <Head title="Findings" />
            <div className="space-y-6">
                <PageHeader
                    title="Findings"
                    description="Evidence-backed operational issues identified from diagnostic snapshots."
                />

                {findings.data.length === 0 ? (
                    <EmptyState
                        icon={ShieldAlert}
                        title="No findings recorded"
                        description="Findings will appear here after diagnostic analysis has run."
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Findings</caption>
                            <thead className="border-b border-border bg-surface text-xs font-medium text-muted-foreground">
                                <tr>
                                    <th scope="col" className="h-9 px-4">
                                        Finding
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Installation
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Category
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Severity
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {findings.data.map((finding) => (
                                    <tr
                                        key={finding.id}
                                        className="h-11 border-b border-border last:border-0 hover:bg-surface/70"
                                    >
                                        <td className="px-4">
                                            <Link
                                                href={'/findings/' + finding.id}
                                                className="font-medium text-primary hover:underline"
                                            >
                                                {finding.title}
                                            </Link>
                                        </td>
                                        <td className="px-4 text-muted-foreground">
                                            {finding.integration_name}
                                        </td>
                                        <td className="px-4 text-muted-foreground">
                                            {finding.category}
                                        </td>
                                        <td className="px-4">
                                            <StatusBadge
                                                status={finding.severity}
                                            />
                                        </td>
                                        <td className="px-4">
                                            <StatusBadge
                                                status={finding.status}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <Pagination links={findings.links} />
                    </div>
                )}
            </div>
        </>
    );
}
