import { Head } from '@inertiajs/react';
import { Copy, Server } from 'lucide-react';

type Integration = {
    id: string;
    name: string;
    status: string;
    addon_version: string | null;
    last_seen_at: string | null;
    diagnostic_runs_count: number;
};
type Props = { integrations: { data: Integration[] } };

function date(value: string | null) {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : 'Never connected';
}

export default function IntegrationsIndex({ integrations }: Props) {
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
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="font-semibold">Create an integration</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Run this command on the Forge application server. The
                        generated secret is displayed once only.
                    </p>
                    <div className="mt-4 flex items-center gap-2 rounded-lg bg-muted p-3">
                        <code className="min-w-0 flex-1 text-sm break-all">
                            php artisan whmcs:integration:create "ResellerBytes
                            Production WHMCS"
                        </code>
                        <button
                            type="button"
                            onClick={() =>
                                navigator.clipboard.writeText(
                                    'php artisan whmcs:integration:create "ResellerBytes Production WHMCS"',
                                )
                            }
                            className="rounded-md p-2 hover:bg-background"
                            aria-label="Copy integration command"
                        >
                            <Copy className="size-4" />
                        </button>
                    </div>
                    <p className="mt-3 text-xs leading-5 text-muted-foreground">
                        Store the one-time secret securely, then create the
                        external WHMCS credentials JSON file with exact mode
                        0600. Never place the secret in WHMCS addon settings.
                    </p>
                </section>
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
                                No WHMCS integration registered
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Create the first integration using the Forge
                                command above.
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
