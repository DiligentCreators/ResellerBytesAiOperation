import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, ShieldAlert } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SeverityBadge } from '@/components/severity-badge';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';

type Finding = {
    id: string;
    title: string;
    category: string;
    severity: string;
    status: string;
    created_at: string | null;
    integration_name: string;
    diagnostic_run_id: string;
    assignee_name: string | null;
};

type Props = {
    findings: {
        data: Finding[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: {
        status: string;
    };
    health: {
        last_scan_clean: boolean;
        active_count: number;
        analyzed_runs: number;
    };
};

const statusFilters = [
    { value: 'all', label: 'Current' },
    { value: 'active', label: 'Active' },
    { value: 'open', label: 'Open' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'accepted_risk', label: 'Accepted risk' },
    { value: 'history', label: 'History' },
] as const;

export default function FindingsIndex({ findings, filters, health }: Props) {
    const setStatus = (status: string) => {
        router.get('/findings', status === 'all' ? {} : { status }, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title="Findings" />
            <div className="space-y-6">
                <PageHeader
                    title="Findings"
                    description="One current issue per installation signal. History keeps older scan rows. Approve → Apply in WHMCS → Verify scan."
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/fix-center">Open Fix Center</Link>
                        </Button>
                    }
                />

                {health.last_scan_clean ? (
                    <section className="rounded-lg border border-success/20 bg-success-soft p-5">
                        <div className="flex items-start gap-3">
                            <CheckCircle2
                                className="mt-0.5 size-5 text-success-fg"
                                aria-hidden
                            />
                            <div>
                                <h2 className="text-sm font-semibold text-success-fg">
                                    Last scan clean
                                </h2>
                                <p className="mt-1 text-sm text-success-fg/90">
                                    Analyzed diagnostics show no active
                                    findings. Keep the weekly digest on and
                                    re-check after the next WHMCS change window.
                                </p>
                            </div>
                        </div>
                    </section>
                ) : null}

                <div className="flex flex-wrap gap-2">
                    {statusFilters.map((filter) => (
                        <Button
                            key={filter.value}
                            type="button"
                            size="sm"
                            variant={
                                filters.status === filter.value
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => setStatus(filter.value)}
                        >
                            {filter.label}
                        </Button>
                    ))}
                </div>

                {findings.data.length === 0 ? (
                    <EmptyState
                        icon={
                            health.last_scan_clean ? CheckCircle2 : ShieldAlert
                        }
                        title={
                            health.last_scan_clean
                                ? 'Last scan clean'
                                : 'No findings in this view'
                        }
                        description={
                            health.last_scan_clean
                                ? 'Your latest analyzed WHMCS diagnostics did not produce active findings.'
                                : health.analyzed_runs === 0
                                  ? 'Findings appear after diagnostic analysis has run.'
                                  : 'Try another status filter or wait for the next scan.'
                        }
                        action={
                            health.analyzed_runs === 0 ? (
                                <Button size="sm" asChild>
                                    <Link href="/whmcs-addon/setup">
                                        Connect WHMCS
                                    </Link>
                                </Button>
                            ) : (
                                <Button size="sm" asChild>
                                    <Link href="/fix-center">
                                        Open Fix Center
                                    </Link>
                                </Button>
                            )
                        }
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
                                    <th scope="col" className="h-9 px-4">
                                        Assignee
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
                                            <SeverityBadge
                                                severity={finding.severity}
                                            />
                                        </td>
                                        <td className="px-4">
                                            <StatusBadge
                                                status={finding.status}
                                            />
                                        </td>
                                        <td className="px-4 text-muted-foreground">
                                            {finding.assignee_name ?? '—'}
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
