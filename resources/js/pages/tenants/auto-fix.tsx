import { Form, Head, Link } from '@inertiajs/react';
import { Sparkles, Wrench } from 'lucide-react';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Installation = {
    id: string;
    name: string;
    status: string;
    auto_collect_enabled: boolean;
    last_heartbeat_at: string | null;
};

type Action = {
    key: string;
    label: string;
    description: string;
    risk_level: string;
};

type Policy = {
    id: string;
    action_key: string;
    integration_id: string | null;
    enabled: boolean;
    daily_cap: number | null;
};

type Props = {
    tenant: { id: string; name: string };
    canManage: boolean;
    proEnabled: boolean;
    billingPlansUrl: string;
    integrationsUrl: string;
    installations: Installation[];
    actions: Action[];
    policies: Policy[];
    updateUrl: string;
    status?: string;
};

function policyFor(
    policies: Policy[],
    actionKey: string,
    integrationId: string | null,
): Policy | undefined {
    return policies.find(
        (policy) =>
            policy.action_key === actionKey &&
            (integrationId === null
                ? policy.integration_id === null
                : policy.integration_id === integrationId),
    );
}

export default function TenantAutoFixSettings({
    tenant,
    canManage,
    proEnabled,
    billingPlansUrl,
    integrationsUrl,
    installations,
    actions,
    policies,
    updateUrl,
    status,
}: Props) {
    const manageEnabled = canManage && proEnabled;
    const scopes: Array<{ id: string | null; name: string }> = [
        { id: null, name: 'All installations' },
        ...installations.map((installation) => ({
            id: installation.id,
            name: installation.name,
        })),
    ];

    return (
        <>
            <Head title="Auto-fix" />
            <div className="space-y-6">
                <PageHeader
                    title="Auto-fix"
                    description={`Allowlisted automatic fixes for ${tenant.name}.`}
                />

                {status ? (
                    <p
                        role="status"
                        className="rounded-md border border-success/20 bg-success-soft px-3 py-2 text-sm text-success-fg"
                    >
                        {status}
                    </p>
                ) : null}

                {!proEnabled ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <div className="flex items-start gap-3">
                            <Sparkles
                                className="mt-0.5 size-5 text-brand"
                                aria-hidden
                            />
                            <div className="space-y-2">
                                <h2 className="text-sm font-semibold">
                                    Auto-fix is a Pro feature
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Upgrade to Pro to enable automatic
                                    allowlisted fixes after rule detection.
                                    Refunds, terminations, invoice edits,
                                    payments, gateway changes, GenInvoices,
                                    order acceptance, unsuspend, and upgrades
                                    can never run automatically.
                                </p>
                                <Button asChild size="sm">
                                    <Link href={billingPlansUrl}>
                                        View plans
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </section>
                ) : null}

                {installations.length === 0 ? (
                    <EmptyState
                        icon={Wrench}
                        title="No installations yet"
                        description="Connect a WHMCS installation before configuring auto-fix policies."
                        action={
                            canManage ? (
                                <Button size="sm" asChild>
                                    <Link href={integrationsUrl}>
                                        Connect an installation
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : null}

                {proEnabled && actions.length > 0 ? (
                    <Form
                        action={updateUrl}
                        method="put"
                        className="space-y-4"
                        options={{ preserveScroll: true }}
                    >
                        {({ errors, processing }) => (
                            <>
                                {actions.map((action, actionIndex) => (
                                    <section
                                        key={action.key}
                                        className="overflow-hidden rounded-lg border border-border bg-card shadow-xs"
                                    >
                                        <div className="border-b border-border px-5 py-4">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h2 className="text-sm font-semibold">
                                                    {action.label}
                                                </h2>
                                                <span className="rounded-md bg-muted px-2 py-0.5 text-xs uppercase tracking-wide text-muted-foreground">
                                                    {action.risk_level} risk
                                                </span>
                                            </div>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {action.description}
                                            </p>
                                        </div>
                                        <ul className="divide-y divide-border">
                                            {scopes.map((scope, scopeIndex) => {
                                                const existing = policyFor(
                                                    policies,
                                                    action.key,
                                                    scope.id,
                                                );
                                                const rowIndex =
                                                    actionIndex * scopes.length +
                                                    scopeIndex;

                                                return (
                                                    <li
                                                        key={`${action.key}-${scope.id ?? 'all'}`}
                                                        className="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1fr)_auto_8rem] md:items-center"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name={`policies[${rowIndex}][action_key]`}
                                                            value={action.key}
                                                        />
                                                        <input
                                                            type="hidden"
                                                            name={`policies[${rowIndex}][integration_id]`}
                                                            value={
                                                                scope.id ?? ''
                                                            }
                                                        />
                                                        <div>
                                                            <p className="text-sm font-medium">
                                                                {scope.name}
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {scope.id ===
                                                                null
                                                                    ? 'Applies when no installation-specific policy is enabled.'
                                                                    : 'Overrides the all-installations policy for this host.'}
                                                            </p>
                                                        </div>
                                                        <label className="flex items-center gap-2 text-sm">
                                                            <input
                                                                type="hidden"
                                                                name={`policies[${rowIndex}][enabled]`}
                                                                value="0"
                                                            />
                                                            <input
                                                                type="checkbox"
                                                                name={`policies[${rowIndex}][enabled]`}
                                                                value="1"
                                                                defaultChecked={
                                                                    existing?.enabled ??
                                                                    false
                                                                }
                                                                disabled={
                                                                    !manageEnabled
                                                                }
                                                            />
                                                            Enabled
                                                        </label>
                                                        <div className="space-y-1">
                                                            <Label
                                                                htmlFor={`cap-${rowIndex}`}
                                                            >
                                                                Daily cap
                                                            </Label>
                                                            <Input
                                                                id={`cap-${rowIndex}`}
                                                                type="number"
                                                                min={1}
                                                                max={65535}
                                                                name={`policies[${rowIndex}][daily_cap]`}
                                                                defaultValue={
                                                                    existing?.daily_cap ??
                                                                    ''
                                                                }
                                                                placeholder="None"
                                                                disabled={
                                                                    !manageEnabled
                                                                }
                                                            />
                                                        </div>
                                                        <InputError
                                                            className="md:col-span-3"
                                                            message={
                                                                errors[
                                                                    `policies.${rowIndex}.action_key`
                                                                ] ??
                                                                errors[
                                                                    `policies.${rowIndex}.daily_cap`
                                                                ] ??
                                                                errors[
                                                                    `policies.${rowIndex}.integration_id`
                                                                ]
                                                            }
                                                        />
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </section>
                                ))}

                                <InputError message={errors.policies} />

                                {manageEnabled ? (
                                    <div className="flex justify-end">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save auto-fix policies
                                        </Button>
                                    </div>
                                ) : null}
                            </>
                        )}
                    </Form>
                ) : null}
            </div>
        </>
    );
}
