import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/money';

type Plan = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    price_monthly_minor: number;
    price_yearly_minor: number;
    price_monthly: string;
    price_yearly: string;
    currency: string;
    trial_days: number;
    trial_enabled: boolean;
    features: string[];
    sort_order: number;
    is_active: boolean;
    is_public: boolean;
    is_contact_sales: boolean;
    monthly_active: boolean;
    yearly_active: boolean;
    prices_confirmed_at: string | null;
    code_locked: boolean;
    kelviq_plan_identifier_monthly: string | null;
    kelviq_plan_identifier_yearly: string | null;
    entitlements: {
        limits?: Record<string, number>;
        support_level?: string;
        'diagnostics.analysis'?: boolean;
        'diagnostics.reanalyze'?: boolean;
    };
};

type Props = {
    plans: Plan[];
    pricesArePlaceholders: boolean;
    canManage: boolean;
};

function FeatureListEditor({
    name,
    initial,
    disabled,
}: {
    name: string;
    initial: string[];
    disabled: boolean;
}) {
    const [lines, setLines] = useState<string[]>(
        initial.length > 0 ? initial : [],
    );

    function addLine() {
        if (lines.length >= 20) {
            return;
        }
        setLines([...lines, '']);
    }

    function removeLine(index: number) {
        setLines(lines.filter((_, i) => i !== index));
    }

    function moveLine(index: number, direction: -1 | 1) {
        const target = index + direction;
        if (target < 0 || target >= lines.length) {
            return;
        }
        const next = [...lines];
        const tmp = next[index];
        next[index] = next[target];
        next[target] = tmp;
        setLines(next);
    }

    return (
        <div className="space-y-2 md:col-span-2">
            <Label>Marketing features (optional)</Label>
            <p className="text-xs text-muted-foreground">
                Ordered bullet strings for the pricing page. Leave empty to keep
                entitlement-derived rows. Max 20 lines, 120 chars each.
            </p>
            {lines.map((line, index) => (
                <div key={index} className="flex gap-2">
                    <input type="hidden" name={`${name}[${index}]`} value={line} />
                    <Input
                        value={line}
                        maxLength={120}
                        disabled={disabled}
                        onChange={(e) => {
                            const next = [...lines];
                            next[index] = e.target.value;
                            setLines(next);
                        }}
                    />
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        disabled={disabled || index === 0}
                        onClick={() => moveLine(index, -1)}
                    >
                        ↑
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        disabled={disabled || index === lines.length - 1}
                        onClick={() => moveLine(index, 1)}
                    >
                        ↓
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        disabled={disabled}
                        onClick={() => removeLine(index)}
                    >
                        Remove
                    </Button>
                </div>
            ))}
            <Button
                type="button"
                variant="secondary"
                size="sm"
                disabled={disabled || lines.length >= 20}
                onClick={addLine}
            >
                Add feature
            </Button>
        </div>
    );
}

