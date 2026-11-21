import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Eye,
    Lock,
    Search,
    Server,
    ShieldCheck,
    Sparkles,
    Wrench,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const steps = [
    {
        icon: Server,
        title: 'Connect WHMCS',
        body: 'Install the read-only addon and create an integration so sanitized diagnostic snapshots can reach your workspace.',
    },
    {
        icon: Search,
        title: 'Find errors',
        body: 'Runs surface evidence-backed findings across configuration, modules, and operational health signals.',
    },
    {
        icon: Wrench,
        title: 'Review recommended fixes',
        body: 'Recommendations stay advisory. Your team decides what to change — nothing is applied automatically.',
    },
];

const features = [
    {
        icon: Eye,
        title: 'Read-only first',
        body: 'Collection is designed to inspect, not mutate. Billing, domains, and provisioning stay untouched.',
    },
    {
        icon: ShieldCheck,
        title: 'Sanitized evidence',
        body: 'Snapshots avoid customer PII, invoices, payment details, and credentials wherever possible.',
    },
    {
        icon: Sparkles,
        title: 'Human-reviewed AI',
        body: 'Analysis assists operators. Fixes require human approval before anything happens in WHMCS.',
    },
    {
        icon: Lock,
        title: 'Tenant isolation',
        body: 'Workspace memberships, HMAC ingestion, and audit trails keep operational data scoped and accountable.',
    },
];

const faqs = [
    {
        q: 'Does AI Operations change my WHMCS automatically?',
        a: 'No. The product is read-only first: it collects diagnostics and recommends fixes for humans to review. It does not auto-repair services, billing, domains, or provisioning.',
    },
    {
        q: 'What data is collected?',
        a: 'Sanitized diagnostic snapshots from the WHMCS addon. Customer records, payment details, and credentials are not part of the intended collection surface.',
    },
    {
        q: 'Do I need a plan to try it?',
        a: 'You can create a workspace and follow onboarding. Public pricing lists plan entitlements; commercial prices may still be placeholders until launch.',
    },
];

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="WHMCS diagnostics with human-reviewed fixes" />
            <section className="relative overflow-hidden">
                <div
                    className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[420px] bg-[radial-gradient(60%_50%_at_50%_0%,rgb(8_168_233/.12),transparent)]"
                    aria-hidden
                />
                <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20 lg:py-24">
                    <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                        ResellerBytes AI Operations
                    </p>
                    <h1 className="mt-4 max-w-3xl text-4xl font-semibold tracking-[-0.02em] sm:text-5xl">
                        Connects to WHMCS, finds errors, recommends
                        human-reviewed fixes
                    </h1>
                    <p className="mt-5 max-w-2xl text-lg text-muted-foreground">
                        A calm operations workspace for reseller hosting teams —
                        evidence-backed diagnostics without automatic changes to
                        production services.
                    </p>
                    <div className="mt-8 flex flex-wrap gap-3">
                        <Button size="xl" asChild>
                            <Link href={auth.user ? dashboard() : register()}>
                                {auth.user
                                    ? 'Open dashboard'
                                    : 'Create workspace'}
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                        </Button>
                        <Button size="xl" variant="secondary" asChild>
                            <Link href="/pricing">View pricing</Link>
                        </Button>
                        {!auth.user ? (
                            <Button size="xl" variant="ghost" asChild>
                                <Link href={login()}>Sign in</Link>
                            </Button>
                        ) : null}
                    </div>
                </div>
            </section>

            <section
                id="how-it-works"
                className="border-t border-border bg-surface"
            >
                <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                    <h2 className="text-2xl font-semibold tracking-tight">
                        How it works
                    </h2>
                    <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Three steps from connection to actionable findings.
                    </p>
                    <ol className="mt-10 grid gap-6 md:grid-cols-3">
                        {steps.map(({ icon: Icon, title, body }, index) => (
                            <li
                                key={title}
                                className="rounded-lg border border-border bg-card p-5 shadow-xs"
                            >
                                <div className="flex items-center gap-3">
                                    <span className="grid size-8 place-items-center rounded-md bg-primary-soft text-sm font-semibold text-info-fg tabular-nums">
                                        {index + 1}
                                    </span>
                                    <Icon
                                        className="size-4 text-brand"
                                        aria-hidden
                                    />
                                </div>
                                <h3 className="mt-4 text-sm font-semibold">
                                    {title}
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {body}
                                </p>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            <section className="border-t border-border">
                <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                    <h2 className="text-2xl font-semibold tracking-tight">
                        Built for careful operators
                    </h2>
                    <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Features that keep diagnostics useful without inventing
                        risk.
                    </p>
                    <div className="mt-10 grid gap-4 sm:grid-cols-2">
                        {features.map(({ icon: Icon, title, body }) => (
                            <div
                                key={title}
                                className="rounded-lg border border-border bg-card p-5 shadow-xs"
                            >
                                <Icon
                                    className="size-4 text-brand"
                                    aria-hidden
                                />
                                <h3 className="mt-3 text-sm font-semibold">
                                    {title}
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {body}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section
                id="security"
                className="border-t border-border bg-surface"
            >
                <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                    <h2 className="text-2xl font-semibold tracking-tight">
                        Security boundary
                    </h2>
                    <p className="mt-3 max-w-3xl text-sm leading-6 text-muted-foreground">
                        ResellerBytes AI Operations is intentionally
                        conservative. The WHMCS addon is designed for manual,
                        read-only diagnostic collection. Recommendations are
                        advisory and require human review. Existing reseller
                        modules (for example provisioning and registrar
                        workflows) remain outside the mutation path. We do not
                        claim certifications or publish invented compliance
                        badges here — treat this as a product safety posture,
                        not a marketing claim.
                    </p>
                </div>
            </section>

            <section className="border-t border-border">
                <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                    <div className="flex flex-col justify-between gap-6 rounded-lg border border-border bg-card p-6 shadow-xs sm:flex-row sm:items-center sm:p-8">
                        <div>
                            <h2 className="text-xl font-semibold tracking-tight">
                                Plans that match your operations
                            </h2>
                            <p className="mt-2 max-w-xl text-sm text-muted-foreground">
                                Compare entitlements for integrations,
                                diagnostics, seats, and retention.
                            </p>
                        </div>
                        <Button size="lg" asChild>
                            <Link href="/pricing">
                                See pricing
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>

            <section className="border-t border-border bg-surface">
                <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                    <h2 className="text-2xl font-semibold tracking-tight">
                        FAQ
                    </h2>
                    <div className="mt-8 max-w-3xl space-y-6">
                        {faqs.map((item) => (
                            <div key={item.q}>
                                <h3 className="text-sm font-semibold">
                                    {item.q}
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {item.a}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="border-t border-border">
                <div className="mx-auto max-w-6xl px-4 py-16 text-center sm:px-6">
                    <h2 className="text-2xl font-semibold tracking-tight">
                        Ready to inspect your WHMCS estate?
                    </h2>
                    <p className="mx-auto mt-3 max-w-xl text-sm text-muted-foreground">
                        Create a workspace, finish onboarding, and connect your
                        first integration when you are ready.
                    </p>
                    <div className="mt-8 flex flex-wrap justify-center gap-3">
                        <Button size="lg" asChild>
                            <Link href={auth.user ? dashboard() : register()}>
                                {auth.user ? 'Go to dashboard' : 'Get started'}
                            </Link>
                        </Button>
                        <Button size="lg" variant="secondary" asChild>
                            <Link href="/pricing">Pricing</Link>
                        </Button>
                    </div>
                </div>
            </section>
        </>
    );
}
