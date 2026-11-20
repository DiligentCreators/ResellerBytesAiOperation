import { Head } from '@inertiajs/react';

export default function TermsOfService() {
    return (
        <>
            <Head title="Terms of Service" />
            <div className="mx-auto max-w-3xl px-4 py-16 sm:px-6">
                <p className="rounded-md border border-warning/25 bg-warning-soft px-3 py-2 text-sm text-warning-fg">
                    Placeholder legal text — replace with counsel-approved Terms
                    of Service before commercial launch.
                </p>
                <h1 className="mt-8 text-3xl font-semibold tracking-tight">
                    Terms of Service
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Last updated: placeholder
                </p>
                <div className="prose prose-sm mt-8 max-w-none space-y-4 text-sm leading-6 text-muted-foreground">
                    <p>
                        These placeholder Terms describe how ResellerBytes AI
                        Operations may be used. They are not final legal advice
                        and must be replaced by the product owner.
                    </p>
                    <h2 className="text-base font-semibold text-foreground">
                        1. Service description
                    </h2>
                    <p>
                        The service provides diagnostic visibility for WHMCS
                        installations, including sanitized snapshot ingestion
                        and advisory recommendations. Automatic remediation of
                        customer services is out of scope for the current
                        product posture.
                    </p>
                    <h2 className="text-base font-semibold text-foreground">
                        2. Accounts and workspaces
                    </h2>
                    <p>
                        You are responsible for maintaining accurate account
                        information, securing credentials, and managing
                        workspace memberships.
                    </p>
                    <h2 className="text-base font-semibold text-foreground">
                        3. Acceptable use
                    </h2>
                    <p>
                        Do not misuse the service, attempt unauthorized access,
                        or interfere with platform integrity, audit controls, or
                        other customers&apos; data.
                    </p>
                    <h2 className="text-base font-semibold text-foreground">
                        4. Billing
                    </h2>
                    <p>
                        Paid plans, trials, invoices, and cancellations are
                        governed by the pricing and billing flows presented in
                        the product. Placeholder prices in seed data are not an
                        offer.
                    </p>
                    <h2 className="text-base font-semibold text-foreground">
                        5. Contact
                    </h2>
                    <p>
                        Replace this section with the operator&apos;s legal
                        contact details before launch.
                    </p>
                </div>
            </div>
        </>
    );
}
