import { Head, Link } from '@inertiajs/react';
import { StatusBadge } from '@/components/status-badge';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatMoney } from '@/lib/money';

type BillingDetails = {
    company_name: string | null;
    billing_email: string | null;
    country: string | null;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    postal_code: string | null;
    tax_id: string | null;
};

type ScheduledChange = {
    plan_id: number;
    plan_code: string;
    plan_name: string;
    charge_period: string;
    change_at: string;
};

type Props = {
    tenant: {
        id: string;
        name: string;
        slug: string;
        timezone?: string;
    };
    billingDetails: BillingDetails;
    subscription: {
        id: number;
        plan_code: string;
        plan_name?: string;
        status: string;
        unit_amount_minor: number;
        currency: string;
        billing_period: string;
        cancel_at_period_end: boolean;
        billing_provider: string | null;
        billing_mode: string | null;
        current_period_starts_at: string | null;
        current_period_ends_at: string | null;
    } | null;
    scheduledChange?: ScheduledChange | null;
    invoices: Array<{
        id: number;
        invoice_number: string;
        status: string;
        amount_due_minor: number;
        currency: string;
    }>;
    payments: Array<{
        id: number;
        amount_minor: number;
        currency: string;
        status: string;
        received_at: string | null;
    }>;
    revenue_paid_minor: number;
};

function displayValue(value: string | null | undefined): string {
    return value && value.trim() !== '' ? value : '—';
}

function DetailRow({
    label,
    value,
}: {
    label: string;
    value: string | null | undefined;
}) {
    return (
        <div className="grid grid-cols-[10rem_1fr] gap-3 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{displayValue(value)}</dd>
        </div>
    );
}

export default function PlatformBillingTenant({
    tenant,
    billingDetails,
    subscription,
    scheduledChange = null,
    invoices,
    payments,
    revenue_paid_minor,
}: Props) {
    const timezone = tenant.timezone ?? 'UTC';
    const periodLabel =
        subscription?.billing_period === 'yearly' ? 'year' : 'month';
    const priceWithInterval = subscription
        ? `${formatMoney(subscription.unit_amount_minor, subscription.currency)} / ${periodLabel}`
        : null;
    const periodRange = subscription
        ? `${formatDate(subscription.current_period_starts_at, { timeZone: timezone })} – ${formatDate(subscription.current_period_ends_at, { timeZone: timezone })}`
        : null;

    return (
        <>
            <Head title={`Billing · ${tenant.name}`} />
            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <Link href="/platform/billing" className="text-sm text-primary">
                    Back to platform billing
                </Link>
                <h1 className="text-2xl font-bold tracking-tight">
                    {tenant.name}
                </h1>
                <p className="text-sm text-muted-foreground">{tenant.slug}</p>
                <p className="text-sm">
                    Lifetime paid:{' '}
                    <span className="font-semibold tabular-nums">
                        {formatMoney(revenue_paid_minor)}
                    </span>
                </p>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Subscription</h2>
                    {subscription ? (
                        <dl className="mt-3 space-y-2">
                            <DetailRow
                                label="Plan"
                                value={
                                    subscription.plan_name ??
                                    subscription.plan_code
                                }
                            />
                            <div className="grid grid-cols-[10rem_1fr] gap-3 text-sm">
                                <dt className="text-muted-foreground">
                                    Status
                                </dt>
                                <dd>
                                    <StatusBadge status={subscription.status} />
                                </dd>
                            </div>
                            <DetailRow
                                label="Price"
                                value={priceWithInterval}
                            />
                            <DetailRow
                                label="Current period"
                                value={periodRange}
                            />
                            <DetailRow
                                label="Cancel at period end"
                                value={
                                    subscription.cancel_at_period_end
                                        ? 'Yes'
                                        : 'No'
                                }
                            />
                            <DetailRow
                                label="Billing provider"
                                value={subscription.billing_provider}
                            />
                            <DetailRow
                                label="Billing mode"
                                value={subscription.billing_mode}
                            />
                            {scheduledChange && (
                                <p
                                    role="status"
                                    className="mt-3 rounded-md border border-border bg-surface px-4 py-3 text-sm"
                                >
                                    Scheduled change:{' '}
                                    {scheduledChange.plan_name} (
                                    {scheduledChange.charge_period}) on{' '}
                                    {formatDate(scheduledChange.change_at, {
                                        timeZone: timezone,
                                    })}
                                </p>
                            )}
                        </dl>
                    ) : (
                        <p className="mt-3 text-sm text-muted-foreground">
                            No subscription.
                        </p>
                    )}
                </section>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Billing details</h2>
                    <dl className="mt-3 space-y-2">
                        <DetailRow
                            label="Company"
                            value={billingDetails.company_name}
                        />
                        <DetailRow
                            label="Billing email"
                            value={billingDetails.billing_email}
                        />
                        <DetailRow
                            label="Country"
                            value={billingDetails.country}
                        />
                        <DetailRow
                            label="Address"
                            value={billingDetails.address_line1}
                        />
                        <DetailRow
                            label="Address line 2"
                            value={billingDetails.address_line2}
                        />
                        <DetailRow label="City" value={billingDetails.city} />
                        <DetailRow
                            label="Postal code"
                            value={billingDetails.postal_code}
                        />
                        <DetailRow
                            label="Tax ID"
                            value={billingDetails.tax_id}
                        />
                    </dl>
                </section>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Invoices</h2>
                    <ul className="mt-3 space-y-2 text-sm">
                        {invoices.map((invoice) => (
                            <li
                                key={invoice.id}
                                className="flex justify-between gap-3"
                            >
                                <span className="text-primary tabular-nums">
                                    {invoice.invoice_number}
                                </span>
                                <span className="flex items-center gap-2">
                                    <StatusBadge status={invoice.status} />
                                    <span className="tabular-nums">
                                        {formatMoney(
                                            invoice.amount_due_minor,
                                            invoice.currency,
                                        )}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Payments</h2>
                    <ul className="mt-3 space-y-2 text-sm">
                        {payments.map((payment) => (
                            <li
                                key={payment.id}
                                className="flex justify-between gap-3"
                            >
                                <span>
                                    {formatDateTime(
                                        payment.received_at,
                                        timezone,
                                    )}
                                </span>
                                <span className="flex items-center gap-2">
                                    <span className="tabular-nums">
                                        {formatMoney(
                                            payment.amount_minor,
                                            payment.currency,
                                        )}
                                    </span>
                                    <StatusBadge status={payment.status} />
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
