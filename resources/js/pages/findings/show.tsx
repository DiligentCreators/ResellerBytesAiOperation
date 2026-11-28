import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    ClipboardCopy,
    ClipboardList,
    RefreshCw,
    ShieldAlert,
} from 'lucide-react';
import { useState } from 'react';
import { InfoTip } from '@/components/info-tip';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';
import { helpText } from '@/lib/help-text';
import { update as updateFinding } from '@/actions/App/Http/Controllers/FindingController';
import { store as storeSafeAction } from '@/actions/App/Http/Controllers/SafeActionController';
import { recheck } from '@/routes/findings';

type Recommendation = {
    id: string;
    recommendation: string;
    rationale: string;
    risk_level: string;
    requires_manual_action: boolean;
};

type EvidenceRow = {
    path: string;
    label: string;
    value: string;
};

type Playbook = {
    detected: {
        title: string;
        category: string;
        severity: string;
        confidence: string | null;
        evidence: EvidenceRow[];
    };
    impact: {
        summary: string;
        why_it_matters: string;
    };
    safe_steps: {
        step: number;
        action: string;
        rationale: string | null;
        risk_level: string | null;
    }[];
    verify: {
        signal: string;
        instructions: string[];
    };
    rollback: {
        summary: string;
        steps: string[];
    };
    change_risk: string;
    deep_links: {
        label: string;
        path: string;
        note: string;
    }[];
    checklist_text: string;
};

type Recheck = {
    status: string;
    message: string;
    latest_run: {
        id: string;
        status: string;
        received_at: string | null;
        findings_count: number;
    } | null;
    matched_finding: {
        id: string;
        title: string;
        severity: string;
        status: string;
    } | null;
};

type Assignee = {
    id: number;
    name: string;
    email: string;
};

type Finding = {
    id: string;
    title: string;
    category: string;
    severity: string;
    status: string;
    summary: string;
    evidence: Record<string, unknown>;
    confidence: string | null;
    created_at: string | null;
    status_updated_at: string | null;
    resolved_at: string | null;
    status_note: string | null;
    integration_id?: string | null;
    integration_name: string;
    diagnostic_run_id: string;
    assignee: Assignee | null;
    recommendations: Recommendation[];
};

type SuggestedSafeAction = {
    key: string;
    label: string;
    description: string;
    risk_level: string;
    requires_password_confirm: boolean;
    execution_target: string;
    entitlement?: string;
    bulk_allowed?: boolean;
    max_items?: number;
    can_one_click?: boolean;
    can_bulk?: boolean;
    upgrade_hint?: string | null;
};

type Props = {
    finding: Finding & {
        issue_key?: string | null;
        affected_count?: number | null;
        affected_ids?: number[];
    };
    playbook: Playbook;
    recheck: Recheck;
    assignees: Assignee[];
    can_manage: boolean;
    statuses: string[];
    suggested_safe_actions?: SuggestedSafeAction[];
    blocked_safe_action_keys?: string[];
    safe_actions_enabled?: boolean;
    fixes_one_click_enabled?: boolean;
    fixes_bulk_enabled?: boolean;
};

