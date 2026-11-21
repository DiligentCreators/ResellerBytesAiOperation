import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { caseStudies, pricing, register } from '@/routes';

type Props = {
    objections: { question: string; answer: string }[];
    packaging: { code: string; name: string; summary: string }[];
    marketplace: {
        positioning: string;
        one_liner: string;
        listing_checklist: string[];
    };
};

export default function ProductPage({
    objections,
    packaging,
    marketplace,
}: Props) {
    return (
        <>
            <Head title="WHMCS Ops Copilot" />
            <div className="relative overflow-hidden">
                <div
                    className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[480px] bg-[radial-gradient(55%_45%_at_50%_0%,rgb(8_168_233/.14),transparent)]"
                    aria-hidden
                />
                <div className="mx-auto max-w-5xl px-4 py-16 sm:px-6 sm:py-24">
                    <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                        ResellerBytes AI Operations
                    </p>
                    <h1 className="mt-4 max-w-3xl text-4xl font-semibold tracking-[-0.03em] sm:text-5xl">
                        WHMCS Ops Copilot
                    </h1>
                    <p className="mt-5 max-w-2xl text-lg text-muted-foreground">
                        {marketplace.one_liner}
                    </p>
                    <p className="mt-3 max-w-2xl text-sm text-muted-foreground">
                        {marketplace.positioning} WHMCS keeps running. We
                        detect, prioritize, explain, and deliver safe next
                        actions — with audit and multi-site workflow.
                    </p>
                    <div className="mt-8 flex flex-wrap gap-3">
                        <Button asChild>
                            <Link href={register.url()}>Start 14-day pilot</Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={pricing.url()}>View packaging</Link>
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={caseStudies.url()}>Case studies</Link>
                        </Button>
                    </div>

                    <section className="mt-16 grid gap-6 sm:grid-cols-3">
                        {packaging.map((tier) => (
                            <div
                                key={tier.code}
                                className="rounded-lg border border-border bg-card/80 p-5"
                            >
                                <h2 className="text-sm font-semibold">
                                    {tier.name}
                                </h2>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {tier.summary}
                                </p>
                            </div>
                        ))}
                    </section>

                    <section className="mt-16">
                        <h2 className="text-2xl font-semibold tracking-tight">
                            Objection handling
                        </h2>
                        <div className="mt-6 space-y-4">
                            {objections.map((item) => (
                                <div
                                    key={item.question}
                                    className="rounded-lg border border-border bg-card p-5"
                                >
                                    <h3 className="text-sm font-semibold">
                                        “{item.question}”
                                    </h3>
                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        {item.answer}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="mt-16 rounded-lg border border-dashed border-border p-6">
                        <h2 className="text-sm font-semibold">
                            Marketplace / partner listing checklist
                        </h2>
                        <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                            {marketplace.listing_checklist.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </section>
                </div>
            </div>
        </>
    );
}
