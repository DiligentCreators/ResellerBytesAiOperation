import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Copy, KeyRound, Server } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { InfoTip } from '@/components/info-tip';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWorkspaceTimezone } from '@/hooks/use-workspace-timezone';
import { formatDateTime } from '@/lib/dates';
import { helpText } from '@/lib/help-text';
import { edit as editSecurity } from '@/routes/security';
import type { IntegrationCredentialsFlash } from '@/types/auth';

type HeartbeatState = 'unknown' | 'ok' | 'stale' | 'down';

type Integration = {
    id: string;
    name: string;
    status: string;
    addon_version: string | null;
    last_seen_at: string | null;
    last_heartbeat_at: string | null;
    cron_php_version: string | null;
    heartbeat_state: HeartbeatState;
    auto_collect_enabled: boolean;
    diagnostic_runs_count: number;
    tenant_id: string | null;
    host_connection_configured?: boolean;
    host_connection_summary?: {
        protocol: string;
        host: string;
        port: number;
        username: string;
        remote_whmcs_root: string;
    } | null;
};

function heartbeatBadge(state: HeartbeatState): {
    label: string;
    tone: 'success' | 'warning' | 'danger' | 'neutral';
} {
    switch (state) {
        case 'ok':
            return { label: 'Online', tone: 'success' };
        case 'stale':
            return { label: 'Quiet for 30 min', tone: 'warning' };
        case 'down':
            return { label: 'Offline', tone: 'danger' };
        default:
            return { label: 'Waiting for first contact', tone: 'neutral' };
    }
}

