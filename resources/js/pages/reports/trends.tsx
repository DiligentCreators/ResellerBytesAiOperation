import { Head, Link, router } from '@inertiajs/react';
import { LineChart } from 'lucide-react';
import { useState } from 'react';
import { HealthTrendChart } from '@/components/health-trend-chart';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type TrendPoint = {
    date: string;
    score: number;
    critical_count: number;
    warning_count: number;
    info_count: number;
    metrics: {
        module_queue_pending?: number | null;
        overdue_unpaid_count?: number | null;
        suspended_with_paid?: number | null;
        sla_breaches?: number | null;
        domains_failing?: number | null;
        disk_free_percent?: number | null;
        heartbeat_state?: string;
    };
};

type Props = {
    tenant: { id: string; name: string };
    range_days: number;
    max_history_days: number;
    available_ranges: number[];
    points: TrendPoint[];
};

type MetricKey =
    | 'score'
    | 'critical_count'
    | 'warning_count'
    | 'overdue_unpaid_count'
    | 'disk_free_percent';

const metricLabels: Record<MetricKey, string> = {
    score: 'Health score',
    critical_count: 'Critical findings',
    warning_count: 'Warning findings',
    overdue_unpaid_count: 'Overdue unpaid',
    disk_free_percent: 'Disk free %',
};

export default function HealthTrends({
    tenant,
    range_days,
    max_history_days,
    available_ranges,
    points,
}: Props) {
    const [metric, setMetric] = useState<MetricKey>('score');

    return (
        <>
            <Head title="Health trends" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Health trends"
                    description={`Score and key metrics for ${tenant.name}. Retention up to ${max_history_days} days on your plan.`}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/dashboard">Back to overview</Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex flex-wrap gap-2">
                        {available_ranges.map((days) => (
                            <Button
                                key={days}
                                size="sm"
                                variant={
                                    range_days === days ? 'default' : 'outline'
                                }
                                onClick={() =>
                                    router.get(
                                        '/reports/trends',
                                        {
                                            days,
                                            tenant_id: tenant.id,
                                        },
                                        { preserveState: true },
                                    )
                                }
                            >
                                {days}d
                            </Button>
                        ))}
                    </div>
                    <Select
                        value={metric}
                        onValueChange={(value) =>
                            setMetric(value as MetricKey)
                        }
                    >
                        <SelectTrigger className="w-[200px]">
                            <SelectValue placeholder="Metric" />
                        </SelectTrigger>
                        <SelectContent>
                            {(
                                Object.keys(metricLabels) as MetricKey[]
                            ).map((key) => (
                                <SelectItem key={key} value={key}>
                                    {metricLabels[key]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <section className="rounded-lg border border-border bg-card p-4 shadow-xs sm:p-5">
                    <div className="mb-4 flex items-center gap-2">
                        <LineChart
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">
                            {metricLabels[metric]} · last {range_days} days
                        </h2>
                    </div>
                    <HealthTrendChart points={points} metric={metric} />
                </section>

                {points.length > 0 ? (
                    <section className="overflow-x-auto rounded-lg border border-border bg-card shadow-xs">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">
                                Daily health snapshots
                            </caption>
                            <thead className="border-b border-border bg-surface text-xs font-medium text-muted-foreground">
                                <tr>
                                    <th className="h-9 px-4" scope="col">
                                        Date
                                    </th>
                                    <th className="h-9 px-4" scope="col">
                                        Score
                                    </th>
                                    <th className="h-9 px-4" scope="col">
                                        Critical
                                    </th>
                                    <th className="h-9 px-4" scope="col">
                                        Warning
                                    </th>
                                    <th className="h-9 px-4" scope="col">
                                        Overdue
                                    </th>
                                    <th className="h-9 px-4" scope="col">
                                        Disk free %
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {[...points].reverse().map((point) => (
                                    <tr
                                        key={point.date}
                                        className="h-10 border-b border-border last:border-0"
                                    >
                                        <td className="px-4 font-medium">
                                            {point.date}
                                        </td>
                                        <td className="px-4">{point.score}</td>
                                        <td className="px-4">
                                            {point.critical_count}
                                        </td>
                                        <td className="px-4">
                                            {point.warning_count}
                                        </td>
                                        <td className="px-4">
                                            {point.metrics
                                                .overdue_unpaid_count ?? '—'}
                                        </td>
                                        <td className="px-4">
                                            {point.metrics.disk_free_percent !=
                                            null
                                                ? point.metrics
                                                      .disk_free_percent
                                                : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                ) : null}
            </div>
        </>
    );
}
