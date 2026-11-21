import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/dates';
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
    isTrial: boolean;
    trialDays: number | null;
    trialEndsAt: string | null;
    approveUrl: string;
    declineUrl: string;
};

export default function SandboxCheckoutPage({
    tenant,
    invoice,
    isTrial,
    trialDays,
    trialEndsAt,
    approveUrl,
    declineUrl,
}: Props) {
    const title = isTrial
        ? `Start your ${trialDays ?? ''}-day free trial`
        : 'Confirm payment';

    return (
        <>
            <Head title={isTrial ? 'Start free trial' : 'Sandbox checkout'} />
            <div className="mx-auto max-w-xl space-y-6 p-6 md:p-10">
                <div className="rounded-lg border border-warning bg-warning-soft p-4">
                    <p className="text-sm font-semibold text-warning-fg">
                        Sandbox — no real payment
                    </p>
                    <p className="mt-1 text-sm text-warning-fg">
                        {isTrial
                            ? 'This hosted page simulates confirming a card for your free trial. You will not be charged until the trial ends.'
                            : 'This hosted page simulates a payment provider. Approve or decline to send a signed event through the same webhook inbox path production gateways will use.'}
                    </p>
                </div>

                <div className="rounded-lg border border-border bg-white p-5">
                    <h1 className="text-xl font-bold tracking-tight">{title}</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {tenant.name}
                    </p>
                    {isTrial && trialEndsAt ? (
                        <p className="mt-4 text-sm text-muted-foreground">
                            Card required · trial ends{' '}
                            {formatDate(trialEndsAt, { timeZone: 'UTC' })}.
                            You will not be charged today.
                        </p>
                    ) : (
                        <>
                            <p className="mt-4 font-mono text-sm">
                                {invoice.invoice_number}
                            </p>
                            <p className="mt-2 text-3xl font-bold tabular-nums">
                                {formatMoney(
                                    invoice.amount_due_minor,
                                    invoice.currency,
                                )}
                            </p>
                        </>
                    )}
                    <ul className="mt-4 space-y-1 text-sm text-muted-foreground">
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
                                className="bg-success hover:bg-success-fg"
                            >
                                {isTrial ? 'Start free trial' : 'Approve'}
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
