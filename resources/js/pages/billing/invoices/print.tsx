import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';

type Props = {
    tenant: { id: string; name: string };
    timezone?: string;
    invoice: {
        invoice_number: string;
        status: string;
        currency: string;
        subtotal_minor: number;
        tax_minor: number;
        amount_due_minor: number;
        line_items: Array<{ description: string; amount_minor: number }>;
        credit_lines?: Array<{ description: string; amount_minor: number }>;
        billing_details: Record<string, string>;
        issued_at: string | null;
        paid_at: string | null;
        service_period_starts_at?: string | null;
        service_period_ends_at?: string | null;
    };
    seller: {
        company_name: string;
        address: string;
        email: string;
        tax_id: string;
    };
};

export default function InvoicePrint({
    tenant,
    invoice,
    seller,
    timezone: timezoneProp,
}: Props) {
    const workspaceTimezone = useWorkspaceTimezone();
    const timezone = timezoneProp ?? workspaceTimezone;

    useEffect(() => {
        const timer = window.setTimeout(() => window.print(), 250);
        return () => window.clearTimeout(timer);
    }, []);

    return (
        <>
            <Head title={`Print ${invoice.invoice_number}`} />
            <div className="mx-auto max-w-3xl bg-white p-10 text-foreground print:p-0">
                <div className="mb-8 flex items-start justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">
                            {seller.company_name}
                        </h1>
                        <p className="mt-2 text-sm whitespace-pre-line text-muted-foreground">
                            {seller.address}
                            {'\n'}
                            {seller.email}
                            {seller.tax_id ? `\nTax ID: ${seller.tax_id}` : ''}
                        </p>
                    </div>
                    <div className="text-right">
                        <p className="text-lg font-semibold tabular-nums">
                            {invoice.invoice_number}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {invoice.status}
                        </p>
                    </div>
                </div>
                <div className="mb-8">
                    <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Bill to
                    </p>
                    <p className="mt-1 font-medium">
                        {invoice.billing_details.company_name ?? tenant.name}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {invoice.billing_details.address_line1}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {invoice.billing_details.billing_email}
                    </p>
                </div>
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs text-muted-foreground">
                            <th className="py-2">Description</th>
                            <th className="py-2 text-right">Amount</th>
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
                                    {formatMoney(
                                        item.amount_minor,
                                        invoice.currency,
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="mt-6 ml-auto w-full max-w-sm space-y-1 text-sm">
                    <div className="flex justify-between gap-4">
                        <span>Subtotal</span>
                        <span className="tabular-nums">
                            {formatMoney(
                                invoice.subtotal_minor,
                                invoice.currency,
                            )}
                        </span>
                    </div>
                    {(invoice.credit_lines ?? []).map((credit) => (
                        <div
                            key={credit.description}
                            className="flex justify-between gap-4"
                        >
                            <span>{credit.description}</span>
                            <span className="shrink-0 tabular-nums">
                                {formatMoney(
                                    credit.amount_minor,
                                    invoice.currency,
                                )}
                            </span>
                        </div>
                    ))}
                    <div className="flex justify-between gap-4">
                        <span>Tax</span>
                        <span className="tabular-nums">
                            {formatMoney(invoice.tax_minor, invoice.currency)}
                        </span>
                    </div>
                    <div className="flex justify-between gap-4 text-lg font-bold">
                        <span>Total</span>
                        <span className="tabular-nums">
                            {formatMoney(
                                invoice.amount_due_minor,
                                invoice.currency,
                            )}
                        </span>
                    </div>
                </div>
                <p className="mt-10 text-xs text-muted-foreground">
                    Issued{' '}
                    {formatDate(invoice.issued_at, { timeZone: timezone })}
                    {invoice.service_period_starts_at &&
                    invoice.service_period_ends_at
                        ? ` · Service period: ${formatDate(invoice.service_period_starts_at, { timeZone: timezone })} – ${formatDate(invoice.service_period_ends_at, { timeZone: timezone })}`
                        : ''}
                    {invoice.paid_at
                        ? ` · Paid ${formatDate(invoice.paid_at, { timeZone: timezone })}`
                        : ''}
                </p>
                <button
                    type="button"
                    onClick={() => window.print()}
                    className="mt-8 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white print:hidden"
                >
                    Download PDF
                </button>
            </div>
        </>
    );
}
