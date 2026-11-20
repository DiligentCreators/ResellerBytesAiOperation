import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/money';

type Props = {
    tenant: {
        id: string;
        name: string;
        slug: string;
        billing: Record<string, string> | null;
    };
    subscription: {
        id: number;
        plan_code: string;
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
    return (
        <AppLayout>
            <Head title={`Billing · ${tenant.name}`} />
            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <Link
                    href="/platform/billing"
                    className="text-sm text-[#0779A8]"
                >
                    Back to platform billing
                </Link>
                <h1 className="text-2xl font-bold tracking-tight">
                    {tenant.name}
                </h1>
                <p className="text-sm text-[#5B6B7D]">{tenant.slug}</p>
                <p className="text-sm">
                    Lifetime paid:{' '}
                    <span className="font-semibold tabular-nums">
                        {formatMoney(revenue_paid_minor)}
                    </span>
                </p>

                <section className="rounded-lg border border-[#E3E8EE] bg-white p-5">
                    <h2 className="text-base font-semibold">Subscription</h2>
                    {subscription ? (
                        <div className="mt-3 space-y-1 text-sm">
                            <p>
                                {subscription.plan_code} · {subscription.status}
                            </p>
                            <p className="tabular-nums">
                                {formatMoney(
                                    subscription.unit_amount_minor,
                                    subscription.currency,
                                )}
                            </p>
                            <p className="text-[#5B6B7D]">
                                Period ends{' '}
                                {subscription.current_period_ends_at}
                            </p>
                        </div>
                    ) : (
                        <p className="mt-3 text-sm text-[#5B6B7D]">
                            No subscription.
                        </p>
                    )}
                </section>

                <section className="rounded-lg border border-[#E3E8EE] bg-white p-5">
                    <h2 className="text-base font-semibold">Billing details</h2>
                    <pre className="mt-3 overflow-x-auto text-xs text-[#5B6B7D]">
                        {JSON.stringify(tenant.billing ?? {}, null, 2)}
                    </pre>
                </section>

                <section className="rounded-lg border border-[#E3E8EE] bg-white p-5">
                    <h2 className="text-base font-semibold">Invoices</h2>
                    <ul className="mt-3 space-y-2 text-sm">
                        {invoices.map((invoice) => (
                            <li
                                key={invoice.id}
                                className="flex justify-between"
                            >
                                <span className="font-mono">
                                    {invoice.invoice_number}
                                </span>
                                <span>
                                    {invoice.status} ·{' '}
                                    {formatMoney(
                                        invoice.amount_due_minor,
                                        invoice.currency,
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="rounded-lg border border-[#E3E8EE] bg-white p-5">
                    <h2 className="text-base font-semibold">Payments</h2>
                    <ul className="mt-3 space-y-2 text-sm">
                        {payments.map((payment) => (
                            <li
                                key={payment.id}
                                className="flex justify-between"
                            >
                                <span>{payment.received_at ?? '—'}</span>
                                <span>
                                    {formatMoney(
                                        payment.amount_minor,
                                        payment.currency,
                                    )}{' '}
                                    · {payment.status}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </AppLayout>
    );
}
