import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownToLine,
    CheckCircle2,
    FileArchive,
    FolderOpen,
    ShieldCheck,
} from 'lucide-react';

type Props = {
    addonVersion: string;
    downloadAvailable: boolean;
};

const steps = [
    'Take and verify a restorable WHMCS files and database backup.',
    'Open your 20i hosting control panel and launch File Manager.',
    'Navigate to the WHMCS installation directory, then modules/addons.',
    'Download the ZIP below, upload it into modules/addons, and use File Manager to extract it there.',
    'Confirm modules/addons/resellerbytes_ai_ops/resellerbytes_ai_ops.php exists.',
    'In WHMCS Admin, open Configuration → System Settings → Addon Modules and activate ResellerBytes AI Operations.',
    'Configure the addon with the absolute path to the external credentials JSON file. The secret itself must not be entered into WHMCS settings.',
    'Restrict Access Control to trusted administrator roles, save changes, then open Addons → ResellerBytes AI Operations.',
    'Run diagnostics once manually and verify the received snapshot in this dashboard.',
];

export default function WhmcsAddonSetup({
    addonVersion,
    downloadAvailable,
}: Props) {
    return (
        <>
            <Head title="WHMCS Addon & Setup" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        WHMCS Addon & Setup
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Download the signed, read-only diagnostic collector and
                        follow the controlled installation procedure.
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
                                WHMCS 8.13.7 · PHP 8.1+ · cURL required · ZIP
                                package
                            </p>
                            <p className="mt-3 text-sm">
                                This package contains only the WHMCS addon
                                folder and an installation text file. It does
                                not include credentials or secrets.
                            </p>
                            <div className="mt-4 flex flex-wrap gap-2">
                                {downloadAvailable ? (
                                    <a
                                        href="/whmcs-addon/download"
                                        className="inline-flex h-10 items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                                    >
                                        <ArrowDownToLine className="size-4" />{' '}
                                        Download WHMCS Addon ZIP
                                    </a>
                                ) : (
                                    <span className="text-sm text-destructive">
                                        ZIP support is not available on this
                                        server. Enable PHP ext-zip before
                                        deployment.
                                    </span>
                                )}
                                <Link
                                    href="/dashboard"
                                    className="inline-flex h-10 items-center rounded-md border px-4 text-sm font-medium hover:bg-muted"
                                >
                                    Back to dashboard
                                </Link>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="rounded-xl border border-amber-500/30 bg-amber-500/5 p-5">
                    <div className="flex items-start gap-3">
                        <AlertTriangle className="mt-0.5 size-5 text-amber-600" />
                        <div>
                            <h2 className="font-semibold">
                                WHMCS does not include a built-in ZIP addon
                                uploader
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                WHMCS activates addon modules from its Admin
                                Area, but the module files must first exist in
                                /modules/addons. You can avoid FTP: upload and
                                extract this ZIP using the 20i hosting File
                                Manager, then activate and configure it in WHMCS
                                Admin.
                            </p>
                        </div>
                    </div>
                </section>

                <section className="rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <FolderOpen className="size-5" />
                        <h2 className="font-semibold">Installation steps</h2>
                    </div>
                    <ol className="mt-5 space-y-4">
                        {steps.map((step, index) => (
                            <li key={step} className="flex gap-3">
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">
                                    {index + 1}
                                </span>
                                <p className="pt-1 text-sm leading-6">{step}</p>
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
                            Store the JSON file outside both the WHMCS
                            installation root and the public document root.
                        </li>
                        <li>
                            Set exact file permissions to 0600 and ensure the
                            PHP runtime user can read it.
                        </li>
                        <li>
                            The file contains the integration UUID and 256-bit
                            secret. The WHMCS addon setting stores only its
                            path.
                        </li>
                        <li>
                            Never paste the secret into WHMCS settings, support
                            tickets, chat, source control, or logs.
                        </li>
                    </ul>
                </section>

                <section className="rounded-xl border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <CheckCircle2 className="size-5" />
                        <h2 className="font-semibold">
                            First-run safety and rollback
                        </h2>
                    </div>
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                        The first release is manual-only and read-only. It does
                        not modify customers, invoices, payments, hosting
                        services, domains, or the existing 20i and ResellerClub
                        modules. Do not configure a cron job.
                    </p>
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                        To roll back: revoke the integration in this dashboard,
                        deactivate the addon in WHMCS, remove its folder through
                        File Manager, delete the external credentials file, and
                        verify normal WHMCS operation.
                    </p>
                </section>
            </div>
        </>
    );
}
