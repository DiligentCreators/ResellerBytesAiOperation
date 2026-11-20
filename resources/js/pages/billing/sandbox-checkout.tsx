import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/money';

type Props = {
    tenant: { id: string; name: string };
    invoice: {
        id: number;
        invoice_number: string;
        currency: string;
        amount_due_minor: number;
        status: string;
        line_items: Array<{ description: string; amount_minor: number }> | null;
    };
    approveUrl: string;
    declineUrl: string;
};

export default function SandboxCheckoutPage({
    tenant,
    invoice,
    approveUrl,
    declineUrl,
}: Props) {
    return (
        <>
            <Head title="Sandbox checkout" />
            <div className="mx-auto max-w-xl space-y-6 p-6 md:p-10">
                <div className="rounded-lg border border-[#F59E0B] bg-[#FFFBEB] p-4">
                    <p className="text-sm font-semibold text-[#B45309]">
                        Sandbox — no real payment
                    </p>
                    <p className="mt-1 text-sm text-[#B45309]">
                        This hosted page simulates a payment provider. Approve
                        or decline to send a signed event through the same
                        webhook inbox path production gateways will use.
                    </p>
                </div>

                <div className="rounded-lg border border-[#E3E8EE] bg-white p-5">
                    <h1 className="text-xl font-bold tracking-tight">
                        Confirm payment
                    </h1>
                    <p className="mt-2 text-sm text-[#5B6B7D]">{tenant.name}</p>
                    <p className="mt-4 font-mono text-sm">
                        {invoice.invoice_number}
                    </p>
                    <p className="mt-2 text-3xl font-bold tabular-nums">
                        {formatMoney(
                            invoice.amount_due_minor,
                            invoice.currency,
                        )}
                    </p>
                    <ul className="mt-4 space-y-1 text-sm text-[#5B6B7D]">
                        {(invoice.line_items ?? []).map((item) => (
                            <li key={item.description}>{item.description}</li>
                        ))}
                    </ul>
                </div>

                <div className="flex gap-3">
                    <Form action={approveUrl} method="post">
                        {({ processing }) => (
                            <Button
                                type="submit"
                                disabled={processing}
                                className="bg-[#059669] hover:bg-[#047857]"
                            >
                                Approve
                            </Button>
                        )}
                    </Form>
                    <Form action={declineUrl} method="post">
                        {({ processing }) => (
                            <Button
                                type="submit"
                                disabled={processing}
                                variant="destructive"
                            >
                                Decline
                            </Button>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}
