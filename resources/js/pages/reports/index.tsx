import { Form, Head, Link, router } from '@inertiajs/react';
import { Download, LineChart, Printer } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDate, formatDateTime } from '@/lib/dates';

type ReportSummary = {
    health_score?: number | null;
    issues_fixed?: number | null;
    time_saved_hours_display?: string | null;
    time_saved_value_display?: string | null;
};

type ReportRow = {
    id: string;
    type: string;
    period_start: string | null;
    period_end: string | null;
    sent_at: string | null;
    summary: ReportSummary;
};

type FixRow = {
    id: string;
    date: string | null;
    installation: string | null;
    issue: string | null;
    mode: string;
    approved_by: string | null;
    result: string;
    minutes_saved: number | null;
};

type Props = {
    tenant: { id: string; name: string; timezone?: string };
    reports: ReportRow[];
    filters: {
        mode?: string | null;
        result?: string | null;
        integration_id?: string | null;
        from?: string | null;
        to?: string | null;
        q?: string | null;
    };
    integrations: Array<{ id: string; name: string }>;
    fixLog: {
        data: FixRow[];
        meta: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
        };
        links: {
            prev: string | null;
            next: string | null;
        };
    };
    status?: string;
};

function reportTypeLabel(type: string): string {
    return type === 'monthly_value' ? 'Monthly value' : 'Weekly health';
}

