import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/money';

type Props = {
    tenant: { id: string; name: string };
    invoice: {
        id: number;
        invoice_number: string;
        status: string;
        amount_due_minor: number;
        currency: string;
    };
    processing: boolean;
};

export default function CheckoutReturnPage({
    tenant,
    invoice,
    processing,
}: Props) {
    return (
        <AppLayout>
            <Head title="Payment processing" />
            <div className="mx-auto max-w-lg space-y-6 p-6">
                <h1 className="text-2xl font-bold tracking-tight">
                    {processing ? 'Payment processing' : 'Payment received'}
                </h1>
                <p className="text-sm text-[#5B6B7D]">
                    {processing
                        ? 'Your checkout completed on the payment provider. We activate your plan only after the signed webhook arrives — this page does not turn access on by itself.'
                        : 'Your invoice is marked paid and entitlements should be active.'}
                </p>
                <div className="rounded-lg border border-[#E3E8EE] bg-[#F7F9FB] p-4 text-sm">
                    <p>
                        Invoice{' '}
                        <span className="font-medium">
                            {invoice.invoice_number}
                        </span>
                    </p>
                    <p className="mt-1 tabular-nums">
                        {formatMoney(
                            invoice.amount_due_minor,
                            invoice.currency,
                        )}{' '}
                        · status {invoice.status}
                    </p>
                </div>
                <Button asChild className="bg-[#0779A8] hover:bg-[#066A93]">
                    <Link href={`/tenants/${tenant.id}/billing`}>
                        Back to billing
                    </Link>
                </Button>
            </div>
        </AppLayout>
    );
}
