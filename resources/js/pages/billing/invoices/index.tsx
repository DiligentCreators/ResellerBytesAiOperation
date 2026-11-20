import { Head, Link } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDate } from '@/lib/dates';
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
    const timezone = useWorkspaceTimezone();

    return (
        <>
            <Head title="Invoices" />
            <div className="space-y-6">
                <PageHeader
                    title="Invoices"
                    description="Download and review past invoices."
                    actions={
                        <Button variant="secondary" size="sm" asChild>
                            <Link href={`/tenants/${tenant.id}/billing`}>
                                Billing settings
                            </Link>
                        </Button>
                    }
                />

                {invoices.data.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title="No invoices yet"
                        description="Your invoices will appear here after your first billing cycle."
                        action={
                            <Button size="sm" asChild>
                                <Link href="/billing/plans">View plans</Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Invoices</caption>
                            <thead className="border-b border-border bg-surface text-xs font-medium text-muted-foreground">
                                <tr>
                                    <th scope="col" className="h-9 px-4">
                                        Invoice
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Status
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Issued
                                    </th>
                                    <th
                                        scope="col"
                                        className="h-9 px-4 text-right"
                                    >
                                        Amount
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.data.map((invoice) => (
                                    <tr
                                        key={invoice.id}
                                        className="h-11 border-b border-border last:border-0 hover:bg-surface/70"
                                    >
                                        <td className="px-4">
                                            <Link
                                                href={`/tenants/${tenant.id}/billing/invoices/${invoice.id}`}
                                                className="font-medium text-primary tabular-nums hover:underline"
                                            >
                                                {invoice.invoice_number}
                                            </Link>
                                        </td>
                                        <td className="px-4">
                                            <StatusBadge
                                                status={invoice.status}
                                            />
                                        </td>
                                        <td className="px-4 text-muted-foreground">
                                            {formatDate(invoice.issued_at, {
                                                timeZone: timezone,
                                            })}
                                        </td>
                                        <td className="px-4 text-right font-medium tabular-nums">
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
                )}
            </div>
        </>
    );
}
