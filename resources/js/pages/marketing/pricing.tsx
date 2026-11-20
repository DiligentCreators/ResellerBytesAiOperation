import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/money';
import { register } from '@/routes';
import { dashboard } from '@/routes';

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

    return (
        <>
            <Head title="Pricing" />
            <div className="min-h-screen bg-[var(--surface,#F7F9FB)] text-[var(--foreground,#0F172A)]">
                <div className="mx-auto max-w-6xl px-6 py-16">
                    <p className="text-sm font-semibold tracking-wide text-[#0779A8] uppercase">
                        ResellerBytes AI Operations
                    </p>
                    <h1 className="mt-3 text-[28px] leading-9 font-bold tracking-tight">
                        Plans for WHMCS operators
                    </h1>
                    <p className="mt-3 max-w-2xl text-sm text-[#5B6B7D]">
                        Diagnose, analyze, and act across your reseller
                        workspace. Toggle monthly or yearly billing below.
                    </p>

                    {pricesArePlaceholders && (
                        <p className="mt-4 rounded-lg bg-[#FFFBEB] px-4 py-3 text-sm text-[#B45309]">
                            Placeholder seed prices — platform admins must edit
                            amounts before commercial launch.
                        </p>
                    )}

                    <div className="mt-8 flex items-center gap-3">
                        <span className="text-sm font-medium">Monthly</span>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={yearly}
                            onClick={() => setYearly((value) => !value)}
                            className={`relative h-7 w-12 rounded-full transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-[#08A8E9] focus-visible:outline-none ${yearly ? 'bg-[#0779A8]' : 'bg-[#D5DDE5]'}`}
                        >
                            <span
                                className={`absolute top-0.5 left-0.5 size-6 rounded-full bg-white transition-transform duration-150 ${yearly ? 'translate-x-5' : ''}`}
                            />
                        </button>
                        <span className="text-sm font-medium">Yearly</span>
                    </div>

                    <div className="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                        {plans.map((plan) => {
                            const price = yearly
                                ? plan.price_yearly_minor
                                : plan.price_monthly_minor;
                            const checkoutHref = tenantId
                                ? `/tenants/${tenantId}/billing/checkout/${plan.id}?period=${yearly ? 'yearly' : 'monthly'}`
                                : register();

                            return (
                                <div
                                    key={plan.id}
                                    className="rounded-lg border border-[#E3E8EE] bg-white p-6"
                                >
                                    <h2 className="text-base font-semibold">
                                        {plan.name}
                                    </h2>
                                    <p className="mt-2 min-h-10 text-sm text-[#5B6B7D]">
                                        {plan.description}
                                    </p>
                                    <p className="mt-6 text-3xl font-bold tabular-nums">
                                        {plan.is_contact_sales
                                            ? 'Custom'
                                            : formatMoney(price, plan.currency)}
                                    </p>
                                    <p className="text-xs text-[#5B6B7D]">
                                        {plan.is_contact_sales
                                            ? 'Talk to sales'
                                            : yearly
                                              ? 'per year'
                                              : 'per month'}
                                    </p>
                                    <ul className="mt-6 space-y-2 text-sm">
                                        <li>
                                            {plan.entitlements.limits
                                                ?.integrations ?? 0}{' '}
                                            WHMCS integrations
                                        </li>
                                        <li>
                                            {plan.entitlements.limits
                                                ?.diagnostics_per_period ??
                                                0}{' '}
                                            diagnostics / period
                                        </li>
                                        <li>
                                            {plan.entitlements.limits
                                                ?.members ?? 0}{' '}
                                            team seats
                                        </li>
                                        <li>
                                            {plan.entitlements.limits
                                                ?.retention_days ?? 0}{' '}
                                            day retention
                                        </li>
                                        <li>
                                            Support:{' '}
                                            {plan.entitlements.support_level ??
                                                'standard'}
                                        </li>
                                    </ul>
                                    <div className="mt-8">
                                        {plan.is_contact_sales ? (
                                            <Button
                                                asChild
                                                variant="outline"
                                                className="w-full"
                                            >
                                                <a href="mailto:sales@resellerbytes.com">
                                                    Contact sales
                                                </a>
                                            </Button>
                                        ) : (
                                            <Button
                                                asChild
                                                className="w-full bg-[#0779A8] hover:bg-[#066A93]"
                                            >
                                                <Link href={checkoutHref}>
                                                    {tenantId
                                                        ? 'Checkout'
                                                        : 'Start free trial'}
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    <section className="mt-16 max-w-3xl">
                        <h2 className="text-base font-semibold">FAQ</h2>
                        <div className="mt-6 space-y-6">
                            {faq.map((item) => (
                                <div key={item.question}>
                                    <h3 className="text-sm font-medium">
                                        {item.question}
                                    </h3>
                                    <p className="mt-2 text-sm text-[#5B6B7D]">
                                        {item.answer}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>

                    {auth.user && (
                        <div className="mt-10">
                            <Button asChild variant="outline">
                                <Link href={dashboard()}>
                                    Back to dashboard
                                </Link>
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