export default function FindingShow({
    finding,
    playbook,
    recheck: recheckState,
    assignees,
    can_manage,
    statuses,
    suggested_safe_actions = [],
    blocked_safe_action_keys = [],
}: Props) {
    const timezone = useWorkspaceTimezone();
    const { flash } = usePage().props as {
        flash?: { status?: string | null };
    };
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);
    const [selectedIds, setSelectedIds] = useState<number[]>(
        finding.affected_ids ?? [],
    );
    const [statusNote, setStatusNote] = useState(finding.status_note ?? '');

    const copyChecklist = async () => {
        const ok = await copy(playbook.checklist_text);
        setCopied(ok);
        if (ok) {
            window.setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <>
            <Head title={`Fix: ${finding.title}`} />
            <div className="space-y-6">
                <Link
                    href="/findings"
                    className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden />
                    All findings
                </Link>
                <PageHeader
                    title={finding.title}
                    description={`${finding.integration_name} · Guided Fix Center — WHMCS stays read-only for this platform`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/fix-center">See what to fix</Link>
                            </Button>
                            <StatusBadge status={finding.severity} />
                            <StatusBadge status={finding.status} />
                            <StatusBadge
                                status={playbook.change_risk}
                                tone={
                                    playbook.change_risk === 'critical' ||
                                    playbook.change_risk === 'high'
                                        ? 'danger'
                                        : playbook.change_risk === 'medium'
                                          ? 'warning'
                                          : 'info'
                                }
                            />
                        </div>
                    }
                />

                {flash?.status ? (
                    <p className="rounded-md border border-success/20 bg-success-soft px-3 py-2 text-sm text-success-fg">
                        {flash.status}
                    </p>
                ) : null}

                <section className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={copyChecklist}
                    >
                        <ClipboardCopy className="size-4" aria-hidden />
                        {copied ? 'Checklist copied' : 'Copy checklist'}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.post(
                                recheck.url(finding.id),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <RefreshCw className="size-4" aria-hidden />
                        Re-check vs latest scan
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={`/diagnostic-runs/${finding.diagnostic_run_id}`}
                        >
                            Source snapshot
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/whmcs-addon/setup">
                            Re-run diagnostics (WHMCS)
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/safe-actions">Safe Actions</Link>
                    </Button>
                </section>

                {can_manage &&
                finding.integration_id &&
                (suggested_safe_actions.length > 0 ||
                    blocked_safe_action_keys.length > 0) ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            Request Safe Action
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Preview affected IDs, approve, apply in WHMCS, then
                            verify. The finding clears only when the next
                            snapshot shows it cleared or reduced.
                        </p>
                        {blocked_safe_action_keys.length > 0 ? (
                            <p className="mt-3 text-sm text-warning-fg">
                                A Safe Action for this issue is already in
                                progress (awaiting apply or verify). Finish that
                                cycle before requesting again.
                            </p>
                        ) : null}
                        {(finding.affected_ids?.length ?? 0) > 0 ? (
                            <div className="mt-4 space-y-2">
                                <p className="text-xs font-medium text-muted-foreground">
                                    Affected items (select for bulk)
                                </p>
                                <ul className="flex flex-wrap gap-2">
                                    {finding.affected_ids?.map((id) => {
                                        const checked =
                                            selectedIds.includes(id);
                                        return (
                                            <li key={id}>
                                                <label className="inline-flex items-center gap-1.5 rounded-md border border-border px-2 py-1 text-xs">
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => {
                                                            setSelectedIds(
                                                                (current) =>
                                                                    checked
                                                                        ? current.filter(
                                                                              (
                                                                                  value,
                                                                              ) =>
                                                                                  value !==
                                                                                  id,
                                                                          )
                                                                        : [
                                                                              ...current,
                                                                              id,
                                                                          ],
                                                            );
                                                        }}
                                                    />
                                                    #{id}
                                                </label>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        ) : null}
                        {suggested_safe_actions.length > 0 ? (
                            <ul className="mt-4 space-y-3">
                                {suggested_safe_actions.map((action) => (
                                    <li
                                        key={action.key}
                                        className="flex flex-col gap-2 rounded-md border border-border p-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">
                                                {action.label}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {action.description}
                                            </p>
                                            {action.upgrade_hint ? (
                                                <p className="mt-2 text-sm text-warning-fg">
                                                    {action.upgrade_hint}
                                                </p>
                                            ) : null}
                                        </div>
                                        {action.can_one_click ? (
                                            <Form
                                                {...storeSafeAction.form()}
                                                className="shrink-0"
                                            >
                                                {({ processing }) => (
                                                    <>
                                                        <input
                                                            type="hidden"
                                                            name="integration_id"
                                                            value={
                                                                finding.integration_id ??
                                                                ''
                                                            }
                                                        />
                                                        <input
                                                            type="hidden"
                                                            name="action_key"
                                                            value={action.key}
                                                        />
                                                        <input
                                                            type="hidden"
                                                            name="finding_id"
                                                            value={finding.id}
                                                        />
                                                        <input
                                                            type="hidden"
                                                            name="input[mode]"
                                                            value={
                                                                action.can_bulk &&
                                                                selectedIds.length >
                                                                    1
                                                                    ? 'bulk'
                                                                    : 'one_click'
                                                            }
                                                        />
                                                        {(action.can_bulk
                                                            ? selectedIds
                                                            : selectedIds.slice(
                                                                  0,
                                                                  1,
                                                              )
                                                        ).map((id) => (
                                                            <input
                                                                key={id}
                                                                type="hidden"
                                                                name="input[target_ids][]"
                                                                value={id}
                                                            />
                                                        ))}
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Request approval
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </section>
                ) : null}

                {can_manage ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">Workflow</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Track ownership and closure here. Allowlisted Safe
                            Actions apply from the WHMCS addon after approval.
                        </p>
                        <Form
                            {...updateFinding.form(finding.id)}
                            className="mt-4 grid gap-4 sm:grid-cols-2"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <>
                                    <label className="space-y-1.5 text-sm">
                                        <span className="inline-flex items-center gap-1.5 font-medium">
                                            Status
                                            <InfoTip
                                                label="Accepted"
                                                text={helpText.accepted}
                                            />
                                        </span>
                                        <select
                                            name="status"
                                            defaultValue={finding.status}
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                        >
                                            {statuses.map((status) => (
                                                <option
                                                    key={status}
                                                    value={status}
                                                >
                                                    {status.replaceAll(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="space-y-1.5 text-sm">
                                        <span className="font-medium">
                                            Assignee
                                        </span>
                                        <select
                                            name="assignee_user_id"
                                            defaultValue={
                                                finding.assignee?.id ?? ''
                                            }
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                        >
                                            <option value="">Unassigned</option>
                                            {assignees.map((member) => (
                                                <option
                                                    key={member.id}
                                                    value={member.id}
                                                >
                                                    {member.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="space-y-1.5 text-sm sm:col-span-2">
                                        <span className="font-medium">
                                            Note
                                        </span>
                                        <textarea
                                            name="status_note"
                                            value={statusNote}
                                            onChange={(event) =>
                                                setStatusNote(
                                                    event.target.value,
                                                )
                                            }
                                            rows={2}
                                            maxLength={500}
                                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                                            placeholder="Optional closure or progress note"
                                        />
                                    </label>
                                    <div className="sm:col-span-2">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Save workflow
                                        </Button>
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Updated{' '}
                                            {formatDateTime(
                                                finding.status_updated_at ??
                                                    finding.created_at,
                                                timezone,
                                            )}
                                            {finding.resolved_at
                                                ? ` · Closed ${formatDateTime(finding.resolved_at, timezone)}`
                                                : ''}
                                        </p>
                                    </div>
                                </>
                            )}
                        </Form>
                    </section>
                ) : (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">Workflow</h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Status: <StatusBadge status={finding.status} />
                            {finding.assignee
                                ? ` · Assigned to ${finding.assignee.name}`
                                : ' · Unassigned'}
                        </p>
                    </section>
                )}

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <div className="mb-3 flex items-center gap-2">
                        <RefreshCw
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">
                            Re-check result
                        </h2>
                        <StatusBadge status={recheckState.status} />
                    </div>
                    <p className="text-sm leading-6">{recheckState.message}</p>
                    {recheckState.latest_run ? (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Latest run{' '}
                            <Link
                                href={`/diagnostic-runs/${recheckState.latest_run.id}`}
                                className="font-medium text-primary hover:underline"
                            >
                                {recheckState.latest_run.id.slice(0, 8)}…
                            </Link>{' '}
                            · {recheckState.latest_run.status} ·{' '}
                            {recheckState.latest_run.findings_count} findings ·{' '}
                            {formatDateTime(
                                recheckState.latest_run.received_at,
                                timezone,
                            )}
                        </p>
                    ) : null}
                    {recheckState.matched_finding ? (
                        <p className="mt-2 text-sm">
                            Still open as{' '}
                            <Link
                                href={`/findings/${recheckState.matched_finding.id}`}
                                className="font-medium text-primary hover:underline"
                            >
                                {recheckState.matched_finding.title}
                            </Link>
                        </p>
                    ) : null}
                    {recheckState.status === 'absent' ? (
                        <p className="mt-3 inline-flex items-center gap-2 text-sm text-success-fg">
                            <CheckCircle2 className="size-4" aria-hidden />
                            Fingerprint cleared on newest analyzed scan.
                        </p>
                    ) : null}
                </section>

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <h2 className="text-sm font-semibold">1. Detected</h2>
                    <p className="mt-2 text-sm leading-7 whitespace-pre-wrap">
                        {playbook.impact.summary}
                    </p>
                    <p className="mt-2 text-xs text-muted-foreground">
                        Confidence:{' '}
                        {playbook.detected.confidence ?? 'Not provided'}
                    </p>
                    <div className="mt-4 space-y-3">
                        <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Evidence
                        </h3>
                        {playbook.detected.evidence.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No evidence paths were retained for this
                                finding.
                            </p>
                        ) : (
                            <dl className="space-y-3">
                                {playbook.detected.evidence.map((row) => (
                                    <div
                                        key={row.path}
                                        className="rounded-md border border-border bg-surface/50 px-3 py-2"
                                    >
                                        <dt className="text-sm font-medium">
                                            {row.label}
                                        </dt>
                                        <dd className="mt-1 font-mono text-xs break-all text-muted-foreground">
                                            {row.value}
                                        </dd>
                                        <dd className="mt-1 text-[11px] text-muted-foreground">
                                            {row.path}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </div>
                </section>

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <div className="mb-2 flex items-center gap-2">
                        <ShieldAlert
                            className="size-4 text-warning-fg"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">
                            2. Impact — why it matters
                        </h2>
                    </div>
                    <p className="text-sm leading-7">
                        {playbook.impact.why_it_matters}
                    </p>
                </section>

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <div className="mb-4 flex items-center gap-2">
                        <ClipboardList
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">3. Safe steps</h2>
                        <StatusBadge status={playbook.change_risk} />
                    </div>
                    <p className="mb-4 text-xs font-medium text-warning-fg">
                        Manual action in WHMCS only — this platform never writes
                        to WHMCS or invents free-form repairs.
                    </p>
                    {playbook.safe_steps.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No structured recommendation was recorded. Use the
                            evidence and deep links below.
                        </p>
                    ) : (
                        <ol className="space-y-4">
                            {playbook.safe_steps.map((step) => (
                                <li
                                    key={step.step}
                                    className="rounded-lg border border-border bg-surface/50 p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-xs font-semibold text-muted-foreground">
                                            Step {step.step}
                                        </span>
                                        {step.risk_level ? (
                                            <StatusBadge
                                                status={step.risk_level}
                                            />
                                        ) : null}
                                    </div>
                                    <p className="mt-2 font-medium">
                                        {step.action}
                                    </p>
                                    {step.rationale ? (
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {step.rationale}
                                        </p>
                                    ) : null}
                                </li>
                            ))}
                        </ol>
                    )}
                </section>

                <section className="grid gap-6 lg:grid-cols-2">
                    <div className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">4. Verify</h2>
                        <p className="mt-2 text-sm leading-6">
                            {playbook.verify.signal}
                        </p>
                        <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                            {playbook.verify.instructions.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </div>
                    <div className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">5. Rollback</h2>
                        <p className="mt-2 text-sm leading-6">
                            {playbook.rollback.summary}
                        </p>
                        <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                            {playbook.rollback.steps.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </div>
                </section>

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <h2 className="text-sm font-semibold">
                        WHMCS admin deep links
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Path hints only — open them in your WHMCS admin. No
                        secrets are embedded.
                    </p>
                    <ul className="mt-4 space-y-3">
                        {playbook.deep_links.map((link) => (
                            <li
                                key={link.label}
                                className="rounded-md border border-border bg-surface/50 px-3 py-2"
                            >
                                <p className="text-sm font-medium">
                                    {link.label}
                                </p>
                                <p className="mt-1 font-mono text-xs">
                                    {link.path}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {link.note}
                                </p>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
