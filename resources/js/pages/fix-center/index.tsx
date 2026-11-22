import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Wrench } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SeverityBadge } from '@/components/severity-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    approve,
    store as storeSafeAction,
} from '@/actions/App/Http/Controllers/SafeActionController';

type GuidedStep = {
    step_index: number;
    action: string;
    completed: boolean;
    completed_at: string | null;
    completed_by_user_id: number | null;
};

type SuggestedAction = {
    key: string;
    label: string;
    description: string;
    risk_level: string;
    requires_password_confirm: boolean;
    bulk_allowed: boolean;
    max_items: number;
    can_one_click: boolean;
    can_bulk: boolean;
};

type Issue = {
    id: string;
    title: string;
    why_it_matters: string;
    affected_count: number | null;
    installation: string;
    installation_id: string | null;
    severity: string;
    status: string;
    tab: string;
    issue_key: string | null;
    fix_mode: string;
    primary_action: string;
    can_one_click: boolean;
    can_bulk: boolean;
    can_guide: boolean;
    upgrade_to_one_click: boolean;
    bulk_max_items: number;
    ignored_until: string | null;
    whmcs_admin_path: string | null;
    guided_steps: GuidedStep[];
    suggested_actions: SuggestedAction[];
    blocked_action_keys: string[];
    affected_ids: number[];
};

