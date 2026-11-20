import { Head, Link } from '@inertiajs/react';
import { StatusBadge } from '@/components/status-badge';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatMoney } from '@/lib/money';

type Props = {
    tenant: {
        id: string;
        name: string;
        slug: string;
        billing: Record<string, string> | null;
        timezone?: string;
    };
    subscription: {
        id: number;
        plan_code: string;
        plan_name?: string;
        status: string;
        unit_amount_minor: number;
        currency: string;
        current_period_ends_at: string;
    } | null;
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

export default function PlatformBillingTenant({
    tenant,
    subscription,
    invoices,
    payments,
    revenue_paid_minor,
}: Props) {
    const timezone = tenant.timezone ?? 'UTC';

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
                        <div className="mt-3 space-y-2 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {subscription.plan_name ??
                                        subscription.plan_code}
                                </span>
                                <StatusBadge status={subscription.status} />
                            </div>
                            <p className="tabular-nums">
                                {formatMoney(
                                    subscription.unit_amount_minor,
                                    subscription.currency,
                                )}
                            </p>
                            <p className="text-muted-foreground">
                                Period ends{' '}
                                {formatDate(
                                    subscription.current_period_ends_at,
                                    { timeZone: timezone },
                                )}
                            </p>
                        </div>
                    ) : (
                        <p className="mt-3 text-sm text-muted-foreground">
                            No subscription.
                        </p>
                    )}
                </section>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Billing details</h2>
                    <pre className="mt-3 overflow-x-auto text-xs text-muted-foreground">
                        {JSON.stringify(tenant.billing ?? {}, null, 2)}
                    </pre>
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
