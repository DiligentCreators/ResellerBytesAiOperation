import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/money';

type Props = {
    summary: {
        mrr_minor: number;
        active_subscriptions: number;
        failed_payments: number;
        paid_invoices: number;
    };
    subscriptions: Array<{
        id: number;
        plan_code: string;
        status: string;
        unit_amount_minor: number;
        currency: string;
        tenant?: { id: string; name: string; slug: string } | null;
    }>;
    invoices: Array<{
        id: number;
        invoice_number: string;
        status: string;
        amount_due_minor: number;
        currency: string;
        tenant?: { id: string; name: string } | null;
    }>;
    seller: {
        company_name: string;
        address: string;
        email: string;
        tax_id: string;
    };
    pricesArePlaceholders: boolean;
};

export default function PlatformBillingIndex({
    summary,
    subscriptions,
    invoices,
    seller,
    pricesArePlaceholders,
}: Props) {
    return (
        <>
            <Head title="Platform billing" />
            <div className="mx-auto max-w-6xl space-y-8 p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Platform billing
                        </h1>
                        {pricesArePlaceholders && (
                            <p className="mt-2 text-sm text-warning-fg">
                                Plan prices are placeholder seed values — edit
                                them before launch.
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            asChild
                            variant="outline"
                            className="border-border text-primary"
                        >
                            <Link href="/platform/billing/gateway">
                                Gateway status
                            </Link>
                        </Button>
                        <Button
                            asChild
                            className="bg-primary hover:bg-primary-hover"
                        >
                            <Link href="/platform/billing/plans">
                                Manage plans
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-4">
                    {[
                        {
                            label: 'MRR (approx)',
                            value: formatMoney(summary.mrr_minor),
                        },
                        {
                            label: 'Active subscriptions',
                            value: String(summary.active_subscriptions),
                        },
                        {
                            label: 'Failed / past due invoices',
                            value: String(summary.failed_payments),
                        },
                        {
                            label: 'Paid invoices',
                            value: String(summary.paid_invoices),
                        },
                    ].map((tile) => (
                        <div
                            key={tile.label}
                            className="rounded-lg border border-border bg-white p-4"
                        >
                            <p className="text-xs font-semibold text-muted-foreground">
                                {tile.label}
                            </p>
                            <p className="mt-2 text-2xl font-bold tabular-nums">
                                {tile.value}
                            </p>
                        </div>
                    ))}
                </div>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Seller details</h2>
                    <Form
                        action="/platform/billing/seller"
                        method="put"
                        className="mt-4 grid gap-4 md:grid-cols-2"
                    >
                        {({ processing }) => (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="company_name">
                                        Company
                                    </Label>
                                    <Input
                                        id="company_name"
                                        name="company_name"
                                        defaultValue={seller.company_name}
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue={seller.email}
                                        required
                                    />
                                </div>
                                <div className="space-y-2 md:col-span-2">
                                    <Label htmlFor="address">Address</Label>
                                    <Input
                                        id="address"
                                        name="address"
                                        defaultValue={seller.address}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="tax_id">Tax ID</Label>
                                    <Input
                                        id="tax_id"
                                        name="tax_id"
                                        defaultValue={seller.tax_id}
                                    />
                                </div>
                                <div className="md:col-span-2">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="bg-primary hover:bg-primary-hover"
                                    >
                                        Save seller details
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Subscriptions</h2>
                    <table className="mt-4 w-full text-left text-sm">
                        <thead className="text-xs text-muted-foreground">
                            <tr>
                                <th className="py-2">Tenant</th>
                                <th>Plan</th>
                                <th>Status</th>
                                <th className="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {subscriptions.map((subscription) => (
                                <tr
                                    key={subscription.id}
                                    className="border-t border-border"
                                >
                                    <td className="py-2">
                                        {subscription.tenant ? (
                                            <Link
                                                href={`/platform/billing/tenants/${subscription.tenant.id}`}
                                                className="text-primary"
                                            >
                                                {subscription.tenant.name}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td>{subscription.plan_code}</td>
                                    <td>{subscription.status}</td>
                                    <td className="text-right tabular-nums">
                                        {formatMoney(
                                            subscription.unit_amount_minor,
                                            subscription.currency,
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section className="rounded-lg border border-border bg-white p-5">
                    <h2 className="text-base font-semibold">Invoices</h2>
                    <table className="mt-4 w-full text-left text-sm">
                        <thead className="text-xs text-muted-foreground">
                            <tr>
                                <th className="py-2">Number</th>
                                <th>Tenant</th>
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
                                    <td className="py-2 font-mono">
                                        {invoice.invoice_number}
                                    </td>
                                    <td>{invoice.tenant?.name ?? '—'}</td>
                                    <td>{invoice.status}</td>
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
                </section>
            </div>
        </>
    );
}
