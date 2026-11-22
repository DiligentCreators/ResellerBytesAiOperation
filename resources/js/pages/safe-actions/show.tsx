import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';
import {
    approve,
    reject,
} from '@/actions/App/Http/Controllers/SafeActionController';

type Definition = {
    key: string;
    label: string;
    description: string;
    risk_level: string;
    requires_password_confirm: boolean;
};

type PreviewItem = {
    id?: number | null;
    command?: string;
    params?: Record<string, unknown>;
    note?: string;
};

type Execution = {
    id: string;
    action_key: string;
    mode?: string | null;
    batch_id?: string | null;
    issue_key?: string | null;
    items_total?: number | null;
    items_succeeded?: number | null;
    minutes_saved?: number | null;
    status: string;
    risk_level: string;
    requires_password_confirm: boolean;
    requested_at: string | null;
    approved_at: string | null;
    agent_picked_up_at: string | null;
    rejected_at: string | null;
    executed_at: string | null;
    verified_at: string | null;
    late_result_status: string | null;
    late_result_at: string | null;
    late_results?: Array<{
        id: string;
        status: string;
        summary: string | null;
        error_code: string | null;
        received_at: string | null;
    }>;
    integration_name: string | null;
    requester_name: string | null;
    approver_name: string | null;
    rejector_name: string | null;
    approval_reason: string | null;
    rejection_reason: string | null;
    reject_blocked_reason: string | null;
    preview?: {
        items?: PreviewItem[];
        refused?: { id?: number | null; reason?: string }[];
        target_ids?: number[];
        description?: string;
    } | null;
    target_ids?: number[] | null;
    result: Record<string, unknown> | null;
    before_evidence: Record<string, unknown> | null;
    after_evidence: Record<string, unknown> | null;
    finding_id: string | null;
    finding_title: string | null;
};

type Props = {
    execution: Execution;
    definition: Definition;
    can_approve: boolean;
    can_reject: boolean;
};

