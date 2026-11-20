import { Head, Link } from '@inertiajs/react';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';

type Invoice = {
    id: number;
    invoice_number: string;
    status: string;
    currency: string;
    subtotal_minor: number;
    tax_minor: number;
    amount_due_minor: number;
    amount_paid_minor: number;
    line_items: Array<{
        description: string;
        quantity?: number;
        amount_minor: number;
    }>;
    billing_details: Record<string, string>;
    issued_at: string | null;
    due_at: string | null;
    paid_at: string | null;
};

type Props = {
    tenant: { id: string; name: string };
    invoice: Invoice;
    seller: {
        company_name: string;
        address: string;
        email: string;
        tax_id: string;
    };
};

export default function InvoiceShow({ tenant, invoice, seller }: Props) {
    const timezone = useWorkspaceTimezone();

    return (
        <>
            <Head title={`Invoice ${invoice.invoice_number}`} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={`/tenants/${tenant.id}/billing/invoices`}>
                            ← Invoices
                        </Link>
                    </Button>
                    <div className="flex gap-2">
                        <Button variant="secondary" size="sm" asChild>
                            <Link
                                href={`/tenants/${tenant.id}/billing/invoices/${invoice.id}/print`}
                            >
                                Print
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="rounded-lg border border-border bg-card p-6 shadow-xs sm:p-10 print:border-0 print:p-0 print:shadow-none">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-sm font-semibold">
                                {seller.company_name}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {seller.address}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {seller.email}
                            </p>
                        </div>
                        <div className="text-right">
                            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                Invoice
                            </p>
                            <p className="mt-1 text-lg font-semibold tabular-nums">
                                {invoice.invoice_number}
                            </p>
                            <div className="mt-2 flex justify-end">
                                <StatusBadge status={invoice.status} />
                            </div>
                        </div>
                    </div>

                    <div className="mt-8 grid gap-6 border-t border-border pt-6 sm:grid-cols-4">
                        <div>
                            <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                Billed to
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                {invoice.billing_details.company_name ??
                                    tenant.name}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {invoice.billing_details.address_line1}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                Issued
                            </p>
                            <p className="mt-1 text-sm">
                                {formatDate(invoice.issued_at, {
                                    timeZone: timezone,
                                })}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                Due
                            </p>
                            <p className="mt-1 text-sm">
                                {formatDate(invoice.due_at, {
                                    timeZone: timezone,
                                })}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                Amount due
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {formatMoney(
                                    invoice.amount_due_minor,
                                    invoice.currency,
                                )}
                            </p>
                        </div>
                    </div>

                    <table className="mt-8 w-full text-sm">
                        <caption className="sr-only">Line items</caption>
                        <thead className="border-b border-border text-xs font-medium text-muted-foreground">
                            <tr>
                                <th scope="col" className="py-2 text-left">
                                    Description
                                </th>
                                <th scope="col" className="py-2 text-right">
                                    Qty
                                </th>
                                <th scope="col" className="py-2 text-right">
                                    Amount
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.line_items.map((item) => (
                                <tr
                                    key={item.description}
                                    className="border-b border-border"
                                >
                                    <td className="py-3">{item.description}</td>
                                    <td className="py-3 text-right tabular-nums">
                                        {item.quantity ?? 1}
                                    </td>
                                    <td className="py-3 text-right font-medium tabular-nums">
                                        {formatMoney(
                                            item.amount_minor,
                                            invoice.currency,
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="mt-6 ml-auto w-full max-w-xs space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">
                                Subtotal
                            </span>
                            <span className="tabular-nums">
                                {formatMoney(
                                    invoice.subtotal_minor,
                                    invoice.currency,
                                )}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tax</span>
                            <span className="tabular-nums">
                                {formatMoney(
                                    invoice.tax_minor,
                                    invoice.currency,
                                )}
                            </span>
                        </div>
                        <div className="flex justify-between border-t border-border pt-2 font-semibold">
                            <span>Total</span>
                            <span className="tabular-nums">
                                {formatMoney(
                                    invoice.amount_due_minor,
                                    invoice.currency,
                                )}
                            </span>
                        </div>
                    </div>

                    {invoice.paid_at ? (
                        <p className="mt-8 text-sm text-muted-foreground">
                            Paid on{' '}
                            {formatDate(invoice.paid_at, {
                                timeZone: timezone,
                            })}
                            {seller.email ? ` · Support: ${seller.email}` : ''}
                        </p>
                    ) : null}
                </div>
            </div>
        </>
    );
}
