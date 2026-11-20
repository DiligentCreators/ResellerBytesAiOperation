import { Head } from '@inertiajs/react';

export default function PrivacyPolicy() {
    return (
        <>
            <Head title="Privacy Policy" />
            <div className="mx-auto max-w-3xl px-4 py-16 sm:px-6">
                <p className="rounded-md border border-warning/25 bg-warning-soft px-3 py-2 text-sm text-warning-fg">
                    Placeholder legal text — replace with counsel-approved
                    Privacy Policy before commercial launch.
                </p>
                <h1 className="mt-8 text-3xl font-semibold tracking-tight">
                    Privacy Policy
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Last updated: placeholder
                </p>
                <div className="mt-8 space-y-4 text-sm leading-6 text-muted-foreground">
                    <p>
                        This placeholder Privacy Policy explains how
                        ResellerBytes AI Operations may process information. It
                        is not final and must be replaced by the product owner.
                    </p>
                    <h2 className="text-base font-semibold text-foreground">
                        1. Information we process
                    </h2>
                    <p>
                        Account details (name, email), workspace metadata, and
                        sanitized WHMCS diagnostic snapshots submitted through
                        integrations. The product is designed to avoid
                        collecting customer PII, payment card data, and
                        credentials in diagnostic payloads.
                    </p>
                    <h2 className="text-base font-semibold text-foreground">
                        2. How we use information
                    </h2>
                    <p>
                        To operate the service, authenticate users, analyze
                        diagnostics, generate advisory findings, bill
                        subscriptions, and maintain security/audit trails.
                    </p>
                    <h2 className="text-base font-semibold text-foreground">
                        3. Sharing
                    </h2>
                    <p>
                        Subprocessors (for example email, AI analysis, and
                        payment providers) may process data as needed to deliver
                        the service. List concrete vendors here before launch.
                    </p>
                    <h2 className="text-base font-semibold text-foreground">
                        4. Retention
                    </h2>
                    <p>
                        Diagnostic retention follows plan entitlements and
                        workspace configuration. Account and billing records are
                        retained as required for operations and legal
                        obligations.
                    </p>
                    <h2 className="text-base font-semibold text-foreground">
                        5. Contact
                    </h2>
                    <p>
                        Replace this section with the operator&apos;s privacy
                        contact details before launch.
                    </p>
                </div>
            </div>
        </>
    );
}
