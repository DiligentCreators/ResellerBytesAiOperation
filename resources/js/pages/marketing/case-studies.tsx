import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { pricing, product } from '@/routes';

type Study = {
    slug: string;
    title: string;
    segment: string;
    before: string;
    after: string;
    metrics: string[];
};

type Props = { studies: Study[] };

export default function CaseStudiesPage({ studies }: Props) {
    return (
        <>
            <Head title="Case studies" />
            <div className="mx-auto max-w-5xl px-4 py-16 sm:px-6 sm:py-20">
                <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                    Sanitized case studies
                </p>
                <h1 className="mt-3 text-4xl font-semibold tracking-[-0.02em]">
                    Before / after without customer PII
                </h1>
                <p className="mt-4 max-w-2xl text-muted-foreground">
                    Composite scenarios from pilot patterns. No invoices,
                    customers, or payment data — only ops signals and workflow
                    outcomes.
                </p>

                <div className="mt-12 space-y-8">
                    {studies.map((study) => (
                        <article
                            key={study.slug}
                            className="rounded-lg border border-border bg-card p-6 shadow-xs"
                        >
                            <p className="text-xs font-medium text-muted-foreground uppercase">
                                {study.segment}
                            </p>
                            <h2 className="mt-2 text-xl font-semibold">
                                {study.title}
                            </h2>
                            <div className="mt-5 grid gap-4 md:grid-cols-2">
                                <div>
                                    <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        Before
                                    </h3>
                                    <p className="mt-2 text-sm leading-6">
                                        {study.before}
                                    </p>
                                </div>
                                <div>
                                    <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        After
                                    </h3>
                                    <p className="mt-2 text-sm leading-6">
                                        {study.after}
                                    </p>
                                </div>
                            </div>
                            <ul className="mt-5 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                                {study.metrics.map((metric) => (
                                    <li key={metric}>{metric}</li>
                                ))}
                            </ul>
                        </article>
                    ))}
                </div>

                <div className="mt-10 flex flex-wrap gap-3">
                    <Button asChild>
                        <Link href={product.url()}>Product story</Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={pricing.url()}>Pricing</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
