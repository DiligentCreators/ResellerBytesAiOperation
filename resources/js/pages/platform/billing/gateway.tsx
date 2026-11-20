import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type GatewayStatus = {
    driver: string;
    kelviq_mode: string;
    api_key_configured: boolean;
    webhook_secret_configured: boolean;
    webhook_url: string;
    last_webhook_received_at: string | null;
    failed_webhook_count: number;
};

type WebhookRow = {
    id: number;
    event_type: string;
    status: string;
    received_at: string | null;
    tenant_id: string;
    attempts: number;
    last_error_code: string | null;
};

type Props = {
    gateway: GatewayStatus;
    recentWebhooks: WebhookRow[];
};

function BoolPill({ value, label }: { value: boolean; label: string }) {
    return (
        <div className="rounded-lg border border-border bg-white p-4">
            <p className="text-xs font-semibold text-muted-foreground">
                {label}
            </p>
            <p
                className={`mt-2 text-sm font-semibold ${value ? 'text-[#059669]' : 'text-[#DC2626]'}`}
            >
                {value ? 'Configured' : 'Missing'}
            </p>
        </div>
    );
}

export default function PlatformBillingGatewayPage({
    gateway,
    recentWebhooks,
}: Props) {
    return (
        <>
            <Head title="Billing gateway" />
            <div className="mx-auto max-w-5xl space-y-8 p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <Link
                            href="/platform/billing"
                            className="text-sm font-semibold text-primary"
                        >
                            ← Platform billing
                        </Link>
                        <h1 className="mt-2 text-2xl font-bold tracking-tight">
                            Payment gateway
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Status only — secret values are never shown.
                        </p>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="rounded-lg border border-border bg-white p-4">
                        <p className="text-xs font-semibold text-muted-foreground">
                            Active driver
                        </p>
                        <p className="mt-2 text-sm font-semibold tabular-nums">
                            {gateway.driver}
                        </p>
                    </div>
                    <div className="rounded-lg border border-border bg-white p-4">
                        <p className="text-xs font-semibold text-muted-foreground">
                            Kelviq mode
                        </p>
                        <p className="mt-2 text-sm font-semibold tabular-nums">
                            {gateway.kelviq_mode}
                        </p>
                    </div>
                    <BoolPill
                        value={gateway.api_key_configured}
                        label="API key"
                    />
                    <BoolPill
                        value={gateway.webhook_secret_configured}
                        label="Webhook secret"
                    />
                    <div className="rounded-lg border border-border bg-white p-4 sm:col-span-2">
                        <p className="text-xs font-semibold text-muted-foreground">
                            Webhook URL (register in Kelviq)
                        </p>
                        <p className="mt-2 font-mono text-sm break-all">
                            {gateway.webhook_url}
                        </p>
                    </div>
                    <div className="rounded-lg border border-border bg-white p-4">
                        <p className="text-xs font-semibold text-muted-foreground">
                            Last webhook received
                        </p>
                        <p className="mt-2 text-sm tabular-nums">
                            {gateway.last_webhook_received_at ?? '—'}
                        </p>
                    </div>
                    <div className="rounded-lg border border-border bg-white p-4">
                        <p className="text-xs font-semibold text-muted-foreground">
                            Failed / dead-lettered
                        </p>
                        <p className="mt-2 text-sm font-semibold tabular-nums">
                            {gateway.failed_webhook_count}
                        </p>
                    </div>
                </div>

                <div className="space-y-3">
                    <h2 className="text-base font-semibold">
                        Recent Kelviq webhooks
                    </h2>
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-[#F7F9FB] text-xs font-semibold text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2">ID</th>
                                    <th className="px-3 py-2">Type</th>
                                    <th className="px-3 py-2">Status</th>
                                    <th className="px-3 py-2">Received</th>
                                    <th className="px-3 py-2">Attempts</th>
                                    <th className="px-3 py-2">Replay</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recentWebhooks.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-3 py-6 text-center text-muted-foreground"
                                        >
                                            No Kelviq webhooks stored yet.
                                        </td>
                                    </tr>
                                )}
                                {recentWebhooks.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-border"
                                    >
                                        <td className="px-3 py-2 tabular-nums">
                                            {row.id}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.event_type}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.status}
                                        </td>
                                        <td className="px-3 py-2 tabular-nums">
                                            {row.received_at ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 tabular-nums">
                                            {row.attempts}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Form
                                                action={`/platform/billing/gateway/webhooks/${row.id}/replay`}
                                                method="post"
                                            >
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    className="border-border text-primary"
                                                >
                                                    Replay
                                                </Button>
                                            </Form>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}
