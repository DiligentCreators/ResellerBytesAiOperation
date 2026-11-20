import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/money';

type Props = {
    tenant: { id: string; name: string };
    invoices: {
        data: Array<{
            id: number;
            invoice_number: string;
            status: string;
            currency: string;
            amount_due_minor: number;
            issued_at: string | null;
            paid_at: string | null;
        }>;
    };
};

export default function InvoicesIndex({ tenant, invoices }: Props) {
    return (
        <AppLayout>
            <Head title="Invoices" />
            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold tracking-tight">
                        Invoices
                    </h1>
                    <Link
                        href={`/tenants/${tenant.id}/billing`}
                        className="text-sm text-[#0779A8]"
                    >
                        Back to billing
                    </Link>
                </div>
                <table className="w-full text-left text-sm">
                    <thead className="text-xs text-[#5B6B7D]">
                        <tr>
                            <th className="py-2">Number</th>
                            <th>Status</th>
                            <th>Issued</th>
                            <th className="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoices.data.map((invoice) => (
                            <tr
                                key={invoice.id}
                                className="border-t border-[#E3E8EE]"
                            >
                                <td className="py-2 font-mono">
                                    <Link
                                        href={`/tenants/${tenant.id}/billing/invoices/${invoice.id}`}
                                        className="text-[#0779A8]"
                                    >
                                        {invoice.invoice_number}
                                    </Link>
                                </td>
                                <td>{invoice.status}</td>
                                <td>{invoice.issued_at ?? '—'}</td>
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
        </AppLayout>
    );
}
