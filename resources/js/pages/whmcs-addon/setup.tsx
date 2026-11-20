import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownToLine,
    CheckCircle2,
    FileArchive,
    FolderOpen,
    Link2,
    ShieldCheck,
} from 'lucide-react';

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
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Connect your WHMCS
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Install the read-only diagnostic addon on your WHMCS
                        control panel and link it to this workspace.
                    </p>
                </div>

                <section className="rounded-xl border bg-card p-5">
                    <div className="flex items-start gap-4">
                        <div className="rounded-lg bg-muted p-3">
                            <FileArchive className="size-6" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="font-semibold">
                                    ResellerBytes AI Operations for WHMCS
                                </h2>
                                <span className="rounded-full border px-2 py-0.5 text-xs">
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
                            <div className="mt-3 flex flex-wrap gap-2 text-xs">
                                <span className="rounded-full border px-2.5 py-1">
                                    AI analysis:{' '}
                                    {aiConfigured
                                        ? 'Available'
                                        : 'Pending platform configuration'}
                                </span>
                            </div>
                            <div className="mt-4 flex flex-wrap gap-2">
                                {downloadAvailable ? (
                                    <a
                                        href="/whmcs-addon/download"
                                        className="inline-flex h-10 items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                                    >
                                        <ArrowDownToLine className="size-4" />
                                        Download addon ZIP
                                    </a>
                                ) : (
                                    <span className="text-sm text-destructive">
                                        {downloadUnavailableReason}
                                    </span>
                                )}
                                {canManage && (
                                    <Link
                                        href="/integrations"
                                        className="inline-flex h-10 items-center gap-2 rounded-md border px-4 text-sm font-medium hover:bg-muted"
                                    >
                                        <Link2 className="size-4" />
                                        Create integration
                                    </Link>
                                )}
                                <Link
                                    href="/dashboard"
                                    className="inline-flex h-10 items-center rounded-md border px-4 text-sm font-medium hover:bg-muted"
                                >
                                    Back to overview
                                </Link>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <FolderOpen className="size-5" />
                        <h2 className="font-semibold">How to set it up</h2>
                    </div>
                    <ol className="mt-5 space-y-4">
                        {steps.map((step, index) => (
                            <li key={step.title} className="flex gap-3">
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">
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

                <section className="rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <ShieldCheck className="size-5" />
                        <h2 className="font-semibold">
                            Credentials file requirements
                        </h2>
                    </div>
                    <ul className="mt-4 list-disc space-y-2 pl-5 text-sm leading-6 text-muted-foreground">
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
                    <pre className="mt-2 overflow-x-auto rounded-lg bg-muted p-3 text-xs leading-6">
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

                <section className="rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <CheckCircle2 className="size-5" />
                        <h2 className="font-semibold">Safety and rollback</h2>
                    </div>
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
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
