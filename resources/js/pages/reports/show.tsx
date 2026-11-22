import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/dates';

type Props = {
    tenant: { id: string; name: string; timezone?: string };
    report: {
        id: string;
        type: string;
        period_start: string | null;
        period_end: string | null;
        sent_at: string | null;
        summary: Record<string, unknown>;
    };
};

function asNumber(value: unknown): number | null {
    return typeof value === 'number' ? value : null;
}

function asString(value: unknown): string | null {
    return typeof value === 'string' ? value : null;
}

function issueCtaLabel(issue: Record<string, unknown>): string {
    const label = asString(issue.cta_label);
    if (label) {
        return label;
    }

    const cta = asString(issue.cta);
    if (cta === 'fix_now') {
        return 'Fix now';
    }
    if (cta === 'start_guided_fix' || cta === 'view_guide') {
        return 'Start guided fix';
    }

    return 'View in Fix Center';
}

export default function ReportShow({ tenant, report }: Props) {
    const timezone = tenant.timezone;
    const summary = report.summary;
    const title =
        report.type === 'monthly_value'
            ? 'Monthly value report'
            : 'Weekly health report';

    const topOpen = Array.isArray(summary.top_open_issues)
        ? (summary.top_open_issues as Array<Record<string, unknown>>)
        : [];
    const installations = Array.isArray(summary.installations)
        ? (summary.installations as Array<Record<string, unknown>>)
        : [];

    return (
        <>
            <Head title={title} />
            <div className="mx-auto flex max-w-3xl flex-col gap-6 print:max-w-none">
                <PageHeader
                    title={title}
                    description={`${tenant.name} · ${formatDate(report.period_start, { timeZone: timezone })} – ${formatDate(report.period_end, { timeZone: timezone })}`}
                    actions={
                        <div className="flex flex-wrap gap-2 print:hidden">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/reports">Back to reports</Link>
                            </Button>
                            <Form
                                action={`/tenants/${tenant.id}/reports/${report.id}/resend`}
                                method="post"
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Resend to me
                                    </Button>
                                )}
                            </Form>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => window.print()}
                            >
                                Print
                            </Button>
                        </div>
                    }
                />

                <p className="text-sm text-muted-foreground">
                    Sent {formatDateTime(report.sent_at, timezone)}
                </p>

                <ul className="space-y-2 text-sm">
                    <li>
                        Health score: {asNumber(summary.health_score) ?? 'n/a'}
                        {asNumber(summary.health_score_change) != null
                            ? ` (${asNumber(summary.health_score_change)! >= 0 ? '+' : ''}${asNumber(summary.health_score_change)})`
                            : ''}
                    </li>
                    <li>Issues fixed: {asNumber(summary.issues_fixed) ?? 0}</li>
                    <li>
                        Time saved:{' '}
                        {asString(summary.time_saved_hours_display) ?? '0.0'}{' '}
                        hours (about $
                        {asString(summary.time_saved_value_display) ?? '0.00'})
                    </li>
                    {asString(summary.recovered_display) ? (
                        <li>
                            $ recovered: ${asString(summary.recovered_display)}
                        </li>
                    ) : null}
                    <li>
                        Outside-heartbeat incidents:{' '}
                        {asNumber(summary.outside_heartbeat_incidents) ?? 0}
                    </li>
                    <li>Open: {asNumber(summary.open) ?? 0}</li>
                    <li>Critical: {asNumber(summary.critical) ?? 0}</li>
                </ul>

                {topOpen.length > 0 ? (
                    <section className="space-y-2">
                        <h2 className="text-sm font-semibold">
                            Top open issues
                        </h2>
                        <ol className="list-decimal space-y-1 pl-5 text-sm">
                            {topOpen.slice(0, 3).map((issue, index) => (
                                <li key={asString(issue.id) ?? index}>
                                    {asString(issue.title) ?? 'Issue'}
                                    {asString(issue.fix_url) ? (
                                        <>
                                            {' '}
                                            ·{' '}
                                            <a
                                                className="underline"
                                                href={asString(issue.fix_url)!}
                                            >
                                                {issueCtaLabel(issue)}
                                            </a>
                                        </>
                                    ) : null}
                                </li>
                            ))}
                        </ol>
                    </section>
                ) : null}

                {installations.length > 0 ? (
                    <section className="space-y-2">
                        <h2 className="text-sm font-semibold">
                            Installation heartbeat
                        </h2>
                        <ul className="space-y-1 text-sm">
                            {installations.map((installation, index) => (
                                <li key={asString(installation.id) ?? index}>
                                    {asString(installation.name) ?? 'Install'}:{' '}
                                    {asString(installation.heartbeat_state) ??
                                        'unknown'}
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
            </div>
        </>
    );
}