function PlanFields({
    plan,
    disabled,
}: {
    plan?: Plan;
    disabled: boolean;
}) {
    return (
        <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
                <Label>Code</Label>
                <Input
                    name="code"
                    defaultValue={plan?.code ?? ''}
                    required
                    disabled={disabled || plan?.code_locked}
                    readOnly={plan?.code_locked}
                />
                {plan?.code_locked ? (
                    <p className="text-xs text-muted-foreground">
                        Code is locked because subscriptions reference this plan.
                    </p>
                ) : null}
            </div>
            <div className="space-y-2">
                <Label>Name</Label>
                <Input
                    name="name"
                    defaultValue={plan?.name ?? ''}
                    required
                    disabled={disabled}
                />
            </div>
            <div className="space-y-2">
                <Label>Currency (ISO)</Label>
                <Input
                    name="currency"
                    defaultValue={plan?.currency ?? 'USD'}
                    required
                    disabled={disabled}
                    maxLength={3}
                />
            </div>
            <div className="space-y-2">
                <Label>Sort order</Label>
                <Input
                    name="sort_order"
                    type="number"
                    defaultValue={plan?.sort_order ?? 10}
                    required
                    disabled={disabled}
                />
            </div>
            <div className="space-y-2">
                <Label>Monthly price</Label>
                <Input
                    name="price_monthly"
                    type="number"
                    step="0.01"
                    min="0"
                    defaultValue={plan?.price_monthly ?? '0.00'}
                    required
                    disabled={disabled}
                />
            </div>
            <div className="space-y-2">
                <Label>Yearly price</Label>
                <Input
                    name="price_yearly"
                    type="number"
                    step="0.01"
                    min="0"
                    defaultValue={plan?.price_yearly ?? '0.00'}
                    required
                    disabled={disabled}
                />
            </div>
            <div className="space-y-2 md:col-span-2">
                <Label>Description</Label>
                <Input
                    name="description"
                    defaultValue={plan?.description ?? ''}
                    disabled={disabled}
                />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    name="monthly_active"
                    value="1"
                    defaultChecked={plan?.monthly_active ?? true}
                    disabled={disabled}
                />
                Monthly interval active
            </label>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    name="yearly_active"
                    value="1"
                    defaultChecked={plan?.yearly_active ?? true}
                    disabled={disabled}
                />
                Yearly interval active
            </label>
            <div className="space-y-2">
                <Label>Kelviq plan id (monthly)</Label>
                <Input
                    name="kelviq_plan_identifier_monthly"
                    defaultValue={plan?.kelviq_plan_identifier_monthly ?? ''}
                    disabled={disabled}
                />
            </div>
            <div className="space-y-2">
                <Label>Kelviq plan id (yearly)</Label>
                <Input
                    name="kelviq_plan_identifier_yearly"
                    defaultValue={plan?.kelviq_plan_identifier_yearly ?? ''}
                    disabled={disabled}
                />
            </div>
            <FeatureListEditor
                name="features"
                initial={plan?.features ?? []}
                disabled={disabled}
            />
            <div className="space-y-2">
                <Label>Members limit</Label>
                <Input
                    name="entitlements[limits][members]"
                    type="number"
                    defaultValue={plan?.entitlements.limits?.members ?? 0}
                    required
                    disabled={disabled}
                />
            </div>
            <div className="space-y-2">
                <Label>Integrations limit</Label>
                <Input
                    name="entitlements[limits][integrations]"
                    type="number"
                    defaultValue={plan?.entitlements.limits?.integrations ?? 0}
                    required
                    disabled={disabled}
                />
            </div>
            <div className="space-y-2">
                <Label>Diagnostics / period</Label>
                <Input
                    name="entitlements[limits][diagnostics_per_period]"
                    type="number"
                    defaultValue={
                        plan?.entitlements.limits?.diagnostics_per_period ?? 0
                    }
                    required
                    disabled={disabled}
                />
            </div>
            <div className="space-y-2">
                <Label>Retention days</Label>
                <Input
                    name="entitlements[limits][retention_days]"
                    type="number"
                    defaultValue={plan?.entitlements.limits?.retention_days ?? 30}
                    required
                    disabled={disabled}
                />
            </div>
            <div className="space-y-2">
                <Label>Support level</Label>
                <Input
                    name="entitlements[support_level]"
                    defaultValue={plan?.entitlements.support_level ?? 'email'}
                    disabled={disabled}
                />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    name="trial_enabled"
                    value="1"
                    defaultChecked={plan?.trial_enabled ?? false}
                    disabled={disabled}
                />
                Trial enabled
            </label>
            <div className="space-y-2">
                <Label>Trial days (1–90 when enabled)</Label>
                <Input
                    name="trial_days"
                    type="number"
                    min={0}
                    max={90}
                    defaultValue={plan?.trial_days ?? 0}
                    required
                    disabled={disabled}
                />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    name="is_active"
                    value="1"
                    defaultChecked={plan?.is_active ?? true}
                    disabled={disabled}
                />
                Active
            </label>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    name="is_public"
                    value="1"
                    defaultChecked={plan?.is_public ?? true}
                    disabled={disabled}
                />
                Public
            </label>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    name="is_contact_sales"
                    value="1"
                    defaultChecked={plan?.is_contact_sales ?? false}
                    disabled={disabled}
                />
                Contact sales only
            </label>
        </div>
    );
}

