import { Head, Link } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';

type Run = {
    id: string;
    snapshot_uuid: string;
    status: string;
    whmcs_version: string;
    received_at: string | null;
    findings_count: number;
    integration_name: string;
};
type Props = {
    runs: {
        data: Run[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export default function DiagnosticRunsIndex({ runs }: Props) {
    const timezone = useWorkspaceTimezone();

    return (
        <>
            <Head title="History" />
            <div className="space-y-6">
                <PageHeader
                    title="History"
                    description="Sanitized diagnostic snapshots received from your WHMCS installations."
                />

                {runs.data.length === 0 ? (
                    <EmptyState
                        icon={Activity}
                        title="No diagnostic runs yet"
                        description="Once the WHMCS addon submits a snapshot, it will appear here."
                        action={
                            <Button size="sm" asChild>
                                <Link href="/whmcs-addon/setup">
                                    Open addon setup
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">
                                Diagnostic runs
                            </caption>
                            <thead className="border-b border-border bg-surface text-xs font-medium text-muted-foreground">
                                <tr>
                                    <th scope="col" className="h-9 px-4">
                                        Installation
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        WHMCS
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Received
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Findings
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Status
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {runs.data.map((run) => (
                                    <tr
                                        key={run.id}
                                        className="h-11 border-b border-border last:border-0 hover:bg-surface/70"
                                    >
                                        <td className="px-4 font-medium">
                                            {run.integration_name}
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
                                        <td className="px-4 tabular-nums">
                                            {run.findings_count}
                                        </td>
                                        <td className="px-4">
                                            <StatusBadge status={run.status} />
                                        </td>
                                        <td className="px-4">
                                            <Link
                                                href={
                                                    '/diagnostic-runs/' + run.id
                                                }
                                                className="text-sm font-medium text-primary hover:underline"
                                            >
                                                Details
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <Pagination links={runs.links} />
                    </div>
                )}
            </div>
        </>
    );
}