type Props = {
    issues: {
        data: Issue[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { tab: string };
    counts: { open: number; fixing: number; fixed: number; ignored: number };
    entitlements: {
        fixes_guided: boolean;
        fixes_one_click: boolean;
        fixes_bulk: boolean;
        bulk_max_items: number;
    };
    billing_plans_url: string;
};

const tabs = [
    { value: 'open', label: 'Open' },
    { value: 'fixing', label: 'Fixing' },
    { value: 'fixed', label: 'Fixed' },
    { value: 'ignored', label: 'Ignored' },
] as const;

export default function FixCenterIndex({
    issues,
    filters,
    counts,
    entitlements,
    billing_plans_url,
}: Props) {
    const { flash } = usePage().props as { flash?: { status?: string | null } };
    const [selected, setSelected] = useState<string[]>([]);
    const [activeIssue, setActiveIssue] = useState<Issue | null>(null);
    const [drawerMode, setDrawerMode] = useState<
        'guide' | 'fix' | 'info' | null
    >(null);
    const [fixPhase, setFixPhase] = useState<
        'preview' | 'approve' | 'status' | 'verified'
    >('preview');
    const [selectedAction, setSelectedAction] =
        useState<SuggestedAction | null>(null);
    const [targetIds, setTargetIds] = useState<number[]>([]);
    const [pendingFixId, setPendingFixId] = useState<string | null>(null);

    const bulkCapable = useMemo(
        () =>
            issues.data.filter(
                (issue) =>
                    issue.can_bulk &&
                    issue.suggested_actions.some((action) => action.can_bulk),
            ),
        [issues.data],
    );

    const setTab = (tab: string) => {
        router.get(
            '/fix-center',
            { tab },
            { preserveState: true, replace: true },
        );
        setSelected([]);
    };

    const openDrawer = (issue: Issue, mode: 'guide' | 'fix' | 'info') => {
        setActiveIssue(issue);
        setDrawerMode(mode);
        setFixPhase('preview');
        const action = issue.suggested_actions[0] ?? null;
        setSelectedAction(action);
        setTargetIds(issue.affected_ids.slice(0, issue.bulk_max_items));
        setPendingFixId(null);
    };

    const closeDrawer = () => {
        setActiveIssue(null);
        setDrawerMode(null);
        setPendingFixId(null);
    };

    const toggleSelected = (id: string, enabled: boolean) => {
        setSelected((prev) => {
            if (enabled) {
                return prev.includes(id) ? prev : [...prev, id];
            }
            return prev.filter((item) => item !== id);
        });
    };

    const requestOneClick = () => {
        if (!activeIssue?.installation_id || !selectedAction) {
            return;
        }

        router.post(
            storeSafeAction.url(),
            {
                integration_id: activeIssue.installation_id,
                action_key: selectedAction.key,
                finding_id: activeIssue.id,
                input: {
                    mode:
                        targetIds.length > 1 && selectedAction.can_bulk
                            ? 'bulk'
                            : 'one_click',
                    target_ids: targetIds,
                },
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const url = String(page.url ?? '');
                    const match = url.match(/\/safe-actions\/([a-f0-9-]+)/i);
                    if (match?.[1]) {
                        setPendingFixId(match[1]);
                        setFixPhase('approve');
                    }
                },
            },
        );
    };

    const approveFix = (reason: string) => {
        if (!pendingFixId) {
            return;
        }
        router.post(
            approve.url(pendingFixId),
            { approval_reason: reason },
            {
                preserveScroll: true,
                onSuccess: () => setFixPhase('status'),
            },
        );
    };

    return (
        <>
            <Head title="Fix Center" />
            <div className="space-y-6">
                <PageHeader
                    title="Fix Center"
                    description="One place to see WHMCS issues and fix them — guided steps or one-click when your plan allows."
                />

                {flash?.status ? (
                    <p className="rounded-md border border-success/20 bg-success-soft px-3 py-2 text-sm text-success-fg">
                        {flash.status}
                    </p>
                ) : null}

                <div className="flex flex-wrap gap-2">
                    {tabs.map((tab) => (
                        <Button
                            key={tab.value}
                            type="button"
                            size="sm"
                            variant={
                                filters.tab === tab.value
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => setTab(tab.value)}
                        >
                            {tab.label}
                            <span className="ml-1 text-xs tabular-nums opacity-80">
                                {counts[tab.value]}
                            </span>
                        </Button>
                    ))}
                </div>

                {entitlements.fixes_bulk &&
                bulkCapable.length > 0 &&
                filters.tab === 'open' ? (
                    <div className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-surface px-3 py-2 text-sm">
                        <span className="text-muted-foreground">
                            {selected.length} selected (max{' '}
                            {entitlements.bulk_max_items} items per fix)
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={selected.length === 0}
                            onClick={() => {
                                const first = issues.data.find(
                                    (issue) =>
                                        selected.includes(issue.id) &&
                                        issue.can_one_click,
                                );
                                if (first) {
                                    openDrawer(first, 'fix');
                                }
                            }}
                        >
                            Fix selected
                        </Button>
                    </div>
                ) : null}

                {issues.data.length === 0 ? (
                    <EmptyState
                        icon={
                            filters.tab === 'fixed' || filters.tab === 'open'
                                ? CheckCircle2
                                : Wrench
                        }
                        title={
                            filters.tab === 'open'
                                ? 'No open issues'
                                : `No ${filters.tab} issues`
                        }
                        description="When a scan finds something to fix, it shows up here in plain language."
                        action={
                            <Button size="sm" asChild>
                                <Link href="/integrations">
                                    Check installations
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="space-y-3">
                        {issues.data.map((issue) => {
                            const canBulkSelect =
                                entitlements.fixes_bulk && issue.can_bulk;

                            return (
                                <article
                                    key={issue.id}
                                    className="rounded-lg border border-border bg-card p-4 shadow-xs"
                                >
                                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div className="flex min-w-0 flex-1 gap-3">
                                            {canBulkSelect ? (
                                                <Checkbox
                                                    checked={selected.includes(
                                                        issue.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggleSelected(
                                                            issue.id,
                                                            checked === true,
                                                        )
                                                    }
                                                    aria-label={`Select ${issue.title}`}
                                                    className="mt-1"
                                                />
                                            ) : null}
                                            <div className="min-w-0 space-y-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h2 className="text-sm font-semibold text-foreground">
                                                        {issue.title}
                                                    </h2>
                                                    <SeverityBadge
                                                        severity={
                                                            issue.severity
                                                        }
                                                    />
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    {issue.why_it_matters}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {issue.installation}
                                                    {issue.affected_count !=
                                                    null
                                                        ? ` · ${issue.affected_count} affected`
                                                        : null}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                                            {issue.primary_action ===
                                            'fix_now' ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    onClick={() =>
                                                        openDrawer(issue, 'fix')
                                                    }
                                                >
                                                    Fix now
                                                </Button>
                                            ) : null}
                                            {issue.primary_action ===
                                            'start_guide' ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    onClick={() =>
                                                        openDrawer(
                                                            issue,
                                                            'guide',
                                                        )
                                                    }
                                                >
                                                    Start guide
                                                </Button>
                                            ) : null}
                                            {issue.primary_action ===
                                            'learn_more' ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        openDrawer(
                                                            issue,
                                                            'info',
                                                        )
                                                    }
                                                >
                                                    Learn more
                                                </Button>
                                            ) : null}

                                            {filters.tab === 'ignored' ? (
                                                <Form
                                                    action={`/fix-center/${issue.id}/unignore`}
                                                    method="post"
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Un-ignore
                                                        </Button>
                                                    )}
                                                </Form>
                                            ) : filters.tab !== 'fixed' ? (
                                                <div className="flex flex-wrap gap-1">
                                                    {(
                                                        [
                                                            [
                                                                '7_days',
                                                                '7 days',
                                                            ],
                                                            [
                                                                '30_days',
                                                                '30 days',
                                                            ],
                                                            [
                                                                'forever',
                                                                'Forever',
                                                            ],
                                                        ] as const
                                                    ).map(([value, label]) => (
                                                        <Form
                                                            key={value}
                                                            action={`/fix-center/${issue.id}/ignore`}
                                                            method="post"
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <>
                                                                    <input
                                                                        type="hidden"
                                                                        name="snooze"
                                                                        value={
                                                                            value
                                                                        }
                                                                    />
                                                                    <Button
                                                                        type="submit"
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        disabled={
                                                                            processing
                                                                        }
                                                                    >
                                                                        Ignore{' '}
                                                                        {label}
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </Form>
                                                    ))}
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                </article>
                            );
                        })}
                        <Pagination links={issues.links} />
                    </div>
                )}
            </div>

            <Sheet
                open={activeIssue !== null && drawerMode !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        closeDrawer();
                    }
                }}
            >
                <SheetContent
                    side="right"
                    className="w-full overflow-y-auto sm:max-w-lg"
                >
                    {activeIssue && drawerMode === 'guide' ? (
                        <>
                            <SheetHeader>
                                <SheetTitle>{activeIssue.title}</SheetTitle>
                                <SheetDescription>
                                    Follow these steps in WHMCS, then ask us to
                                    check again.
                                </SheetDescription>
                            </SheetHeader>
                            <div className="space-y-4 px-4">
                                {activeIssue.whmcs_admin_path ? (
                                    <p className="rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                        <span className="font-medium">
                                            WHMCS admin path:{' '}
                                        </span>
                                        {activeIssue.whmcs_admin_path}
                                    </p>
                                ) : null}
                                <ol className="space-y-3">
                                    {activeIssue.guided_steps.map((step) => (
                                        <li
                                            key={step.step_index}
                                            className="flex gap-3 rounded-md border border-border p-3"
                                        >
                                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">
                                                {step.step_index}
                                            </span>
                                            <div className="min-w-0 flex-1 space-y-2">
                                                <p className="text-sm">
                                                    {step.action}
                                                </p>
                                                {step.completed ? (
                                                    <p className="text-xs text-success-fg">
                                                        Done
                                                    </p>
                                                ) : (
                                                    <Form
                                                        action={`/fix-center/${activeIssue.id}/guided-steps`}
                                                        method="post"
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                        onSuccess={() => {
                                                            router.reload({
                                                                only: [
                                                                    'issues',
                                                                    'counts',
                                                                ],
                                                            });
                                                        }}
                                                    >
                                                        {({ processing }) => (
                                                            <>
                                                                <input
                                                                    type="hidden"
                                                                    name="step_index"
                                                                    value={
                                                                        step.step_index
                                                                    }
                                                                />
                                                                <Button
                                                                    type="submit"
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                >
                                                                    Mark step
                                                                    done
                                                                </Button>
                                                            </>
                                                        )}
                                                    </Form>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                                {activeIssue.upgrade_to_one_click ? (
                                    <p className="text-sm text-muted-foreground">
                                        This can be fixed in one click on
                                        Growth.{' '}
                                        <Link
                                            href={billing_plans_url}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            Upgrade to fix in one click
                                        </Link>
                                    </p>
                                ) : null}
                            </div>
                            <SheetFooter>
                                <Form
                                    action={`/fix-center/${activeIssue.id}/complete-guide`}
                                    method="post"
                                    options={{ preserveScroll: true }}
                                    onSuccess={() => closeDrawer()}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                !activeIssue.can_guide
                                            }
                                        >
                                            I did it, check again
                                        </Button>
                                    )}
                                </Form>
                            </SheetFooter>
                        </>
                    ) : null}

                    {activeIssue && drawerMode === 'fix' ? (
                        <>
                            <SheetHeader>
                                <SheetTitle>Fix now</SheetTitle>
                                <SheetDescription>
                                    Preview what will change, approve, then
                                    watch status until verified.
                                </SheetDescription>
                            </SheetHeader>
                            <div className="space-y-4 px-4">
                                <p className="text-sm font-medium">
                                    {activeIssue.title}
                                </p>
                                {selectedAction ? (
                                    <div className="space-y-2 rounded-md border border-border p-3 text-sm">
                                        <p className="font-medium">
                                            {selectedAction.label}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {selectedAction.description}
                                        </p>
                                        {targetIds.length > 0 ? (
                                            <p className="text-xs text-muted-foreground">
                                                Targets: {targetIds.join(', ')}
                                            </p>
                                        ) : null}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No one-click fix is available for this
                                        issue.
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    Phase:{' '}
                                    {fixPhase === 'preview'
                                        ? 'Preview'
                                        : fixPhase === 'approve'
                                          ? 'Approve'
                                          : fixPhase === 'status'
                                            ? 'Live status'
                                            : 'Verified'}
                                </p>
                            </div>
                            <SheetFooter className="gap-2">
                                {fixPhase === 'preview' && selectedAction ? (
                                    <Button
                                        type="button"
                                        onClick={requestOneClick}
                                    >
                                        Continue to approve
                                    </Button>
                                ) : null}
                                {fixPhase === 'approve' && pendingFixId ? (
                                    <Button
                                        type="button"
                                        onClick={() =>
                                            approveFix(
                                                'Approved from Fix Center',
                                            )
                                        }
                                    >
                                        Approve fix
                                    </Button>
                                ) : null}
                                {fixPhase === 'status' ||
                                fixPhase === 'verified' ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        asChild
                                    >
                                        <Link
                                            href={
                                                pendingFixId
                                                    ? `/safe-actions/${pendingFixId}`
                                                    : '/safe-actions'
                                            }
                                        >
                                            View fix status
                                        </Link>
                                    </Button>
                                ) : null}
                            </SheetFooter>
                        </>
                    ) : null}

                    {activeIssue && drawerMode === 'info' ? (
                        <>
                            <SheetHeader>
                                <SheetTitle>{activeIssue.title}</SheetTitle>
                                <SheetDescription>
                                    {activeIssue.why_it_matters}
                                </SheetDescription>
                            </SheetHeader>
                            <div className="space-y-3 px-4 text-sm">
                                {activeIssue.whmcs_admin_path ? (
                                    <p>
                                        <span className="font-medium">
                                            WHMCS admin path:{' '}
                                        </span>
                                        {activeIssue.whmcs_admin_path}
                                    </p>
                                ) : null}
                                <ol className="list-decimal space-y-2 pl-4">
                                    {activeIssue.guided_steps.map((step) => (
                                        <li key={step.step_index}>
                                            {step.action}
                                        </li>
                                    ))}
                                </ol>
                            </div>
                            <SheetFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={closeDrawer}
                                >
                                    Close
                                </Button>
                            </SheetFooter>
                        </>
                    ) : null}
                </SheetContent>
            </Sheet>
        </>
    );
}