export default function PlatformPlansPage({
    plans,
    pricesArePlaceholders,
    canManage,
}: Props) {
    return (
        <>
            <Head title="Plan catalog" />
            <div className="mx-auto max-w-5xl space-y-8">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Plans
                        </h1>
                        {pricesArePlaceholders && (
                            <p className="mt-2 text-sm text-warning-fg">
                                Some public plans still have unconfirmed prices.
                                Confirm them before launch.
                            </p>
                        )}
                        {!canManage && (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Read-only — you need permission to manage
                                billing settings to edit plans.
                            </p>
                        )}
                    </div>
                    <Link
                        href="/platform/billing"
                        className="text-sm text-primary"
                    >
                        Back to billing
                    </Link>
                </div>

                <p className="rounded-md border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                    Price changes apply to new checkouts and to plan changes
                    from now on. Existing subscriptions keep the price stored at
                    purchase until renewal. Kelviq renewals bill the price
                    configured on the Kelviq plan, so also update the price in
                    Kelviq.
                </p>

                {canManage && (
                    <Form
                        action="/platform/billing/plans"
                        method="post"
                        className="space-y-4 rounded-lg border border-border bg-white p-5"
                    >
                        {({ processing }) => (
                            <>
                                <h2 className="text-base font-semibold">
                                    Create plan
                                </h2>
                                <PlanFields disabled={false} />
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="bg-primary hover:bg-primary-hover"
                                >
                                    Create plan
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                <div className="space-y-6">
                    {plans.map((plan) => (
                        <div
                            key={plan.id}
                            className="space-y-4 rounded-lg border border-border bg-white p-5"
                        >
                            <Form
                                action={`/platform/billing/plans/${plan.id}`}
                                method="put"
                                className="space-y-4"
                            >
                                {({ processing }) => (
                                    <>
                                        <div className="flex items-center justify-between">
                                            <h2 className="text-base font-semibold">
                                                {plan.name}{' '}
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {plan.code}
                                                </span>
                                                {!plan.is_active && (
                                                    <span className="ml-2 text-xs text-warning-fg">
                                                        deactivated
                                                    </span>
                                                )}
                                            </h2>
                                            <p className="text-sm text-muted-foreground tabular-nums">
                                                {formatMoney(
                                                    plan.price_monthly_minor,
                                                    plan.currency,
                                                )}
                                                /mo
                                            </p>
                                        </div>
                                        <PlanFields
                                            plan={plan}
                                            disabled={!canManage}
                                        />
                                        {canManage && (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="bg-primary hover:bg-primary-hover"
                                            >
                                                Save plan
                                            </Button>
                                        )}
                                    </>
                                )}
                            </Form>

                            {canManage && (
                                <div className="flex flex-wrap gap-2 border-t border-border pt-4">
                                    {plan.is_active ? (
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() =>
                                                router.post(
                                                    `/platform/billing/plans/${plan.id}/deactivate`,
                                                )
                                            }
                                        >
                                            Deactivate
                                        </Button>
                                    ) : (
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() =>
                                                router.post(
                                                    `/platform/billing/plans/${plan.id}/reactivate`,
                                                )
                                            }
                                        >
                                            Reactivate
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={plan.code_locked}
                                        onClick={() => {
                                            if (
                                                confirm(
                                                    plan.code_locked
                                                        ? 'This plan is referenced and cannot be deleted.'
                                                        : 'Delete this plan permanently?',
                                                )
                                            ) {
                                                router.delete(
                                                    `/platform/billing/plans/${plan.id}`,
                                                );
                                            }
                                        }}
                                    >
                                        Delete
                                    </Button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
