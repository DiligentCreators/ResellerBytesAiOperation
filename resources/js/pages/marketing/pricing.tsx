import { Head, Link, usePage } from '@inertiajs/react';
import { Check, Minus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/money';
import { dashboard, register } from '@/routes';

type Plan = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    price_monthly_minor: number;
    price_yearly_minor: number;
    currency: string;
    trial_days: number;
    is_contact_sales: boolean;
    entitlements: {
        limits?: Record<string, number>;
        support_level?: string;
    };
};

type Props = {
    plans: Plan[];
    pricesArePlaceholders: boolean;
    faq: Array<{ question: string; answer: string }>;
};

export default function PricingPage({
    plans,
    pricesArePlaceholders,
    faq,
}: Props) {
    const { auth } = usePage().props;
    const [yearly, setYearly] = useState(false);
    const tenantId = auth.user ? auth.tenants?.[0]?.id : null;

    const highlightedCode = useMemo(() => {
        const growth = plans.find((plan) => plan.code === 'growth');
        return growth?.code ?? plans[1]?.code ?? plans[0]?.code;
    }, [plans]);

    const yearlySavePercent = useMemo(() => {
        const sample = plans.find(
            (plan) =>
                !plan.is_contact_sales &&
                plan.price_monthly_minor > 0 &&
                plan.price_yearly_minor > 0,
        );
        if (!sample) {
            return null;
        }
        const monthlyCost = sample.price_monthly_minor * 12;
        if (monthlyCost <= sample.price_yearly_minor) {
            return null;
        }
        return Math.round(
            ((monthlyCost - sample.price_yearly_minor) / monthlyCost) * 100,
        );
    }, [plans]);

    return (
        <>
            <Head title="Pricing" />
            <div className="relative overflow-hidden">
                <div
                    className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[420px] bg-[radial-gradient(60%_50%_at_50%_0%,rgb(8_168_233/.12),transparent)]"
                    aria-hidden
                />
                <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
                    <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                        Pricing
                    </p>
                    <h1 className="mt-3 text-4xl font-semibold tracking-[-0.02em] sm:text-5xl">
                        Plans that scale with your operations
                    </h1>
                    <p className="mt-4 max-w-2xl text-lg text-muted-foreground">
                        Diagnose WHMCS estates, review findings, and keep fixes
                        human-approved — pick the entitlements that fit your
                        team.
                    </p>

                    {pricesArePlaceholders && (
                        <p
                            role="status"
                            className="mt-6 rounded-md border border-warning/25 bg-warning-soft px-4 py-3 text-sm text-warning-fg"
                        >
                            Placeholder seed prices — platform admins must edit
                            amounts before commercial launch.
                        </p>
                    )}

                    <div
                        className="mt-10 inline-flex rounded-lg bg-muted p-1"
                        role="radiogroup"
                        aria-label="Billing period"
                    >
                        <button
                            type="button"
                            role="radio"
                            aria-checked={!yearly}
                            onClick={() => setYearly(false)}
                            className={`h-8 rounded-md px-4 text-sm font-medium transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                                !yearly
                                    ? 'bg-white text-foreground shadow-xs'
                                    : 'text-muted-foreground'
                            }`}
                        >
                            Monthly
                        </button>
                        <button
                            type="button"
                            role="radio"
                            aria-checked={yearly}
                            onClick={() => setYearly(true)}
                            className={`inline-flex h-8 items-center gap-2 rounded-md px-4 text-sm font-medium transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                                yearly
                                    ? 'bg-white text-foreground shadow-xs'
                                    : 'text-muted-foreground'
                            }`}
                        >
                            Yearly
                            {yearlySavePercent ? (
                                <Badge variant="success">
                                    save {yearlySavePercent}%
                                </Badge>
                            ) : null}
                        </button>
                    </div>

                    <div className="mt-12 grid items-stretch gap-6 md:grid-cols-2 lg:grid-cols-4">
                        {plans.map((plan) => {
                            const price = yearly
                                ? plan.price_yearly_minor
                                : plan.price_monthly_minor;
                            const perMonth =
                                yearly && !plan.is_contact_sales
                                    ? Math.round(plan.price_yearly_minor / 12)
                                    : null;
                            const highlighted = plan.code === highlightedCode;
                            const checkoutHref = tenantId
                                ? `/tenants/${tenantId}/billing/checkout/${plan.id}?period=${yearly ? 'yearly' : 'monthly'}`
                                : register();

                            return (
                                <div
                                    key={plan.id}
                                    className={`relative flex flex-col rounded-lg border bg-white p-6 ${
                                        highlighted
                                            ? 'border-brand bg-gradient-to-b from-brand-50 to-white shadow-[0_12px_40px_-12px_rgb(8_168_233/.35)] ring-1 ring-brand'
                                            : 'border-border'
                                    }`}
                                >
                                    {highlighted ? (
                                        <Badge
                                            variant="popular"
                                            className="absolute -top-3 left-6"
                                        >
                                            Popular
                                        </Badge>
                                    ) : null}
                                    <h2 className="text-sm font-semibold">
                                        {plan.name}
                                    </h2>
                                    <p className="mt-2 min-h-10 text-sm text-muted-foreground">
                                        {plan.description}
                                    </p>
                                    <div className="mt-4 flex items-baseline gap-1">
                                        <span className="text-4xl font-semibold tracking-tight tabular-nums">
                                            {plan.is_contact_sales
                                                ? 'Custom'
                                                : formatMoney(
                                                      perMonth ?? price,
                                                      plan.currency,
                                                  )}
                                        </span>
                                        {!plan.is_contact_sales ? (
                                            <span className="text-sm text-muted-foreground">
                                                /month
                                            </span>
                                        ) : null}
                                    </div>
                                    {yearly &&
                                    !plan.is_contact_sales &&
                                    perMonth !== null ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            billed yearly{' '}
                                            {formatMoney(
                                                plan.price_yearly_minor,
                                                plan.currency,
                                            )}
                                        </p>
                                    ) : null}
                                    <ul className="mt-6 space-y-2.5 text-sm">
                                        <li className="flex gap-2.5">
                                            <Check
                                                className="mt-0.5 size-4 text-brand"
                                                aria-hidden
                                            />
                                            {plan.entitlements.limits
                                                ?.integrations ?? 0}{' '}
                                            WHMCS integrations
                                        </li>
                                        <li className="flex gap-2.5">
                                            <Check
                                                className="mt-0.5 size-4 text-brand"
                                                aria-hidden
                                            />
                                            {plan.entitlements.limits
                                                ?.diagnostics_per_period ??
                                                0}{' '}
                                            diagnostics / period
                                        </li>
                                        <li className="flex gap-2.5">
                                            <Check
                                                className="mt-0.5 size-4 text-brand"
                                                aria-hidden
                                            />
                                            {plan.entitlements.limits
                                                ?.members ?? 0}{' '}
                                            team seats
                                        </li>
                                        <li className="flex gap-2.5">
                                            <Check
                                                className="mt-0.5 size-4 text-brand"
                                                aria-hidden
                                            />
                                            {plan.entitlements.limits
                                                ?.retention_days ?? 0}{' '}
                                            day retention
                                        </li>
                                        <li className="flex gap-2.5">
                                            <Check
                                                className="mt-0.5 size-4 text-brand"
                                                aria-hidden
                                            />
                                            Support:{' '}
                                            {plan.entitlements.support_level ??
                                                'standard'}
                                        </li>
                                    </ul>
                                    <div className="mt-auto pt-8">
                                        {plan.is_contact_sales ? (
                                            <Button
                                                asChild
                                                variant="secondary"
                                                size="lg"
                                                className="w-full"
                                            >
                                                <a href="mailto:sales@resellerbytes.com">
                                                    Contact sales
                                                </a>
                                            </Button>
                                        ) : (
                                            <Button
                                                asChild
                                                variant={
                                                    highlighted
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                                size="lg"
                                                className="w-full"
                                            >
                                                <Link href={checkoutHref}>
                                                    {tenantId
                                                        ? 'Checkout'
                                                        : 'Get started'}
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    <section className="mt-20 overflow-x-auto">
                        <h2 className="text-base font-semibold">
                            Compare plans
                        </h2>
                        <table className="mt-6 w-full min-w-[640px] text-sm">
                            <caption className="sr-only">
                                Plan entitlement comparison
                            </caption>
                            <thead>
                                <tr className="border-b border-border">
                                    <th
                                        scope="col"
                                        className="w-1/3 py-3 text-left text-xs font-semibold text-muted-foreground"
                                    >
                                        Feature
                                    </th>
                                    {plans.map((plan) => (
                                        <th
                                            key={plan.id}
                                            scope="col"
                                            className="py-3 text-center text-xs font-semibold text-muted-foreground"
                                        >
                                            {plan.name}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {(
                                    [
                                        ['integrations', 'Integrations'],
                                        [
                                            'diagnostics_per_period',
                                            'Diagnostics / period',
                                        ],
                                        ['members', 'Team seats'],
                                        ['retention_days', 'Retention (days)'],
                                    ] as const
                                ).map(([key, label]) => (
                                    <tr
                                        key={key}
                                        className="border-b border-border"
                                    >
                                        <th
                                            scope="row"
                                            className="py-3 text-left font-medium"
                                        >
                                            {label}
                                        </th>
                                        {plans.map((plan) => {
                                            const value =
                                                plan.entitlements.limits?.[key];
                                            return (
                                                <td
                                                    key={plan.id}
                                                    className="py-3 text-center tabular-nums"
                                                >
                                                    {value === undefined ? (
                                                        <Minus
                                                            className="mx-auto size-4 text-slate-brand"
                                                            aria-label="Not specified"
                                                        />
                                                    ) : (
                                                        value
                                                    )}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>

                    <section className="mt-16 max-w-3xl">
                        <h2 className="text-base font-semibold">FAQ</h2>
                        <div className="mt-6 space-y-6">
                            {faq.map((item) => (
                                <div key={item.question}>
                                    <h3 className="text-sm font-medium">
                                        {item.question}
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {item.answer}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>

                    {auth.user ? (
                        <div className="mt-10">
                            <Button asChild variant="secondary">
                                <Link href={dashboard()}>
                                    Back to dashboard
                                </Link>
                            </Button>
                        </div>
                    ) : null}
                </div>
            </div>
        </>
    );
}
