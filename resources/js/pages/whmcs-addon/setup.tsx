import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownToLine,
    CheckCircle2,
    FileArchive,
    FolderOpen,
    Link2,
    ShieldCheck,
} from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';

type Props = {
    addonVersion: string;
    downloadAvailable: boolean;
    downloadUnavailableReason: string;
    aiConfigured: boolean;
};

const steps = [
    {
        title: 'Create an integration',
        body: 'Open Integrations and create a connection for this WHMCS installation. Copy the one-time integration ID and secret shown after create (or rotate).',
    },
    {
        title: 'Download the addon package',
        body: 'Download the signed ZIP from this page. It contains only the addon module files — never credentials.',
    },
    {
        title: 'Upload into WHMCS',
        body: 'In your hosting control panel File Manager (or SFTP), open your WHMCS directory and go to modules/addons. Upload and extract the ZIP so modules/addons/resellerbytes_ai_ops/resellerbytes_ai_ops.php exists.',
    },
    {
        title: 'Activate in WHMCS Admin',
        body: 'In WHMCS go to Configuration → System Settings → Addon Modules, activate ResellerBytes AI Operations, and restrict Access Control to trusted admin roles.',
    },
    {
        title: 'Point to your credentials file',
        body: 'Create a JSON credentials file outside the WHMCS web root (permissions 0600). In the addon settings, enter only the absolute path to that file — never paste the secret into WHMCS.',
    },
    {
        title: 'Run a manual diagnostic',
        body: 'Open Addons → ResellerBytes AI Operations in WHMCS, run diagnostics once, then confirm the snapshot appears under Diagnostic Runs here.',
    },
];

export default function WhmcsAddonSetup({
    addonVersion,
    downloadAvailable,
    downloadUnavailableReason,
    aiConfigured,
}: Props) {
    const { auth } = usePage().props;
    const canManage = auth.can_manage_integrations;

    return (
        <>
            <Head title="WHMCS Addon & Setup" />
            <div className="space-y-6">
                <PageHeader
                    title="Connect your WHMCS"
                    description="Install the read-only diagnostic addon on your WHMCS control panel and link it to this workspace."
                />

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <div className="flex items-start gap-4">
                        <div className="grid size-11 place-items-center rounded-lg border border-border bg-surface shadow-xs">
                            <FileArchive
                                className="size-5 text-slate-brand"
                                aria-hidden
                            />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-sm font-semibold">
                                    ResellerBytes AI Operations for WHMCS
                                </h2>
                                <span className="inline-flex h-5 items-center rounded-md bg-muted px-1.5 text-xs font-medium text-muted-foreground ring-1 ring-border ring-inset">
                                    v{addonVersion}
                                </span>
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                WHMCS 8.13.7 · PHP 8.1+ · cURL · Signed ZIP
                                package
                            </p>
                            <p className="mt-3 text-sm leading-6">
                                This package is read-only. It does not change
                                customers, invoices, payments, hosting, domains,
                                or your existing registrar/hosting modules.
                            </p>
                            <p className="mt-3 text-sm leading-6 text-muted-foreground">
                                Diagnostics collect technical aggregates only:
                                cron health, module queue depth, overdue and
                                unpaid invoice counts, suspended or orphaned
                                services, domain expiry problems, ticket SLA
                                breaches, mail and gateway failure counts, disk
                                and database size signals, and basic security
                                flags. Client names, emails, addresses, ticket
                                message bodies, passwords, and secrets are never
                                collected or sent.
                            </p>
                            <div className="mt-3 flex flex-wrap items-center gap-2 text-xs">
                                <span className="text-muted-foreground">
                                    AI analysis:
                                </span>
                                <StatusBadge
                                    status={
                                        aiConfigured ? 'available' : 'pending'
                                    }
                                    tone={aiConfigured ? 'success' : 'warning'}
                                />
                            </div>
                            <div className="mt-4 flex flex-wrap gap-2">
                                {downloadAvailable ? (
                                    <Button asChild size="sm">
                                        <a href="/whmcs-addon/download">
                                            <ArrowDownToLine className="size-4" />
                                            Download addon ZIP
                                        </a>
                                    </Button>
                                ) : (
                                    <span className="text-sm text-destructive">
                                        {downloadUnavailableReason}
                                    </span>
                                )}
                                {canManage ? (
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        asChild
                                    >
                                        <Link href="/integrations">
                                            <Link2 className="size-4" />
                                            Create integration
                                        </Link>
                                    </Button>
                                ) : null}
                                <Button variant="secondary" size="sm" asChild>
                                    <Link href="/dashboard">
                                        Back to overview
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <div className="mb-4 flex items-center gap-2">
                        <FolderOpen
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">
                            How to set it up
                        </h2>
                    </div>
                    <ol className="space-y-4">
                        {steps.map((step, index) => (
                            <li key={step.title} className="flex gap-3">
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface text-xs font-semibold ring-1 ring-border">
                                    {index + 1}
                                </span>
                                <div className="pt-0.5">
                                    <p className="text-sm font-medium">
                                        {step.title}
                                    </p>
                                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                        {step.body}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ol>
                </section>

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <div className="mb-4 flex items-center gap-2">
                        <ShieldCheck
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">
                            Credentials file requirements
                        </h2>
                    </div>
                    <ul className="list-disc space-y-2 pl-5 text-sm leading-6 text-muted-foreground">
                        <li>
                            Store the JSON file outside the WHMCS installation
                            root and outside the public document root.
                        </li>
                        <li>
                            Set file permissions to{' '}
                            <code className="rounded bg-muted px-1">0600</code>{' '}
                            so only the PHP runtime user can read it.
                        </li>
                        <li>
                            The file holds{' '}
                            <code className="rounded bg-muted px-1">
                                integration_id
                            </code>{' '}
                            and{' '}
                            <code className="rounded bg-muted px-1">
                                secret
                            </code>
                            . The WHMCS addon setting stores only the file path.
                        </li>
                        <li>
                            Never paste the secret into WHMCS settings, tickets,
                            chat, source control, or logs.
                        </li>
                    </ul>
                    <p className="mt-4 text-sm font-medium">
                        Credentials JSON format
                    </p>
                    <pre className="mt-2 overflow-x-auto rounded-lg border border-border bg-surface p-3 text-xs leading-6">
                        <code>{`{
  "integration_id": "YOUR-INTEGRATION-UUID",
  "secret": "YOUR-64-CHARACTER-HEX-SECRET"
}`}</code>
                    </pre>
                    <p className="mt-2 text-xs text-muted-foreground">
                        Use the one-time values from Integrations after you
                        create or rotate a connection.
                    </p>
                </section>

                <section className="rounded-lg border border-border bg-card p-5 shadow-xs">
                    <div className="mb-4 flex items-center gap-2">
                        <CheckCircle2
                            className="size-4 text-slate-brand"
                            aria-hidden
                        />
                        <h2 className="text-sm font-semibold">
                            Safety and rollback
                        </h2>
                    </div>
                    <p className="text-sm leading-6 text-muted-foreground">
                        The addon is manual and read-only. Do not configure a
                        WHMCS cron for it in this release.
                    </p>
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                        To roll back: rotate or revoke the integration here,
                        deactivate the addon in WHMCS, remove the{' '}
                        <code className="rounded bg-muted px-1">
                            resellerbytes_ai_ops
                        </code>{' '}
                        folder, delete the external credentials file, and
                        confirm WHMCS still works normally.
                    </p>
                </section>
            </div>
        </>
    );
}
