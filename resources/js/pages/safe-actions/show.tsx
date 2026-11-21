import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';
import { approve } from '@/actions/App/Http/Controllers/SafeActionController';

type Definition = {
    key: string;
    label: string;
    description: string;
    risk_level: string;
    requires_password_confirm: boolean;
};

type Execution = {
    id: string;
    action_key: string;
    status: string;
    risk_level: string;
    requires_password_confirm: boolean;
    requested_at: string | null;
    approved_at: string | null;
    executed_at: string | null;
    verified_at: string | null;
    integration_name: string | null;
    requester_name: string | null;
    approver_name: string | null;
    approval_reason: string | null;
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
};

export default function SafeActionShow({
    execution,
    definition,
    can_approve,
}: Props) {
    const timezone = useWorkspaceTimezone();
    const { flash } = usePage().props as { flash?: { status?: string | null } };

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
                    <p className="text-sm leading-6">{definition.description}</p>
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
                    </dl>
                    {execution.finding_id ? (
                        <p className="mt-3 text-sm">
                            Finding context:{' '}
                            <Link
                                href={`/findings/${execution.finding_id}`}
                                className="font-medium text-primary hover:underline"
                            >
                                {execution.finding_title ?? execution.finding_id}
                            </Link>
                        </p>
                    ) : null}
                    {definition.requires_password_confirm ? (
                        <p className="mt-3 text-xs text-warning-fg">
                            Medium risk: password confirmation and 2FA are
                            required before approve.
                        </p>
                    ) : null}
                </section>

                {can_approve ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">Approve</h2>
                        <Form
                            {...approve.form(execution.id)}
                            className="mt-4 space-y-3"
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

                {execution.status === 'completed' ? (
                    <section className="rounded-lg border border-warning/20 bg-warning-soft/40 p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            Applied — waiting for verify
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            The allowlisted change was applied, but runtime
                            proof is not confirmed yet. Keep the finding in
                            progress until a verify scan shows the issue is
                            gone — do not request the same Safe Action again.
                        </p>
                    </section>
                ) : null}

                {execution.result ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">Result</h2>
                        <p className="mt-2 text-sm">
                            {String(execution.result.summary ?? '')}
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
                        {execution.result.note ? (
                            <p className="mt-3 text-xs text-muted-foreground">
                                {String(execution.result.note)}
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
