import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { store } from '@/actions/App/Http/Controllers/SafeActionController';

type ActionDef = {
    key: string;
    label: string;
    description: string;
    risk_level: string;
    requires_password_confirm: boolean;
};

type Integration = {
    id: string;
    name: string;
    status: string;
    can_execute: boolean;
};

type Execution = {
    id: string;
    action_key: string;
    status: string;
    risk_level: string;
    requested_at: string | null;
    integration_name: string | null;
    requester_name: string | null;
};

type Props = {
    executions: {
        data: Execution[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    actions: ActionDef[];
    integrations: Integration[];
};

export default function SafeActionsIndex({
    executions,
    actions,
    integrations,
}: Props) {
    const { flash } = usePage().props as { flash?: { status?: string | null } };
    const executable = integrations.filter((item) => item.can_execute);

    return (
        <>
            <Head title="Safe Actions" />
            <div className="space-y-6">
                <PageHeader
                    title="Safe Actions"
                    description="Allowlisted, human-approved, audited actions only. No free-form AI repairs and no WHMCS writes from the model."
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/fix-center">Open Fix Center</Link>
                        </Button>
                    }
                />

                {flash?.status ? (
                    <p className="rounded-md border border-success/20 bg-success-soft px-3 py-2 text-sm text-success-fg">
                        {flash.status}
                    </p>
                ) : null}

                {executable.length > 0 ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            Request an allowlisted action
                        </h2>
                        <Form
                            {...store.form()}
                            className="mt-4 grid gap-4 sm:grid-cols-2"
                        >
                            {({ processing }) => (
                                <>
                                    <label className="space-y-1.5 text-sm">
                                        <span className="font-medium">
                                            Installation
                                        </span>
                                        <select
                                            name="integration_id"
                                            required
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                            defaultValue={executable[0]?.id}
                                        >
                                            {executable.map((integration) => (
                                                <option
                                                    key={integration.id}
                                                    value={integration.id}
                                                >
                                                    {integration.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="space-y-1.5 text-sm">
                                        <span className="font-medium">
                                            Action
                                        </span>
                                        <select
                                            name="action_key"
                                            required
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                            defaultValue={actions[0]?.key}
                                        >
                                            {actions.map((action) => (
                                                <option
                                                    key={action.key}
                                                    value={action.key}
                                                >
                                                    {action.label} (
                                                    {action.risk_level})
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <div className="sm:col-span-2">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Request approval
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                        <ul className="mt-4 space-y-2 text-sm text-muted-foreground">
                            {actions.map((action) => (
                                <li key={action.key}>
                                    <span className="font-medium text-foreground">
                                        {action.label}
                                    </span>
                                    {' — '}
                                    {action.description}
                                    {action.requires_password_confirm
                                        ? ' Requires password confirm + 2FA.'
                                        : ''}
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : (
                    <EmptyState
                        icon={ShieldCheck}
                        title="Safe Actions unavailable"
                        description="You need an operator role and a plan with the safe_actions entitlement (Growth / Agency+)."
                    />
                )}

                {executions.data.length === 0 ? (
                    <EmptyState
                        icon={ShieldCheck}
                        title="No safe action history yet"
                        description="Approved actions appear here with audit timestamps and verification evidence."
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">
                                Safe action executions
                            </caption>
                            <thead className="border-b border-border bg-surface text-xs font-medium text-muted-foreground">
                                <tr>
                                    <th className="h-9 px-4" scope="col">
                                        Action
                                    </th>
                                    <th className="h-9 px-4" scope="col">
                                        Installation
                                    </th>
                                    <th className="h-9 px-4" scope="col">
                                        Risk
                                    </th>
                                    <th className="h-9 px-4" scope="col">
                                        Status
                                    </th>
                                    <th className="h-9 px-4" scope="col">
                                        Requested by
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {executions.data.map((execution) => (
                                    <tr
                                        key={execution.id}
                                        className="h-11 border-b border-border last:border-0 hover:bg-surface/70"
                                    >
                                        <td className="px-4">
                                            <Link
                                                href={`/safe-actions/${execution.id}`}
                                                className="font-medium text-primary hover:underline"
                                            >
                                                {execution.action_key.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </Link>
                                        </td>
                                        <td className="px-4 text-muted-foreground">
                                            {execution.integration_name}
                                        </td>
                                        <td className="px-4">
                                            <StatusBadge
                                                status={execution.risk_level}
                                            />
                                        </td>
                                        <td className="px-4">
                                            <StatusBadge
                                                status={execution.status}
                                            />
                                        </td>
                                        <td className="px-4 text-muted-foreground">
                                            {execution.requester_name ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <Pagination links={executions.links} />
                    </div>
                )}
            </div>
        </>
    );
}
