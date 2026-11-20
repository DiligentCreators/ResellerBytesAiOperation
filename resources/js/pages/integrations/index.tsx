import { Form, Head, usePage } from '@inertiajs/react';
import { Copy, KeyRound, Server } from 'lucide-react';
import { useState } from 'react';
import { Pagination } from '@/components/pagination';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { IntegrationCredentialsFlash } from '@/types/auth';

type Integration = {
    id: string;
    name: string;
    status: string;
    addon_version: string | null;
    last_seen_at: string | null;
    diagnostic_runs_count: number;
    tenant_id: string | null;
};

type Props = {
    integrations: {
        data: Integration[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

function date(value: string | null) {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : 'Never connected';
}

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
            <Head title="WHMCS Integrations" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        WHMCS Integrations
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Connected WHMCS installations and their diagnostic
                        activity.
                    </p>
                </div>

                {showCredentials && credentials && (
                    <section className="rounded-xl border border-amber-500/40 bg-amber-500/5 p-5">
                        <h2 className="font-semibold">
                            {credentials.action === 'rotated'
                                ? 'New integration secret'
                                : 'Integration credentials'}
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Shown once only. Copy these values into a
                            credentials JSON file on your WHMCS server (mode
                            0600, outside the web root). Never paste the secret
                            into WHMCS addon settings.
                        </p>
                        <div className="mt-4 space-y-3">
                            <div>
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Installation
                                </p>
                                <p className="mt-1 text-sm font-medium">
                                    {credentials.name}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Integration ID
                                </p>
                                <div className="mt-1 flex items-center gap-2 rounded-lg bg-muted p-3">
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
                                        className="rounded-md p-2 hover:bg-background"
                                        aria-label="Copy integration ID"
                                    >
                                        <Copy className="size-4" />
                                    </button>
                                </div>
                            </div>
                            <div>
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Secret
                                </p>
                                <div className="mt-1 flex items-center gap-2 rounded-lg bg-muted p-3">
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
                                        className="rounded-md p-2 hover:bg-background"
                                        aria-label="Copy secret"
                                    >
                                        <Copy className="size-4" />
                                    </button>
                                </div>
                            </div>
                            <div>
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Credentials JSON
                                </p>
                                <div className="mt-1 flex items-start gap-2 rounded-lg bg-muted p-3">
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
                                        className="rounded-md p-2 hover:bg-background"
                                        aria-label="Copy credentials JSON"
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
                            I have saved these credentials
                        </Button>
                    </section>
                )}

                {canManage && (
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="font-semibold">Connect WHMCS</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Create an integration for your workspace. The secret
                            is shown once so you can place it on your WHMCS
                            server.
                        </p>
                        <Form
                            action="/integrations"
                            method="post"
                            className="mt-4 space-y-4"
                            options={{ preserveScroll: true }}
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            Installation name
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            maxLength={120}
                                            placeholder="Production WHMCS"
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    {manageableTenants.length > 1 && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="tenant_id">
                                                Workspace
                                            </Label>
                                            <select
                                                id="tenant_id"
                                                name="tenant_id"
                                                required
                                                className="rounded-md border bg-background p-2 text-sm"
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
                                    )}
                                    {manageableTenants.length === 1 && (
                                        <input
                                            type="hidden"
                                            name="tenant_id"
                                            value={manageableTenants[0].id}
                                        />
                                    )}
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                    >
                                        Create integration
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                {isPlatformOperator && (
                    <details className="rounded-xl border bg-card p-5">
                        <summary className="cursor-pointer font-semibold">
                            Platform: CLI create
                        </summary>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Optional Forge-shell provisioning for operators.
                            Prefer the Connect WHMCS form for tenant workspaces.
                        </p>
                        <div className="mt-4 flex items-center gap-2 rounded-lg bg-muted p-3">
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
                                className="rounded-md p-2 hover:bg-background"
                                aria-label="Copy integration command"
                            >
                                <Copy className="size-4" />
                            </button>
                        </div>
                    </details>
                )}

                <section className="overflow-hidden rounded-xl border bg-card">
                    <div className="border-b p-5">
                        <h2 className="font-semibold">
                            Registered installations
                        </h2>
                    </div>
                    {integrations.data.length === 0 ? (
                        <div className="px-5 py-12 text-center">
                            <Server className="mx-auto size-8 text-muted-foreground" />
                            <p className="mt-3 font-medium">
                                No WHMCS connected yet
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {canManage
                                    ? 'Create an integration above, then install the WHMCS addon and credentials JSON on your WHMCS server.'
                                    : 'Ask a workspace owner or admin to connect WHMCS, then download the addon from Setup.'}
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/40 text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-5 py-3 font-medium">
                                            Installation
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Status
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Addon
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Last seen
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Snapshots
                                        </th>
                                        {canManage && (
                                            <th className="px-5 py-3 font-medium">
                                                Actions
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {integrations.data.map((item) => (
                                        <tr key={item.id}>
                                            <td className="px-5 py-4">
                                                <p className="font-medium">
                                                    {item.name}
                                                </p>
                                                <p className="mt-1 font-mono text-xs text-muted-foreground">
                                                    {item.id}
                                                </p>
                                            </td>
                                            <td className="px-5 py-4">
                                                <span className="rounded-full border px-2.5 py-1 text-xs capitalize">
                                                    {item.status}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {item.addon_version ??
                                                    'Not reported'}
                                            </td>
                                            <td className="px-5 py-4 text-muted-foreground">
                                                {date(item.last_seen_at)}
                                            </td>
                                            <td className="px-5 py-4">
                                                {item.diagnostic_runs_count}
                                            </td>
                                            {canManage && (
                                                <td className="px-5 py-4">
                                                    {item.status ===
                                                        'active' &&
                                                        item.tenant_id && (
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
                                                                                    'Rotate this secret? The previous secret stops working immediately.',
                                                                                )
                                                                            ) {
                                                                                event.preventDefault();
                                                                            }
                                                                        }}
                                                                    >
                                                                        <KeyRound className="size-3.5" />
                                                                        Rotate
                                                                        secret
                                                                    </Button>
                                                                )}
                                                            </Form>
                                                        )}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination links={integrations.links} />
                </section>
            </div>
        </>
    );
}
