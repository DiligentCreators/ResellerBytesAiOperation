import { Form, Head, router } from '@inertiajs/react';
import { BellRing, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';

type Channel = {
    id: string;
    type: string;
    name: string;
    target_hint: string;
    events: string[];
    enabled: boolean;
    verified_at: string | null;
    last_error: string | null;
    last_sent_at: string | null;
    has_signing_secret: boolean;
    created_at: string | null;
};

type Delivery = {
    id: string;
    channel_id: string;
    channel_name: string | null;
    channel_type: string | null;
    alert_key: string;
    dedupe_key: string;
    status: string;
    error: string | null;
    sent_at: string | null;
    created_at: string | null;
};

type Props = {
    tenant: { id: string; name: string };
    channels: Channel[];
    deliveries: Delivery[];
    canManage: boolean;
    channelsEntitled: boolean;
    eventKeys: string[];
    channelTypes: string[];
    status?: string;
    createdSigningSecret?: { channel_id: string; secret: string } | null;
};

export default function TenantAlerts({
    tenant,
    channels,
    deliveries,
    canManage,
    channelsEntitled,
    eventKeys,
    channelTypes,
    status,
    createdSigningSecret,
}: Props) {
    const timezone = useWorkspaceTimezone();
    const [showCreate, setShowCreate] = useState(false);
    const [editingId, setEditingId] = useState<string | null>(null);
    const manageEnabled = canManage && channelsEntitled;

    return (
        <>
            <Head title="Alert channels" />
            <div className="space-y-6">
                <PageHeader
                    title="Alert channels"
                    description={`Immediate critical alerts for ${tenant.name}.`}
                />

                {status ? (
                    <p
                        role="status"
                        className="rounded-md border border-success/20 bg-success-soft px-3 py-2 text-sm text-success-fg"
                    >
                        {status}
                    </p>
                ) : null}

                {createdSigningSecret ? (
                    <div
                        role="status"
                        className="rounded-md border border-border bg-card px-4 py-3 text-sm"
                    >
                        <p className="font-medium">
                            Webhook signing secret (shown once)
                        </p>
                        <p className="mt-1 break-all font-mono text-xs">
                            {createdSigningSecret.secret}
                        </p>
                        <p className="mt-2 text-xs text-muted-foreground">
                            Store this secret now. It will not be shown again.
                        </p>
                    </div>
                ) : null}

                {!channelsEntitled ? (
                    <div className="rounded-md border border-border bg-muted/40 px-4 py-3 text-sm">
                        <p className="font-medium">Upgrade to Growth+</p>
                        <p className="mt-1 text-muted-foreground">
                            Starter includes in-app notifications only. Email,
                            Slack, Discord, and webhook alert channels are
                            available on Growth and higher plans.
                        </p>
                        <Button
                            type="button"
                            className="mt-3"
                            variant="secondary"
                            onClick={() => router.visit('/billing/plans')}
                        >
                            View plans
                        </Button>
                    </div>
                ) : null}

                <section className="rounded-lg border border-border bg-card shadow-xs">
                    <div className="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                        <div className="flex items-center gap-2">
                            <BellRing
                                className="size-4 text-slate-brand"
                                aria-hidden
                            />
                            <h2 className="text-sm font-semibold">Channels</h2>
                        </div>
                        {manageEnabled ? (
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => {
                                    setEditingId(null);
                                    setShowCreate((open) => !open);
                                }}
                            >
                                <Plus className="size-4" aria-hidden />
                                Add channel
                            </Button>
                        ) : null}
                    </div>

                    {showCreate && manageEnabled ? (
                        <Form
                            action={`/tenants/${tenant.id}/alerts/channels`}
                            method="post"
                            className="space-y-4 border-b border-border p-5"
                            onSuccess={() => setShowCreate(false)}
                        >
                            {({ errors, processing }) => (
                                <ChannelFields
                                    channelTypes={channelTypes}
                                    eventKeys={eventKeys}
                                    errors={errors}
                                    processing={processing}
                                    submitLabel="Create channel"
                                    onCancel={() => setShowCreate(false)}
                                />
                            )}
                        </Form>
                    ) : null}

                    {channels.length === 0 ? (
                        <p className="px-5 py-8 text-sm text-muted-foreground">
                            No alert channels yet.
                            {channelsEntitled
                                ? ' Add email, Slack, Discord, or a signed webhook.'
                                : ' Upgrade to configure external channels.'}
                        </p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {channels.map((channel) => (
                                <li key={channel.id} className="px-5 py-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">
                                                {channel.name}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {channel.type} ·{' '}
                                                {channel.target_hint}
                                                {channel.enabled
                                                    ? ''
                                                    : ' · disabled'}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Events:{' '}
                                                {channel.events.join(', ')}
                                            </p>
                                            {channel.last_error ? (
                                                <p className="mt-1 text-xs text-destructive">
                                                    Last error:{' '}
                                                    {channel.last_error}
                                                </p>
                                            ) : null}
                                        </div>
                                        {manageEnabled ? (
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        router.post(
                                                            `/tenants/${tenant.id}/alerts/channels/${channel.id}/test`,
                                                        )
                                                    }
                                                >
                                                    Send test
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        setEditingId(
                                                            editingId ===
                                                                channel.id
                                                                ? null
                                                                : channel.id,
                                                        )
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="destructive"
                                                    onClick={() =>
                                                        router.delete(
                                                            `/tenants/${tenant.id}/alerts/channels/${channel.id}`,
                                                        )
                                                    }
                                                >
                                                    Delete
                                                </Button>
                                            </div>
                                        ) : null}
                                    </div>

                                    {editingId === channel.id &&
                                    manageEnabled ? (
                                        <Form
                                            action={`/tenants/${tenant.id}/alerts/channels/${channel.id}`}
                                            method="put"
                                            className="mt-4 space-y-4 rounded-md border border-border p-4"
                                            onSuccess={() =>
                                                setEditingId(null)
                                            }
                                        >
                                            {({ errors, processing }) => (
                                                <ChannelFields
                                                    channelTypes={channelTypes}
                                                    eventKeys={eventKeys}
                                                    errors={errors}
                                                    processing={processing}
                                                    submitLabel="Save changes"
                                                    defaults={channel}
                                                    hideType
                                                    onCancel={() =>
                                                        setEditingId(null)
                                                    }
                                                />
                                            )}
                                        </Form>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="rounded-lg border border-border bg-card shadow-xs">
                    <div className="border-b border-border px-5 py-4">
                        <h2 className="text-sm font-semibold">
                            Delivery log (last 50)
                        </h2>
                    </div>
                    {deliveries.length === 0 ? (
                        <p className="px-5 py-8 text-sm text-muted-foreground">
                            No deliveries yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="border-b border-border text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-5 py-2 font-medium">
                                            When
                                        </th>
                                        <th className="px-5 py-2 font-medium">
                                            Channel
                                        </th>
                                        <th className="px-5 py-2 font-medium">
                                            Alert
                                        </th>
                                        <th className="px-5 py-2 font-medium">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {deliveries.map((delivery) => (
                                        <tr key={delivery.id}>
                                            <td className="px-5 py-2 text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    delivery.created_at,
                                                    timezone,
                                                )}
                                            </td>
                                            <td className="px-5 py-2">
                                                {delivery.channel_name ??
                                                    delivery.channel_id}
                                            </td>
                                            <td className="px-5 py-2">
                                                {delivery.alert_key}
                                            </td>
                                            <td className="px-5 py-2">
                                                {delivery.status}
                                                {delivery.error
                                                    ? ` · ${delivery.error}`
                                                    : ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function ChannelFields({
    channelTypes,
    eventKeys,
    errors,
    processing,
    submitLabel,
    defaults,
    hideType = false,
    onCancel,
}: {
    channelTypes: string[];
    eventKeys: string[];
    errors: Record<string, string>;
    processing: boolean;
    submitLabel: string;
    defaults?: Channel;
    hideType?: boolean;
    onCancel: () => void;
}) {
    return (
        <>
            {!hideType ? (
                <div className="grid gap-2">
                    <Label htmlFor="type">Type</Label>
                    <select
                        id="type"
                        name="type"
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        defaultValue={defaults?.type ?? 'email'}
                        required
                    >
                        {channelTypes.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.type} />
                </div>
            ) : null}

            <div className="grid gap-2">
                <Label htmlFor="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    defaultValue={defaults?.name ?? ''}
                    required
                    maxLength={120}
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="target">
                    {hideType
                        ? 'Target (leave blank to keep current)'
                        : 'Target (email or HTTPS webhook URL)'}
                </Label>
                <Input
                    id="target"
                    name="target"
                    defaultValue=""
                    placeholder={defaults?.target_hint}
                    required={!hideType}
                />
                <InputError message={errors.target} />
            </div>

            <fieldset className="grid gap-2">
                <legend className="text-sm font-medium">Events</legend>
                <div className="grid gap-2 sm:grid-cols-2">
                    {eventKeys.map((key) => (
                        <label
                            key={key}
                            className="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                name="events[]"
                                value={key}
                                defaultChecked={
                                    defaults?.events?.includes(key) ??
                                    key === 'all_critical'
                                }
                            />
                            {key}
                        </label>
                    ))}
                </div>
                <InputError message={errors.events} />
            </fieldset>

            <label className="flex items-center gap-2 text-sm">
                <input type="hidden" name="enabled" value="0" />
                <input
                    type="checkbox"
                    name="enabled"
                    value="1"
                    defaultChecked={defaults?.enabled ?? true}
                />
                Enabled
            </label>

            <div className="flex gap-2">
                <Button type="submit" disabled={processing}>
                    {processing ? 'Saving…' : submitLabel}
                </Button>
                <Button type="button" variant="secondary" onClick={onCancel}>
                    Cancel
                </Button>
            </div>
        </>
    );
}