function asDisplayString(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

export default function SafeActionShow({
    execution,
    definition,
    can_approve,
    can_reject,
}: Props) {
    const timezone = useWorkspaceTimezone();
    const { flash } = usePage().props as { flash?: { status?: string | null } };
    const [confirmReject, setConfirmReject] = useState(false);
    const isRejected =
        execution.status === 'rejected' || execution.status === 'cancelled';

    return (
        <>
            <Head title={definition.label} />
            <div className="space-y-6">
                <Link
                    href="/safe-actions"
                    className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden />
                    All safe actions
                </Link>
                <PageHeader
                    title={definition.label}
                    description={`${execution.integration_name ?? 'Installation'} · Approve → Apply → Verify → Cleared`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/fix-center">Open Fix Center</Link>
                            </Button>
                            <StatusBadge status={execution.status} />
                            <StatusBadge status={execution.risk_level} />
                        </div>
                    }
                />

                {flash?.status ? (
                    <p className="rounded-md border border-success/20 bg-success-soft px-3 py-2 text-sm text-success-fg">
                        {flash.status}
                    </p>
                ) : null}

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <p className="text-sm leading-6">
                        {definition.description}
                    </p>
                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Requested
                            </dt>
                            <dd>
                                {execution.requester_name} ·{' '}
                                {formatDateTime(
                                    execution.requested_at,
                                    timezone,
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Approved
                            </dt>
                            <dd>
                                {execution.approver_name
                                    ? `${execution.approver_name} · ${formatDateTime(execution.approved_at, timezone)}`
                                    : 'Pending'}
                            </dd>
                        </div>
                        {isRejected ? (
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    {execution.status === 'cancelled'
                                        ? 'Cancelled'
                                        : 'Rejected'}
                                </dt>
                                <dd>
                                    {execution.rejector_name
                                        ? `${execution.rejector_name} · ${formatDateTime(execution.rejected_at, timezone)}`
                                        : formatDateTime(
                                              execution.rejected_at,
                                              timezone,
                                          )}
                                </dd>
                            </div>
                        ) : null}
                    </dl>
                    {execution.finding_id ? (
                        <p className="mt-3 text-sm">
                            Finding context:{' '}
                            <Link
                                href={`/findings/${execution.finding_id}`}
                                className="font-medium text-primary hover:underline"
                            >
                                {execution.finding_title ??
                                    execution.finding_id}
                            </Link>
                        </p>
                    ) : null}
                    {definition.requires_password_confirm ? (
                        <p className="mt-3 text-xs text-warning-fg">
                            Medium risk: password confirmation and 2FA are
                            required before approve.
                        </p>
                    ) : null}
                    {execution.mode || execution.items_total != null ? (
                        <p className="mt-3 text-sm text-muted-foreground">
                            Mode: {execution.mode ?? 'one_click'}
                            {execution.items_total != null
                                ? ` · ${execution.items_total} item(s)`
                                : ''}
                            {execution.minutes_saved != null
                                ? ` · ${execution.minutes_saved} min saved`
                                : ''}
                        </p>
                    ) : null}
                </section>

                {execution.preview?.items &&
                execution.preview.items.length > 0 ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            Preview — what will run
                        </h2>
                        <ul className="mt-3 space-y-2 text-sm">
                            {execution.preview.items.map((item, index) => (
                                <li
                                    key={`${item.command ?? 'cmd'}-${item.id ?? index}`}
                                    className="rounded-md border border-border px-3 py-2 font-mono text-xs"
                                >
                                    {item.command}
                                    {item.id != null ? ` #${item.id}` : ''}
                                    {item.params
                                        ? ` ${JSON.stringify(item.params)}`
                                        : ''}
                                </li>
                            ))}
                        </ul>
                        {execution.preview.refused &&
                        execution.preview.refused.length > 0 ? (
                            <ul className="mt-3 space-y-1 text-sm text-warning-fg">
                                {execution.preview.refused.map(
                                    (item, index) => (
                                        <li
                                            key={`${item.id ?? index}-${item.reason}`}
                                        >
                                            Refused #{item.id}: {item.reason}
                                        </li>
                                    ),
                                )}
                            </ul>
                        ) : null}
                    </section>
                ) : null}

                {execution.reject_blocked_reason ? (
                    <p className="rounded-md border border-warning/30 bg-warning-soft px-3 py-2 text-sm text-warning-fg">
                        {execution.reject_blocked_reason}
                    </p>
                ) : null}

                {can_approve || can_reject ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">Decision</h2>
                        <div className="mt-4 grid gap-6 lg:grid-cols-2">
                            {can_approve ? (
                                <Form
                                    {...approve.form(execution.id)}
                                    className="space-y-3"
                                >
                                    {({ processing }) => (
                                        <>
                                            <label className="block space-y-1.5 text-sm">
                                                <span className="font-medium">
                                                    Why are you approving this?
                                                </span>
                                                <textarea
                                                    name="approval_reason"
                                                    required
                                                    minLength={8}
                                                    rows={3}
                                                    className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                                                    placeholder="Short operational reason for the audit trail"
                                                />
                                            </label>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Approve and execute
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            ) : null}

                            {can_reject ? (
                                <div className="space-y-3">
                                    {!confirmReject ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setConfirmReject(true)
                                            }
                                        >
                                            Reject
                                        </Button>
                                    ) : (
                                        <Form
                                            {...reject.form(execution.id)}
                                            className="space-y-3"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <label className="block space-y-1.5 text-sm">
                                                        <span className="font-medium">
                                                            Optional rejection
                                                            reason
                                                        </span>
                                                        <textarea
                                                            name="rejection_reason"
                                                            maxLength={1000}
                                                            rows={3}
                                                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                                                            placeholder="Optional note for the audit trail"
                                                        />
                                                    </label>
                                                    <p className="text-xs text-muted-foreground">
                                                        This stops the safe
                                                        action before WHMCS runs
                                                        it. The finding stays
                                                        open.
                                                    </p>
                                                    <div className="flex flex-wrap gap-2">
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="destructive"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Confirm reject
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                processing
                                                            }
                                                            onClick={() =>
                                                                setConfirmReject(
                                                                    false,
                                                                )
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                    </div>
                                                </>
                                            )}
                                        </Form>
                                    )}
                                </div>
                            ) : null}
                        </div>
                    </section>
                ) : null}

                {execution.approval_reason ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            Approval reason
                        </h2>
                        <p className="mt-2 text-sm whitespace-pre-wrap">
                            {execution.approval_reason}
                        </p>
                    </section>
                ) : null}

                {isRejected ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            {execution.status === 'cancelled'
                                ? 'Cancellation'
                                : 'Rejection'}
                        </h2>
                        <p className="mt-2 text-sm">
                            {execution.rejector_name ?? 'Operator'} ·{' '}
                            {formatDateTime(execution.rejected_at, timezone)}
                        </p>
                        {execution.rejection_reason ? (
                            <p className="mt-2 text-sm whitespace-pre-wrap">
                                {execution.rejection_reason}
                            </p>
                        ) : (
                            <p className="mt-2 text-sm text-muted-foreground">
                                No reason provided.
                            </p>
                        )}
                    </section>
                ) : null}

                {execution.status === 'completed' ? (
                    <section className="rounded-lg border border-warning/20 bg-warning-soft/40 p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            Applied — waiting for verify
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            The allowlisted change was applied, but runtime
                            proof is not confirmed yet. Keep the finding in
                            progress until a verify scan shows the issue is gone
                            — do not request the same Safe Action again.
                        </p>
                    </section>
                ) : null}

                {(execution.late_results?.length ?? 0) > 0 ||
                execution.result?.late === true ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            Late addon results
                        </h2>
                        <p className="mt-2 text-xs text-muted-foreground">
                            Recorded after cancel/reject · latest{' '}
                            {execution.late_result_status ?? '—'}
                            {execution.late_result_at
                                ? ` · ${formatDateTime(execution.late_result_at, timezone)}`
                                : ''}
                            {` · ${Math.max(execution.late_results?.length ?? 0, 1)} total`}
                        </p>
                        {(execution.late_results?.length ?? 0) > 0 ? (
                            <ul className="mt-3 space-y-3">
                                {execution.late_results!.map((lateResult) => (
                                    <li
                                        key={lateResult.id}
                                        className="rounded-md border border-border bg-surface/50 px-3 py-2"
                                    >
                                        <p className="text-xs text-muted-foreground">
                                            {lateResult.received_at
                                                ? formatDateTime(
                                                      lateResult.received_at,
                                                      timezone,
                                                  )
                                                : '—'}
                                            {` · ${lateResult.status}`}
                                            {lateResult.error_code
                                                ? ` · ${lateResult.error_code}`
                                                : ''}
                                        </p>
                                        <p className="mt-1 text-sm">
                                            {lateResult.summary ?? 'No summary'}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-2 text-sm">
                                {asDisplayString(execution.result?.summary)}
                            </p>
                        )}
                    </section>
                ) : null}

                {execution.result && execution.result.late !== true ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">Result</h2>
                        <p className="mt-2 text-sm">
                            {asDisplayString(execution.result.summary)}
                        </p>
                        {'instructions' in execution.result &&
                        Array.isArray(execution.result.instructions) ? (
                            <ol className="mt-3 list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                                {execution.result.instructions.map(
                                    (item: unknown) => (
                                        <li key={String(item)}>
                                            {String(item)}
                                        </li>
                                    ),
                                )}
                            </ol>
                        ) : null}
                        {'paths' in execution.result &&
                        Array.isArray(execution.result.paths) ? (
                            <ul className="mt-3 space-y-2 text-sm">
                                {execution.result.paths.map((path: any) => (
                                    <li
                                        key={String(path.label)}
                                        className="rounded-md border border-border bg-surface/50 px-3 py-2"
                                    >
                                        <p className="font-medium">
                                            {String(path.label)}
                                        </p>
                                        <p className="font-mono text-xs">
                                            {String(path.path)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        {'checks' in execution.result &&
                        Array.isArray(execution.result.checks) ? (
                            <ul className="mt-3 space-y-2 text-sm">
                                {execution.result.checks.map((check: any) => (
                                    <li
                                        key={String(check.id)}
                                        className="flex flex-wrap items-center gap-2"
                                    >
                                        <span>{String(check.label)}</span>
                                        <StatusBadge
                                            status={String(
                                                check.severity ?? 'info',
                                            )}
                                        />
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {JSON.stringify(check.value)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        {typeof execution.result.note === 'string' &&
                        execution.result.note !== '' ? (
                            <p className="mt-3 text-xs text-muted-foreground">
                                {execution.result.note}
                            </p>
                        ) : null}
                    </section>
                ) : null}

                <section className="grid gap-6 lg:grid-cols-2">
                    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <div className="border-b border-border px-5 py-3 text-sm font-semibold">
                            Before evidence
                        </div>
                        <pre className="max-h-72 overflow-auto p-5 text-xs">
                            <code>
                                {JSON.stringify(
                                    execution.before_evidence,
                                    null,
                                    2,
                                )}
                            </code>
                        </pre>
                    </div>
                    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <div className="border-b border-border px-5 py-3 text-sm font-semibold">
                            After evidence
                        </div>
                        <pre className="max-h-72 overflow-auto p-5 text-xs">
                            <code>
                                {JSON.stringify(
                                    execution.after_evidence,
                                    null,
                                    2,
                                )}
                            </code>
                        </pre>
                    </div>
                </section>
            </div>
        </>
    );
}
