import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/status-badge';
import InputError from '@/components/input-error';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatMoney } from '@/lib/money';

type ScheduledChange = {
    plan_id: number;
    plan_code: string;
    plan_name: string;
    charge_period: string;
    change_at: string;
};

type CancelValueReminder = {
    time_saved_hours_display: string;
    time_saved_value_display: string;
    hourly_rate_usd: number;
    issues_fixed: number;
    open_critical: number;
};

type Props = {
    tenant: { id: string; name: string };
    timezone?: string;
    cancelValueReminder?: CancelValueReminder | null;
    subscription: {
        id: number;
        plan_code: string;
        plan_name: string;
        status: string;
        currency: string;
        unit_amount_minor: number;
        billing_period: string;
        cancel_at_period_end: boolean;
        current_period_ends_at: string | null;
        trial_ends_at?: string | null;
        trial_days_remaining?: number | null;
        is_convertible_trial?: boolean;
        entitlements: { limits?: Record<string, number> };
    } | null;
    scheduledChange?: ScheduledChange | null;
    usage: { members: number; integrations: number; diagnostics: number };
    invoices: Array<{
        id: number;
        invoice_number: string;
        status: string;
        currency: string;
        amount_due_minor: number;
        issued_at: string | null;
        service_period_starts_at?: string | null;
        service_period_ends_at?: string | null;
    }>;
    payments: Array<{
        id: number;
        amount_minor: number;
        currency: string;
        status: string;
        received_at: string | null;
    }>;
    plans: Array<{
        id: number;
        code: string;
        name: string;
        price_monthly_minor: number;
        currency: string;
        is_contact_sales: boolean;
    }>;
    billingDetails: Record<string, string>;
    countries: Array<{ code: string; name: string }>;
    canManage: boolean;
    status?: string;
};

function usagePercent(used: number, limit: number | undefined): number {
    if (!limit || limit <= 0) {
        return 0;
    }

    return Math.min(100, Math.round((used / limit) * 100));
}

function UsageBar({
    label,
    used,
    limit,
}: {
    label: string;
    used: number;
    limit: number | undefined;
}) {
    const percent = usagePercent(used, limit);

    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between text-sm">
                <span>{label}</span>
                <span className="text-muted-foreground tabular-nums">
                    {used}/{limit ?? '—'}
                </span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full bg-primary transition-[width]"
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}

