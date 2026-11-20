import { Link, usePage } from '@inertiajs/react';
import { CheckCircle2, ShieldCheck, Workflow } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const valueBullets = [
    {
        icon: Workflow,
        title: 'Connect WHMCS safely',
        body: 'Read-only diagnostic collection with human-reviewed recommendations.',
    },
    {
        icon: ShieldCheck,
        title: 'No automatic repairs',
        body: 'Existing modules, billing, and provisioning stay untouched.',
    },
    {
        icon: CheckCircle2,
        title: 'Evidence-backed findings',
        body: 'Sanitized snapshots only — no customer PII or payment data.',
    },
];

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="grid min-h-svh lg:grid-cols-2">
            <div className="flex items-center justify-center px-6 py-10 sm:px-8">
                <div className="w-full max-w-sm space-y-6">
                    <Link
                        href={home()}
                        className="inline-flex items-center gap-2.5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <AppLogoIcon className="size-7 text-brand" />
                        <span className="text-sm font-semibold tracking-tight">
                            {name}
                        </span>
                    </Link>
                    <div className="space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        {description ? (
                            <p className="text-sm text-muted-foreground">
                                {description}
                            </p>
                        ) : null}
                    </div>
                    {children}
                </div>
            </div>
            <aside className="relative hidden overflow-hidden border-l border-border bg-surface lg:flex lg:flex-col lg:justify-center lg:px-12 lg:py-10">
                <div
                    className="pointer-events-none absolute inset-x-0 top-0 h-[420px] bg-[radial-gradient(60%_50%_at_50%_0%,rgb(8_168_233/.12),transparent)]"
                    aria-hidden
                />
                <div className="relative z-10 max-w-md space-y-8">
                    <div className="rounded-lg border border-border bg-white p-5 shadow-pop">
                        <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                            Operations overview
                        </p>
                        <p className="mt-3 text-sm text-muted-foreground">
                            Connect to WHMCS, find configuration errors, and get
                            human-reviewed fix recommendations — without
                            changing production services.
                        </p>
                        <div className="mt-5 grid grid-cols-3 gap-3">
                            {['Integrations', 'Findings', 'Runs'].map(
                                (label, index) => (
                                    <div
                                        key={label}
                                        className="rounded-md border border-border bg-surface p-3"
                                    >
                                        <p className="text-[11px] text-muted-foreground">
                                            {label}
                                        </p>
                                        <p className="mt-1 text-lg font-semibold tabular-nums">
                                            {index === 0
                                                ? '—'
                                                : index === 1
                                                  ? '—'
                                                  : '—'}
                                        </p>
                                    </div>
                                ),
                            )}
                        </div>
                    </div>
                    <ul className="space-y-4">
                        {valueBullets.map(
                            ({ icon: Icon, title: itemTitle, body }) => (
                                <li key={itemTitle} className="flex gap-3">
                                    <div className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-md border border-border bg-white text-brand shadow-xs">
                                        <Icon className="size-4" aria-hidden />
                                    </div>
                                    <div>
                                        <p className="text-sm font-medium">
                                            {itemTitle}
                                        </p>
                                        <p className="mt-0.5 text-sm text-muted-foreground">
                                            {body}
                                        </p>
                                    </div>
                                </li>
                            ),
                        )}
                    </ul>
                </div>
            </aside>
        </div>
    );
}