export default function ReportsIndex({
    tenant,
    reports,
    filters,
    integrations,
    fixLog,
    status,
}: Props) {
    const workspaceTimezone = useWorkspaceTimezone();
    const timezone = tenant.timezone || workspaceTimezone;

    const applyFilters = (overrides: Record<string, string | undefined>) => {
        const next = {
            tenant_id: tenant.id,
            mode: filters.mode ?? undefined,
            result: filters.result ?? undefined,
            integration_id: filters.integration_id ?? undefined,
            from: filters.from ?? undefined,
            to: filters.to ?? undefined,
            q: filters.q ?? undefined,
            ...overrides,
        };

        Object.keys(next).forEach((key) => {
            if (next[key as keyof typeof next] === '' || next[key as keyof typeof next] === undefined) {
                delete next[key as keyof typeof next];
            }
        });

        router.get('/reports', next, { preserveState: true });
    };

    const exportQuery = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            exportQuery.set(key, value);
        }
    });
    const exportSuffix = exportQuery.toString()
        ? `?${exportQuery.toString()}`
        : '';

    return (
        <>
            <Head title="Reports" />
            <div className="flex flex-col gap-8">
                <PageHeader
                    title="Reports"
                    description={`Past weekly and monthly reports for ${tenant.name}, plus the full fix log.`}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/reports/trends?tenant_id=${tenant.id}`}>
                                <LineChart className="mr-2 size-4" />
                                Health trends
                            </Link>
                        </Button>
                    }
                />

                {status ? (
                    <p
                        role="status"
                        className="rounded-md border border-success/20 bg-success-soft px-3 py-2 text-sm text-success-fg"
                    >
                        {status}
                    </p>
                ) : null}

                <section className="space-y-4">
                    <h2 className="text-base font-semibold">Past reports</h2>
                    {reports.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No reports have been sent yet. Weekly health reports
                            go out on the platform schedule; monthly value
                            reports require Growth+ and arrive before renewal.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-left text-sm">
                                <thead className="border-b border-border text-muted-foreground">
                                    <tr>
                                        <th className="px-2 py-2 font-medium">
                                            Type
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Period
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Sent
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Snapshot
                                        </th>
                                        <th className="px-2 py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {reports.map((report) => (
                                        <tr
                                            key={report.id}
                                            className="border-b border-border/70"
                                        >
                                            <td className="px-2 py-3">
                                                {reportTypeLabel(report.type)}
                                            </td>
                                            <td className="px-2 py-3 tabular-nums">
                                                {formatDate(report.period_start, {
                                                    timeZone: timezone,
                                                })}{' '}
                                                –{' '}
                                                {formatDate(report.period_end, {
                                                    timeZone: timezone,
                                                })}
                                            </td>
                                            <td className="px-2 py-3 tabular-nums">
                                                {formatDateTime(
                                                    report.sent_at,
                                                    timezone,
                                                )}
                                            </td>
                                            <td className="px-2 py-3 text-muted-foreground">
                                                {report.summary.issues_fixed !=
                                                null
                                                    ? `${report.summary.issues_fixed} fixed`
                                                    : '—'}
                                                {report.summary
                                                    .time_saved_hours_display
                                                    ? ` · ${report.summary.time_saved_hours_display}h`
                                                    : ''}
                                            </td>
                                            <td className="px-2 py-3">
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/tenants/${tenant.id}/reports/${report.id}`}
                                                        >
                                                            View
                                                        </Link>
                                                    </Button>
                                                    <Form
                                                        action={`/tenants/${tenant.id}/reports/${report.id}/resend`}
                                                        method="post"
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                variant="ghost"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Resend to me
                                                            </Button>
                                                        )}
                                                    </Form>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="space-y-4">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <h2 className="text-base font-semibold">Fix log</h2>
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={`/tenants/${tenant.id}/reports/fix-log/export${exportSuffix}`}
                                >
                                    <Download className="mr-2 size-4" />
                                    Export CSV
                                </a>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={`/tenants/${tenant.id}/reports/fix-log/print${exportSuffix}`}
                                >
                                    <Printer className="mr-2 size-4" />
                                    Print / PDF
                                </Link>
                            </Button>
                        </div>
                    </div>

                    <form
                        className="grid gap-3 md:grid-cols-3 lg:grid-cols-6"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = new FormData(event.currentTarget);
                            applyFilters({
                                mode: String(form.get('mode') || '') || undefined,
                                result:
                                    String(form.get('result') || '') ||
                                    undefined,
                                integration_id:
                                    String(form.get('integration_id') || '') ||
                                    undefined,
                                from:
                                    String(form.get('from') || '') || undefined,
                                to: String(form.get('to') || '') || undefined,
                                q: String(form.get('q') || '') || undefined,
                            });
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="mode">Mode</Label>
                            <Select
                                name="mode"
                                defaultValue={filters.mode ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilters({
                                        mode:
                                            value === 'all'
                                                ? undefined
                                                : value,
                                    })
                                }
                            >
                                <SelectTrigger id="mode">
                                    <SelectValue placeholder="All modes" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="one_click">
                                        One-click
                                    </SelectItem>
                                    <SelectItem value="bulk">Bulk</SelectItem>
                                    <SelectItem value="auto">Auto</SelectItem>
                                    <SelectItem value="guided">
                                        Guided
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="result">Result</Label>
                            <Input
                                id="result"
                                name="result"
                                defaultValue={filters.result ?? ''}
                                placeholder="verified"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="integration_id">Installation</Label>
                            <Select
                                name="integration_id"
                                defaultValue={
                                    filters.integration_id ?? 'all'
                                }
                                onValueChange={(value) =>
                                    applyFilters({
                                        integration_id:
                                            value === 'all'
                                                ? undefined
                                                : value,
                                    })
                                }
                            >
                                <SelectTrigger id="integration_id">
                                    <SelectValue placeholder="All installs" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {integrations.map((integration) => (
                                        <SelectItem
                                            key={integration.id}
                                            value={integration.id}
                                        >
                                            {integration.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="from">From</Label>
                            <Input
                                id="from"
                                name="from"
                                type="date"
                                defaultValue={filters.from ?? ''}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="to">To</Label>
                            <Input
                                id="to"
                                name="to"
                                type="date"
                                defaultValue={filters.to ?? ''}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="q">Search</Label>
                            <div className="flex gap-2">
                                <Input
                                    id="q"
                                    name="q"
                                    defaultValue={filters.q ?? ''}
                                    placeholder="Issue or action"
                                />
                                <Button type="submit" size="sm">
                                    Apply
                                </Button>
                            </div>
                        </div>
                    </form>

                    {fixLog.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No fixes recorded for these filters yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[48rem] text-left text-sm">
                                <thead className="border-b border-border text-muted-foreground">
                                    <tr>
                                        <th className="px-2 py-2 font-medium">
                                            Date
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Installation
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Issue
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Mode
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Approved by
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Result
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Minutes saved
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {fixLog.data.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-b border-border/70"
                                        >
                                            <td className="px-2 py-3 tabular-nums">
                                                {formatDateTime(
                                                    row.date,
                                                    timezone,
                                                )}
                                            </td>
                                            <td className="px-2 py-3">
                                                {row.installation ?? '—'}
                                            </td>
                                            <td className="px-2 py-3">
                                                {row.issue ?? '—'}
                                            </td>
                                            <td className="px-2 py-3">
                                                {row.mode.replace('_', '-')}
                                            </td>
                                            <td className="px-2 py-3">
                                                {row.approved_by ?? '—'}
                                            </td>
                                            <td className="px-2 py-3">
                                                {row.result}
                                            </td>
                                            <td className="px-2 py-3 tabular-nums">
                                                {row.minutes_saved ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <span>
                            {fixLog.meta.total} fix
                            {fixLog.meta.total === 1 ? '' : 'es'}
                        </span>
                        <div className="flex gap-2">
                            {fixLog.links.prev ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.get(fixLog.links.prev!)
                                    }
                                >
                                    Previous
                                </Button>
                            ) : null}
                            {fixLog.links.next ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.get(fixLog.links.next!)
                                    }
                                >
                                    Next
                                </Button>
                            ) : null}
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}