type Props = {
    integrations: {
        data: Integration[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

function credentialsJson(credentials: IntegrationCredentialsFlash): string {
    return JSON.stringify(
        {
            integration_id: credentials.integration_id,
            secret: credentials.secret,
        },
        null,
        2,
    );
}

export default function IntegrationsIndex({ integrations }: Props) {
    const { auth, flash } = usePage().props;
    const timezone = useWorkspaceTimezone();
    const credentials = flash?.integration_credentials ?? null;
    const canManage = auth.can_manage_integrations;
    const isPlatformOperator = auth.is_platform_operator;
    const manageableTenants = (auth.tenants ?? []).filter((tenant) =>
        ['owner', 'admin'].includes(tenant.role),
    );
    const [dismissedCredentials, setDismissedCredentials] = useState(false);
    const showCredentials = credentials !== null && !dismissedCredentials;

    return (
        <>
            <Head title="My WHMCS" />
            <div className="space-y-6">
                <PageHeader
                    title="My WHMCS"
                    description="Connect your WHMCS sites, download the addon, and manage backup connection details."
                />

                {showCredentials && credentials ? (
                    <section className="rounded-lg border border-warning/30 bg-warning-soft/30 p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            {credentials.action === 'rotated'
                                ? 'New connection key'
                                : 'Connection details'}
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Shown once only. Copy these values into a connection
                            file on your WHMCS server (mode 0600, outside the
                            web root). Never paste the connection key into WHMCS
                            addon settings.
                        </p>
                        <div className="mt-4 space-y-3">
                            <div>
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Site name
                                </p>
                                <p className="mt-1 text-sm font-medium">
                                    {credentials.name}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Connection ID
                                </p>
                                <div className="mt-1 flex items-center gap-2 rounded-lg border border-border bg-surface p-3">
                                    <code className="min-w-0 flex-1 text-sm break-all">
                                        {credentials.integration_id}
                                    </code>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            navigator.clipboard.writeText(
                                                credentials.integration_id,
                                            )
                                        }
                                        className="rounded-md p-2 hover:bg-card"
                                        aria-label="Copy connection ID"
                                    >
                                        <Copy className="size-4" />
                                    </button>
                                </div>
                            </div>
                            <div>
                                <p className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Connection key
                                    <InfoTip
                                        label="Connection key"
                                        text={helpText.connectionKey}
                                    />
                                </p>
                                <div className="mt-1 flex items-center gap-2 rounded-lg border border-border bg-surface p-3">
                                    <code className="min-w-0 flex-1 text-sm break-all">
                                        {credentials.secret}
                                    </code>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            navigator.clipboard.writeText(
                                                credentials.secret,
                                            )
                                        }
                                        className="rounded-md p-2 hover:bg-card"
                                        aria-label="Copy connection key"
                                    >
                                        <Copy className="size-4" />
                                    </button>
                                </div>
                            </div>
                            <div>
                                <p className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Connection file
                                    <InfoTip
                                        label="Connection file"
                                        text={helpText.connectionFile}
                                    />
                                </p>
                                <div className="mt-1 flex items-start gap-2 rounded-lg border border-border bg-surface p-3">
                                    <pre className="min-w-0 flex-1 overflow-x-auto text-xs leading-5">
                                        <code>
                                            {credentialsJson(credentials)}
                                        </code>
                                    </pre>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            navigator.clipboard.writeText(
                                                credentialsJson(credentials),
                                            )
                                        }
                                        className="rounded-md p-2 hover:bg-card"
                                        aria-label="Copy connection file"
                                    >
                                        <Copy className="size-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            className="mt-4"
                            onClick={() => setDismissedCredentials(true)}
                        >
                            I saved the connection file
                        </Button>
                    </section>
                ) : null}

                {canManage ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <h2 className="text-sm font-semibold">
                            Connect your WHMCS
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            3 steps: download, create your connection key, paste
                            it on your server. Takes about 10 minutes.
                        </p>
                        {!auth.two_factor_enabled ? (
                            <p className="mt-3 text-xs text-muted-foreground">
                                Tip: turn on{' '}
                                <Link
                                    href={editSecurity()}
                                    className="underline underline-offset-2 hover:no-underline"
                                >
                                    two-factor authentication
                                </Link>{' '}
                                in Settings for extra security.
                            </p>
                        ) : null}
                        <Form
                            action="/integrations"
                            method="post"
                            className="mt-4 space-y-4"
                            options={{ preserveScroll: true }}
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Site name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            maxLength={120}
                                            placeholder="Main billing"
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    {manageableTenants.length > 1 ? (
                                        <div className="grid gap-2">
                                            <Label htmlFor="tenant_id">
                                                Workspace
                                            </Label>
                                            <select
                                                id="tenant_id"
                                                name="tenant_id"
                                                required
                                                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                                defaultValue=""
                                            >
                                                <option value="" disabled>
                                                    Select a workspace
                                                </option>
                                                {manageableTenants.map(
                                                    (tenant) => (
                                                        <option
                                                            key={tenant.id}
                                                            value={tenant.id}
                                                        >
                                                            {tenant.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <InputError
                                                message={errors.tenant_id}
                                            />
                                        </div>
                                    ) : null}
                                    {manageableTenants.length === 1 ? (
                                        <input
                                            type="hidden"
                                            name="tenant_id"
                                            value={manageableTenants[0].id}
                                        />
                                    ) : null}
                                    <Button type="submit" disabled={processing}>
                                        Connect this WHMCS
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                ) : null}

                {isPlatformOperator ? (
                    <details className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <summary className="cursor-pointer text-sm font-semibold">
                            Platform: CLI create
                        </summary>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Optional Forge-shell provisioning for operators.
                            Prefer the Connect WHMCS form for tenant workspaces.
                        </p>
                        <div className="mt-4 flex items-center gap-2 rounded-lg border border-border bg-surface p-3">
                            <code className="min-w-0 flex-1 text-sm break-all">
                                php artisan whmcs:integration:create
                                &quot;ResellerBytes Production WHMCS&quot;
                                --owner=verified@example.com
                                --tenant=&lt;tenant-uuid&gt;
                            </code>
                            <button
                                type="button"
                                onClick={() =>
                                    navigator.clipboard.writeText(
                                        'php artisan whmcs:integration:create "ResellerBytes Production WHMCS" --owner=verified@example.com --tenant=<tenant-uuid>',
                                    )
                                }
                                className="rounded-md p-2 hover:bg-card"
                                aria-label="Copy integration command"
                            >
                                <Copy className="size-4" />
                            </button>
                        </div>
                    </details>
                ) : null}

                {integrations.data.length === 0 ? (
                    <EmptyState
                        icon={Server}
                        title="No WHMCS connected"
                        description={
                            canManage
                                ? 'Connect one to start.'
                                : 'Ask a workspace owner or admin to connect WHMCS, then download the addon from Setup.'
                        }
                    />
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border bg-card shadow-xs">
                        <div className="border-b border-border px-5 py-4">
                            <h2 className="text-sm font-semibold">
                                Connected WHMCS sites
                            </h2>
                        </div>
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">
                                Connected WHMCS sites
                            </caption>
                            <thead className="border-b border-border bg-surface text-xs font-medium text-muted-foreground">
                                <tr>
                                    <th scope="col" className="h-9 px-4">
                                        Name
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Status
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        <span className="inline-flex items-center gap-1.5 font-medium">
                                            Connection
                                            <InfoTip
                                                label="Connection"
                                                text={helpText.connectionBadge}
                                                side="bottom"
                                            />
                                        </span>
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Addon version
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        <span className="inline-flex items-center gap-1.5 font-medium">
                                            Cron PHP
                                            <InfoTip
                                                label="Cron PHP"
                                                text={helpText.scheduledTask}
                                                side="bottom"
                                            />
                                        </span>
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Last contact
                                    </th>
                                    <th scope="col" className="h-9 px-4">
                                        Last check
                                    </th>
                                    {canManage ? (
                                        <th scope="col" className="h-9 px-4">
                                            <span className="inline-flex items-center gap-1.5 font-medium">
                                                Auto-collect
                                                <InfoTip
                                                    label="Auto-collect"
                                                    text={
                                                        helpText.automaticChecks
                                                    }
                                                    side="bottom"
                                                />
                                            </span>
                                        </th>
                                    ) : null}
                                    {canManage ? (
                                        <th scope="col" className="h-9 px-4">
                                            Actions
                                        </th>
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody>
                                {integrations.data.map((item) => {
                                    const heartbeat = heartbeatBadge(
                                        item.heartbeat_state ?? 'unknown',
                                    );

                                    return (
                                        <tr
                                            key={item.id}
                                            className="h-11 border-b border-border last:border-0 hover:bg-surface/70"
                                        >
                                            <td className="px-4">
                                                <p className="font-medium">
                                                    {item.name}
                                                </p>
                                                <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                                                    {item.id}
                                                </p>
                                            </td>
                                            <td className="px-4">
                                                <StatusBadge
                                                    status={item.status}
                                                />
                                            </td>
                                            <td className="px-4">
                                                <StatusBadge
                                                    status={heartbeat.label}
                                                    tone={heartbeat.tone}
                                                />
                                            </td>
                                            <td className="px-4 text-muted-foreground">
                                                {item.addon_version ??
                                                    'Not reported'}
                                            </td>
                                            <td className="px-4 text-muted-foreground">
                                                {item.cron_php_version ?? '—'}
                                            </td>
                                            <td className="px-4 text-muted-foreground">
                                                {item.last_heartbeat_at
                                                    ? formatDateTime(
                                                          item.last_heartbeat_at,
                                                          timezone,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="px-4 tabular-nums">
                                                {item.diagnostic_runs_count}
                                            </td>
                                            {canManage ? (
                                                <td className="px-4">
                                                    {item.status === 'active' &&
                                                    item.tenant_id ? (
                                                        <Form
                                                            action={`/integrations/${item.id}/auto-collect`}
                                                            method="put"
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                            className="flex items-center gap-2"
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <>
                                                                    <input
                                                                        type="hidden"
                                                                        name="auto_collect_enabled"
                                                                        value={
                                                                            item.auto_collect_enabled
                                                                                ? '0'
                                                                                : '1'
                                                                        }
                                                                    />
                                                                    <label className="inline-flex items-center gap-2 text-xs text-muted-foreground">
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={
                                                                                item.auto_collect_enabled
                                                                            }
                                                                            disabled={
                                                                                processing
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) => {
                                                                                event.currentTarget.form?.requestSubmit();
                                                                            }}
                                                                            className="size-4 rounded border-input"
                                                                        />
                                                                        {item.auto_collect_enabled
                                                                            ? 'On'
                                                                            : 'Off'}
                                                                    </label>
                                                                </>
                                                            )}
                                                        </Form>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </td>
                                            ) : null}
                                            {canManage ? (
                                                <td className="px-4">
                                                    {item.status === 'active' &&
                                                    item.tenant_id ? (
                                                        <Form
                                                            action={`/integrations/${item.id}/rotate`}
                                                            method="post"
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <Button
                                                                    type="submit"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                    onClick={(
                                                                        event,
                                                                    ) => {
                                                                        if (
                                                                            !confirm(
                                                                                'Reset this connection key? The previous key stops working immediately.',
                                                                            )
                                                                        ) {
                                                                            event.preventDefault();
                                                                        }
                                                                    }}
                                                                >
                                                                    <KeyRound className="size-3.5" />
                                                                    Reset
                                                                    connection
                                                                    key
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    ) : null}
                                                </td>
                                            ) : null}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        <Pagination links={integrations.links} />
                    </div>
                )}

                {canManage ? (
                    <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="min-w-0">
                                <div className="flex items-center gap-1.5">
                                    <h2 className="text-sm font-semibold">
                                        Addon package
                                    </h2>
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Download the signed addon from Setup, then
                                    check the download is genuine before you
                                    install.
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Button variant="secondary" size="sm" asChild>
                                    <Link href="/whmcs-addon/setup">
                                        Setup guide
                                    </Link>
                                </Button>
                                <Button size="sm" asChild>
                                    <a href="/whmcs-addon/download">
                                        Download addon
                                    </a>
                                </Button>
                            </div>
                        </div>
                        <p className="mt-4 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                            Check the download is genuine
                            <InfoTip
                                label="Check the download is genuine"
                                text={helpText.downloadFingerprint}
                            />
                        </p>
                    </section>
                ) : null}

                {canManage && integrations.data.length > 0 ? (
                    <section
                        id="host-connection"
                        className="scroll-mt-6 space-y-4"
                    >
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <div className="flex items-center gap-1.5">
                                    <h2 className="text-xl font-semibold tracking-tight">
                                        Backup connection (optional)
                                    </h2>
                                    <InfoTip
                                        label="Backup connection (optional)"
                                        text={helpText.backupConnection}
                                    />
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Only needed if a fix cannot be applied
                                    automatically. Skip this for now.
                                </p>
                            </div>
                        </div>
                        {integrations.data.map((item) =>
                            item.tenant_id && item.status === 'active' ? (
                                <div
                                    key={`host-${item.id}`}
                                    className="rounded-lg border border-border bg-card p-5 shadow-xs"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h3 className="text-sm font-semibold">
                                            {item.name}
                                        </h3>
                                        <StatusBadge
                                            status={
                                                item.host_connection_configured
                                                    ? 'configured'
                                                    : 'missing'
                                            }
                                        />
                                    </div>
                                    {item.host_connection_summary ? (
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {item.host_connection_summary.protocol.toUpperCase()}{' '}
                                            {
                                                item.host_connection_summary
                                                    .username
                                            }
                                            @{item.host_connection_summary.host}
                                            :{item.host_connection_summary.port}{' '}
                                            →{' '}
                                            {
                                                item.host_connection_summary
                                                    .remote_whmcs_root
                                            }
                                        </p>
                                    ) : null}
                                    <Form
                                        action={`/integrations/${item.id}/host-connection`}
                                        method="put"
                                        className="mt-4 grid gap-3 sm:grid-cols-2"
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="space-y-1.5">
                                                    <Label
                                                        htmlFor={`protocol-${item.id}`}
                                                    >
                                                        Protocol
                                                    </Label>
                                                    <select
                                                        id={`protocol-${item.id}`}
                                                        name="protocol"
                                                        defaultValue={
                                                            item
                                                                .host_connection_summary
                                                                ?.protocol ??
                                                            'ftp'
                                                        }
                                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                                    >
                                                        <option value="ftp">
                                                            FTP
                                                        </option>
                                                        <option value="ftps">
                                                            FTPS
                                                        </option>
                                                    </select>
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label
                                                        htmlFor={`host-${item.id}`}
                                                    >
                                                        Host
                                                    </Label>
                                                    <Input
                                                        id={`host-${item.id}`}
                                                        name="host"
                                                        defaultValue={
                                                            item
                                                                .host_connection_summary
                                                                ?.host ?? ''
                                                        }
                                                        required
                                                    />
                                                    <InputError
                                                        message={errors.host}
                                                    />
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label
                                                        htmlFor={`port-${item.id}`}
                                                    >
                                                        Port
                                                    </Label>
                                                    <Input
                                                        id={`port-${item.id}`}
                                                        name="port"
                                                        type="number"
                                                        defaultValue={
                                                            item
                                                                .host_connection_summary
                                                                ?.port ?? 21
                                                        }
                                                        required
                                                    />
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label
                                                        htmlFor={`username-${item.id}`}
                                                    >
                                                        Username
                                                    </Label>
                                                    <Input
                                                        id={`username-${item.id}`}
                                                        name="username"
                                                        defaultValue={
                                                            item
                                                                .host_connection_summary
                                                                ?.username ?? ''
                                                        }
                                                        required
                                                    />
                                                </div>
                                                <div className="space-y-1.5 sm:col-span-2">
                                                    <Label
                                                        htmlFor={`password-${item.id}`}
                                                    >
                                                        Password
                                                    </Label>
                                                    <Input
                                                        id={`password-${item.id}`}
                                                        name="password"
                                                        type="password"
                                                        autoComplete="new-password"
                                                        required
                                                    />
                                                </div>
                                                <div className="space-y-1.5 sm:col-span-2">
                                                    <Label
                                                        htmlFor={`root-${item.id}`}
                                                    >
                                                        Absolute WHMCS root on
                                                        server
                                                    </Label>
                                                    <Input
                                                        id={`root-${item.id}`}
                                                        name="remote_whmcs_root"
                                                        placeholder="/home/user/public_html"
                                                        defaultValue={
                                                            item
                                                                .host_connection_summary
                                                                ?.remote_whmcs_root ??
                                                            ''
                                                        }
                                                        required
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.remote_whmcs_root
                                                        }
                                                    />
                                                </div>
                                                <div className="flex flex-wrap gap-2 sm:col-span-2">
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        Save host credentials
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                    {item.host_connection_configured ? (
                                        <Form
                                            action={`/integrations/${item.id}/host-connection`}
                                            method="put"
                                            className="mt-3"
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <input
                                                        type="hidden"
                                                        name="clear"
                                                        value="1"
                                                    />
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        Remove host credentials
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    ) : null}
                                </div>
                            ) : null,
                        )}
                    </section>
                ) : null}
            </div>
        </>
    );
}
