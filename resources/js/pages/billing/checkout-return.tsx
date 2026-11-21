import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
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
    declined?: boolean;
    declineMessage?: string | null;
};

export default function CheckoutReturnPage({
    tenant,
    invoice,
    processing,
    declined = false,
    declineMessage = null,
}: Props) {
    const title = declined
        ? 'Payment declined'
        : processing
          ? 'Payment processing'
          : 'Payment received';

    return (
        <>
            <Head title={title} />
            <div className="mx-auto max-w-lg space-y-6 p-6">
                <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                <p className="text-sm text-muted-foreground">
                    {declined
                        ? (declineMessage ??
                          "Your payment was declined. You haven't been charged — please try again or use a different payment method.")
                        : processing
                          ? 'Your checkout completed on the payment provider. We activate your plan only after the signed webhook arrives — this page does not turn access on by itself.'
                          : 'Your invoice is marked paid and entitlements should be active.'}
                </p>
                <div className="rounded-lg border border-border bg-[#F7F9FB] p-4 text-sm">
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
                <Button asChild className="bg-primary hover:bg-primary-hover">
                    <Link href={`/tenants/${tenant.id}/billing`}>
                        Back to billing
                    </Link>
                </Button>
            </div>
        </>
    );
}
