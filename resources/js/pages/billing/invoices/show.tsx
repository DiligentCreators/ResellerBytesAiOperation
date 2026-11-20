import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
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
    return (
        <AppLayout>
            <Head title={`Invoice ${invoice.invoice_number}`} />
            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="font-mono text-2xl font-bold">
                        {invoice.invoice_number}
                    </h1>
                    <Button asChild variant="outline">
                        <Link
                            href={`/tenants/${tenant.id}/billing/invoices/${invoice.id}/print`}
                        >
                            Download PDF / Print
                        </Link>
                    </Button>
                </div>
                <div className="grid gap-6 rounded-lg border border-[#E3E8EE] bg-white p-6 md:grid-cols-2">
                    <div>
                        <p className="text-xs font-semibold text-[#5B6B7D]">
                            From
                        </p>
                        <p className="mt-1 font-medium">
                            {seller.company_name}
                        </p>
                        <p className="text-sm text-[#5B6B7D]">
                            {seller.address}
                        </p>
                        <p className="text-sm text-[#5B6B7D]">{seller.email}</p>
                    </div>
                    <div>
                        <p className="text-xs font-semibold text-[#5B6B7D]">
                            Bill to
                        </p>
                        <p className="mt-1 font-medium">
                            {invoice.billing_details.company_name ??
                                tenant.name}
                        </p>
                        <p className="text-sm text-[#5B6B7D]">
                            {invoice.billing_details.address_line1}
                        </p>
                        <p className="text-sm text-[#5B6B7D]">
                            {invoice.billing_details.billing_email}
                        </p>
                    </div>
                </div>
                <div className="rounded-lg border border-[#E3E8EE] bg-white p-6">
                    <p className="text-sm">
                        Status: <strong>{invoice.status}</strong>
                    </p>
                    <p className="text-sm text-[#5B6B7D]">
                        Issued {invoice.issued_at ?? '—'}
                        {invoice.paid_at ? ` · Paid ${invoice.paid_at}` : ''}
                    </p>
                    <table className="mt-6 w-full text-sm">
                        <thead className="text-xs text-[#5B6B7D]">
                            <tr>
                                <th className="py-2 text-left">Item</th>
                                <th className="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.line_items.map((item) => (
                                <tr
                                    key={item.description}
                                    className="border-t border-[#E3E8EE]"
                                >
                                    <td className="py-2">{item.description}</td>
                                    <td className="text-right tabular-nums">
                                        {formatMoney(
                                            item.amount_minor,
                                            invoice.currency,
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="mt-4 space-y-1 text-right text-sm">
                        <p>
                            Subtotal:{' '}
                            {formatMoney(
                                invoice.subtotal_minor,
                                invoice.currency,
                            )}
                        </p>
                        <p>
                            Tax:{' '}
                            {formatMoney(invoice.tax_minor, invoice.currency)}
                        </p>
                        <p className="text-base font-semibold">
                            Total:{' '}
                            {formatMoney(
                                invoice.amount_due_minor,
                                invoice.currency,
                            )}
                        </p>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