export default function BillingSettingsPage(props: Props) {
    const {
        tenant,
        timezone,
        cancelValueReminder,
        subscription,
        scheduledChange,
        usage,
        invoices,
        payments,
        plans,
        billingDetails,
        countries,
        canManage,
        status,
    } = props;
    const limits = subscription?.entitlements?.limits ?? {};
    const periodLabel =
        subscription?.billing_period === 'yearly' ? 'year' : 'month';
    const checkoutError = (
        usePage().props.errors as { checkout?: string } | undefined
    )?.checkout;

    return (
        <>
            <Head title="Billing settings" />
            <div className="mx-auto max-w-5xl space-y-8 p-6">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">
                        Billing
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {tenant.name}
                    </p>
                    {status && (
                        <p
                            role="status"
                            className="mt-3 rounded-md border border-success/20 bg-success-soft px-4 py-3 text-sm text-success-fg"
                        >
                            {status}
                        </p>
                    )}
                    {checkoutError && (
                        <p
                            role="alert"
                            className="mt-3 rounded-md border border-destructive/20 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                        >
                            {checkoutError}
                        </p>
                    )}
                    {scheduledChange && subscription && (
                        <div
                            role="status"
                            className="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-surface px-4 py-3 text-sm"
                        >
                            <p>
                                Your plan changes to {scheduledChange.plan_name}{' '}
                                ({scheduledChange.charge_period}) on{' '}
                                {formatDate(scheduledChange.change_at, {
                                    timeZone: timezone,
                                })}
                                .
                            </p>
                            {canManage && (
                                <Form
                                    action={`/tenants/${tenant.id}/billing/subscriptions/${subscription.id}/scheduled-change`}
                                    method="delete"
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Cancel scheduled change
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </div>
                    )}
                </div>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Current plan</h2>
                    {subscription ? (
                        <div className="mt-4 space-y-4 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-base font-semibold">
                                    {subscription.plan_name}
                                </span>
                                <StatusBadge status={subscription.status} />
                            </div>
                            <p className="text-muted-foreground tabular-nums">
                                {formatMoney(
                                    subscription.unit_amount_minor,
                                    subscription.currency,
                                )}{' '}
                                / {periodLabel}
                            </p>
                            {subscription.is_convertible_trial &&
                            subscription.trial_ends_at ? (
                                <p className="text-muted-foreground">
                                    Trial ends on{' '}
                                    {formatDate(subscription.trial_ends_at, {
                                        timeZone: timezone,
                                    })}
                                    , {subscription.trial_days_remaining ?? 0}{' '}
                                    {(subscription.trial_days_remaining ??
                                        0) === 1
                                        ? 'day'
                                        : 'days'}{' '}
                                    left
                                </p>
                            ) : (
                                <p className="text-muted-foreground">
                                    Renews / period ends:{' '}
                                    {formatDate(
                                        subscription.current_period_ends_at,
                                        { timeZone: timezone },
                                    )}
                                </p>
                            )}
                            {subscription.cancel_at_period_end && (
                                <p className="text-warning-fg">
                                    Cancels at period end
                                </p>
                            )}
                            <div className="mt-2 grid gap-4 md:grid-cols-3">
                                <UsageBar
                                    label="Members"
                                    used={usage.members}
                                    limit={limits.members}
                                />
                                <UsageBar
                                    label="Integrations"
                                    used={usage.integrations}
                                    limit={limits.integrations}
                                />
                                <UsageBar
                                    label="Diagnostics"
                                    used={usage.diagnostics}
                                    limit={limits.diagnostics_per_period}
                                />
                            </div>
                            {canManage && (
                                <div className="mt-4 flex flex-wrap gap-3">
                                    {subscription.is_convertible_trial ? (
                                        <Button
                                            asChild
                                            className="bg-primary hover:bg-primary-hover"
                                        >
                                            <Link href="/billing/plans">
                                                Subscribe now
                                            </Link>
                                        </Button>
                                    ) : (
                                        <>
                                            {!subscription.cancel_at_period_end ? (
                                                <div className="space-y-3">
                                                    {cancelValueReminder && (
                                                        <div
                                                            role="status"
                                                            className="rounded-md border border-border bg-surface px-4 py-3 text-sm text-muted-foreground"
                                                        >
                                                            <p>
                                                                This period you
                                                                saved{' '}
                                                                <span className="font-medium text-foreground">
                                                                    {
                                                                        cancelValueReminder.time_saved_hours_display
                                                                    }{' '}
                                                                    hours
                                                                </span>{' '}
                                                                (about $
                                                                {
                                                                    cancelValueReminder.time_saved_value_display
                                                                }
                                                                ), fixed{' '}
                                                                <span className="font-medium text-foreground">
                                                                    {
                                                                        cancelValueReminder.issues_fixed
                                                                    }
                                                                </span>{' '}
                                                                issues
                                                                {cancelValueReminder.open_critical >
                                                                0
                                                                    ? `, and still have ${cancelValueReminder.open_critical} open critical issue${cancelValueReminder.open_critical === 1 ? '' : 's'}`
                                                                    : ''}
                                                                .
                                                            </p>
                                                        </div>
                                                    )}
                                                    <Form
                                                        action={`/tenants/${tenant.id}/billing/subscriptions/${subscription.id}/cancel`}
                                                        method="post"
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                variant="outline"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Cancel at period
                                                                end
                                                            </Button>
                                                        )}
                                                    </Form>
                                                </div>
                                            ) : (
                                                <Form
                                                    action={`/tenants/${tenant.id}/billing/subscriptions/${subscription.id}/resume`}
                                                    method="post"
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
                                                            className="bg-primary hover:bg-primary-hover"
                                                        >
                                                            Resume
                                                        </Button>
                                                    )}
                                                </Form>
                                            )}
                                            <Button asChild variant="outline">
                                                <Link href="/billing/plans">
                                                    Change plan
                                                </Link>
                                            </Button>
                                        </>
                                    )}
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="mt-4 space-y-3">
                            <p className="text-sm text-muted-foreground">
                                No active subscription.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {plans
                                    .filter((plan) => !plan.is_contact_sales)
                                    .map((plan) => (
                                        <Button
                                            key={plan.id}
                                            asChild
                                            className="bg-primary hover:bg-primary-hover"
                                        >
                                            <Link
                                                href={`/tenants/${tenant.id}/billing/checkout/${plan.id}?period=monthly`}
                                            >
                                                {plan.name} (
                                                {formatMoney(
                                                    plan.price_monthly_minor,
                                                    plan.currency,
                                                )}
                                                )
                                            </Link>
                                        </Button>
                                    ))}
                            </div>
                        </div>
                    )}
                </section>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Billing details</h2>
                    <Form
                        action={`/tenants/${tenant.id}/billing/details`}
                        method="put"
                        className="mt-4 grid gap-4 md:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="company_name">
                                        Company
                                    </Label>
                                    <Input
                                        id="company_name"
                                        name="company_name"
                                        defaultValue={
                                            billingDetails.company_name ?? ''
                                        }
                                        disabled={!canManage}
                                        required
                                    />
                                    <InputError message={errors.company_name} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="billing_email">Email</Label>
                                    <Input
                                        id="billing_email"
                                        name="billing_email"
                                        type="email"
                                        defaultValue={
                                            billingDetails.billing_email ?? ''
                                        }
                                        disabled={!canManage}
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="country">Country</Label>
                                    <select
                                        id="country"
                                        name="country"
                                        defaultValue={
                                            billingDetails.country ?? 'US'
                                        }
                                        disabled={!canManage}
                                        className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                    >
                                        {countries.map((country) => (
                                            <option
                                                key={country.code}
                                                value={country.code}
                                            >
                                                {country.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="space-y-2 md:col-span-2">
                                    <Label htmlFor="address_line1">
                                        Address
                                    </Label>
                                    <Input
                                        id="address_line1"
                                        name="address_line1"
                                        defaultValue={
                                            billingDetails.address_line1 ?? ''
                                        }
                                        disabled={!canManage}
                                        required
                                    />
                                </div>
                                {canManage && (
                                    <div className="md:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="bg-primary hover:bg-primary-hover"
                                        >
                                            Save billing details
                                        </Button>
                                    </div>
                                )}
                            </>
                        )}
                    </Form>
                </section>

                <section className="rounded-lg border border-border bg-white p-5">
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-semibold">Invoices</h2>
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={`/tenants/${tenant.id}/billing/invoices`}
                            >
                                View all
                            </Link>
                        </Button>
                    </div>
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="text-xs text-muted-foreground">
                                <tr>
                                    <th className="py-2">Number</th>
                                    <th>Status</th>
                                    <th className="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.map((invoice) => (
                                    <tr
                                        key={invoice.id}
                                        className="border-t border-border"
                                    >
                                        <td className="py-2">
                                            <Link
                                                href={`/tenants/${tenant.id}/billing/invoices/${invoice.id}`}
                                                className="font-medium text-primary tabular-nums hover:underline"
                                            >
                                                {invoice.invoice_number}
                                            </Link>
                                        </td>
                                        <td>
                                            <StatusBadge
                                                status={invoice.status}
                                            />
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {formatMoney(
                                                invoice.amount_due_minor,
                                                invoice.currency,
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Payment history</h2>
                    <div className="mt-4 space-y-2 text-sm">
                        {payments.map((payment) => (
                            <div
                                key={payment.id}
                                className="flex flex-wrap items-center justify-between gap-2 border-t border-border py-2"
                            >
                                <span className="text-muted-foreground">
                                    {formatDateTime(
                                        payment.received_at,
                                        timezone,
                                    )}
                                </span>
                                <span className="flex items-center gap-2 tabular-nums">
                                    {formatMoney(
                                        payment.amount_minor,
                                        payment.currency,
                                    )}
                                    <StatusBadge status={payment.status} />
                                </span>
                            </div>
                        ))}
                        {payments.length === 0 && (
                            <p className="text-muted-foreground">
                                No payments yet.
                            </p>
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}
