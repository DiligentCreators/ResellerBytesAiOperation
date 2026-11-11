import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, ArrowRight, ShieldCheck } from 'lucide-react';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="ResellerBytes AI Operations" />
            <main className="flex min-h-screen flex-col bg-background">
                <header className="mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-6">
                    <div>
                        <p className="text-sm font-semibold tracking-wide">RESELLERBYTES</p>
                        <p className="text-xs text-muted-foreground">AI Operations Engineer</p>
                    </div>
                    <Link href={auth.user ? dashboard() : login()} className="inline-flex h-10 items-center gap-2 rounded-md border px-4 text-sm font-medium hover:bg-muted">
                        {auth.user ? 'Open dashboard' : 'Sign in'} <ArrowRight className="size-4" />
                    </Link>
                </header>
                <section className="mx-auto flex w-full max-w-7xl flex-1 flex-col justify-center px-6 py-16">
                    <div className="max-w-3xl">
                        <span className="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs text-muted-foreground"><ShieldCheck className="size-3.5" /> Read-only diagnostics · Human-approved actions</span>
                        <h1 className="mt-8 text-4xl font-semibold tracking-tight sm:text-6xl">Understand your WHMCS operations before making changes.</h1>
                        <p className="mt-6 max-w-2xl text-lg leading-8 text-muted-foreground">A focused operations dashboard that receives sanitized WHMCS diagnostics, identifies evidence-backed risks, and gives your team clear recommendations without changing customer services, billing, domains, or provisioning.</p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            <Link href={auth.user ? dashboard() : login()} className="inline-flex h-11 items-center gap-2 rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground hover:bg-primary/90">Open operations dashboard <ArrowRight className="size-4" /></Link>
                            <div className="inline-flex h-11 items-center gap-2 rounded-md border px-4 text-sm text-muted-foreground"><Activity className="size-4" /> Manual-first diagnostic collection</div>
                        </div>
                    </div>
                    <div className="mt-20 grid gap-4 border-t pt-8 sm:grid-cols-3">
                        <div><p className="font-medium">No automatic repairs</p><p className="mt-1 text-sm text-muted-foreground">Recommendations remain advisory and require human approval.</p></div>
                        <div><p className="font-medium">Existing modules preserved</p><p className="mt-1 text-sm text-muted-foreground">20i provisioning and ResellerClub registrar workflows remain untouched.</p></div>
                        <div><p className="font-medium">Sanitized evidence only</p><p className="mt-1 text-sm text-muted-foreground">No customer records, invoices, payment details, or credentials are collected.</p></div>
                    </div>
                </section>
                <footer className="mx-auto w-full max-w-7xl px-6 py-6 text-xs text-muted-foreground">A product of Diligent Creators Pvt. Ltd. · ResellerBytes</footer>
            </main>
        </>
    );
}
