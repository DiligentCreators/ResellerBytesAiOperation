import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/money';
import { show as onboarding } from '@/routes/onboarding';
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
};

type Props = {
    plans: Plan[];
    pricesArePlaceholders: boolean;
    tenantId?: string | null;
};

export default function BillingPlans({
    plans,
    pricesArePlaceholders,
    tenantId,
}: Props) {
    const { auth } = usePage().props;
    const resolvedTenantId = tenantId ?? auth.tenants?.[0]?.id;

    return (
        <>
            <Head title="Plans" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-6 md:p-10">
                <div>
                    <p className="text-sm font-medium tracking-wide text-[#5B6B7D] uppercase">
                        Billing
                    </p>
                    <h1 className="mt-2 text-2xl font-bold tracking-tight">
                        Choose a plan
                    </h1>
                    {pricesArePlaceholders && (
                        <p className="mt-3 rounded-lg bg-[#FFFBEB] px-4 py-3 text-sm text-[#B45309]">
                            Placeholder seed prices — edit in platform billing
                            before launch.
                        </p>
                    )}
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    {plans.map((plan) => (
                        <div
                            key={plan.id}
                            className="rounded-lg border border-[#E3E8EE] bg-white p-5"
                        >
                            <h2 className="text-base font-semibold">
                                {plan.name}
                            </h2>
                            <p className="mt-2 text-sm text-[#5B6B7D]">
                                {plan.description}
                            </p>
                            <p className="mt-4 text-2xl font-bold tabular-nums">
                                {plan.is_contact_sales
                                    ? 'Contact sales'
                                    : formatMoney(
                                          plan.price_monthly_minor,
                                          plan.currency,
                                      )}
                            </p>
                            {!plan.is_contact_sales && resolvedTenantId && (
                                <Button
                                    asChild
                                    className="mt-4 bg-[#0779A8] hover:bg-[#066A93]"
                                >
                                    <Link
                                        href={`/tenants/${resolvedTenantId}/billing/checkout/${plan.id}?period=monthly`}
                                    >
                                        Continue to checkout
                                    </Link>
                                </Button>
                            )}
                        </div>
                    ))}
                </div>

                <div className="flex flex-wrap gap-3">
                    <Button asChild variant="outline">
                        <Link href={onboarding()}>
                            <ArrowLeft className="size-4" />
                            Back to onboarding
                        </Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={dashboard()}>Open dashboard</Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href="/pricing">Public pricing</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
