import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Check } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/dates';
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
    trial_enabled: boolean;
    monthly_active: boolean;
    yearly_active: boolean;
    is_contact_sales: boolean;
};

type ScheduledChange = {
    plan_id: number;
    plan_code: string;
    plan_name: string;
    charge_period: string;
    change_at: string;
};

type CurrentTrial = {
    plan_name: string;
    trial_ends_at: string | null;
    days_remaining: number;
};

type Props = {
    plans: Plan[];
    pricesArePlaceholders: boolean;
    tenantId?: string | null;
    scheduledChange?: ScheduledChange | null;
    currentTrial?: CurrentTrial | null;
    timezone?: string;
};

export default function BillingPlans({
    plans,
    pricesArePlaceholders,
    tenantId,
    scheduledChange = null,
    currentTrial = null,
    timezone = 'UTC',
}: Props) {
    const { auth } = usePage().props;
    const resolvedTenantId = tenantId ?? auth.tenants?.[0]?.id;
    const [yearly, setYearly] = useState(false);
    const hasMonthly = plans.some((plan) => plan.monthly_active);
    const hasYearly = plans.some((plan) => plan.yearly_active);
    const periodIsYearly = hasYearly && (yearly || !hasMonthly);
    const visiblePlans = plans.filter((plan) =>
        periodIsYearly ? plan.yearly_active : plan.monthly_active,
    );

    return (
        <>
            <Head title="Plans" />
            <div className="space-y-6">
                <PageHeader
                    title="Choose a plan"
                    description="Select entitlements for this workspace. You can change plans later from billing settings."
                    actions={
                        hasMonthly && hasYearly ? (
                            <div
                                className="inline-flex rounded-lg bg-muted p-1"
                                role="radiogroup"
                                aria-label="Billing period"
                            >
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={!periodIsYearly}
                                    onClick={() => setYearly(false)}
                                    className={`h-8 rounded-md px-4 text-sm font-medium ${
                                        !periodIsYearly
                                            ? 'bg-white text-foreground shadow-xs'
                                            : 'text-muted-foreground'
                                    }`}
                                >
                                    Monthly
                                </button>
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={periodIsYearly}
                                    onClick={() => setYearly(true)}
                                    className={`h-8 rounded-md px-4 text-sm font-medium ${
                                        periodIsYearly
                                            ? 'bg-white text-foreground shadow-xs'
                                            : 'text-muted-foreground'
                                    }`}
                                >
                                    Yearly
                                </button>
                            </div>
                        ) : null
                    }
                />

                {pricesArePlaceholders && (
                    <p
                        role="status"
                        className="rounded-md border border-warning/25 bg-warning-soft px-4 py-3 text-sm text-warning-fg"
                    >
                        Placeholder seed prices — edit in platform billing
                        before launch.
                    </p>
                )}

                {currentTrial && (
                    <div
                        role="status"
                        className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-card px-4 py-3 text-sm shadow-xs"
                    >
                        <p>
                            Trial ends on{' '}
                            {formatDate(currentTrial.trial_ends_at, {
                                timeZone: timezone,
                            })}
                            , {currentTrial.days_remaining}{' '}
                            {currentTrial.days_remaining === 1
                                ? 'day'
                                : 'days'}{' '}
                            left on {currentTrial.plan_name}. Choose any plan to
                            subscribe now — the paid period starts at payment.
                        </p>
                    </div>
                )}

                {scheduledChange && resolvedTenantId && (
                    <div
                        role="status"
                        className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-card px-4 py-3 text-sm shadow-xs"
                    >
                        <p>
                            Your plan changes to {scheduledChange.plan_name} (
                            {scheduledChange.charge_period}) on{' '}
                            {formatDate(scheduledChange.change_at, {
                                timeZone: timezone,
                            })}
                            .
                        </p>
                        <Button asChild variant="outline" size="sm">
                            <Link href={`/tenants/${resolvedTenantId}/billing`}>
                                Manage in billing
                            </Link>
                        </Button>
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {visiblePlans.map((plan) => {
                        const price = periodIsYearly
                            ? plan.price_yearly_minor
                            : plan.price_monthly_minor;
                        const highlighted = plan.code === 'growth';

                        return (
                            <div
                                key={plan.id}
                                className={`flex flex-col rounded-lg border bg-card p-5 shadow-xs ${
                                    highlighted
                                        ? 'relative border-brand ring-1 ring-brand'
                                        : 'border-border'
                                }`}
                            >
                                {highlighted ? (
                                    <Badge
                                        variant="popular"
                                        className="absolute -top-3 left-5"
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
                                <p className="mt-4 text-3xl font-semibold tracking-tight tabular-nums">
                                    {plan.is_contact_sales
                                        ? 'Custom'
                                        : formatMoney(price, plan.currency)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {plan.is_contact_sales
                                        ? 'Talk to sales'
                                        : periodIsYearly
                                          ? 'per year'
                                          : 'per month'}
                                </p>
                                <ul className="mt-4 space-y-2 text-sm text-muted-foreground">
                                    <li className="flex gap-2">
                                        <Check
                                            className="mt-0.5 size-4 text-brand"
                                            aria-hidden
                                        />
                                        {plan.trial_enabled &&
                                        plan.trial_days > 0
                                            ? `${plan.trial_days}-day free trial`
                                            : 'No trial'}
                                    </li>
                                </ul>
                                <div className="mt-auto space-y-2 pt-5">
                                    {plan.is_contact_sales ? (
                                        <Button
                                            asChild
                                            variant="secondary"
                                            className="w-full"
                                        >
                                            <a href="mailto:sales@resellerbytes.com">
                                                Contact sales
                                            </a>
                                        </Button>
                                    ) : resolvedTenantId ? (
                                        <>
                                            {!currentTrial &&
                                            plan.trial_enabled &&
                                            plan.trial_days > 0 ? (
                                                <Button
                                                    asChild
                                                    variant="secondary"
                                                    className="w-full"
                                                >
                                                    <Link
                                                        href={`/tenants/${resolvedTenantId}/billing/checkout/${plan.id}?period=${periodIsYearly ? 'yearly' : 'monthly'}`}
                                                    >
                                                        Start {plan.trial_days}
                                                        -day trial
                                                    </Link>
                                                </Button>
                                            ) : null}
                                            <Button asChild className="w-full">
                                                <Link
                                                    href={`/tenants/${resolvedTenantId}/billing/checkout/${plan.id}?period=${periodIsYearly ? 'yearly' : 'monthly'}`}
                                                >
                                                    {currentTrial
                                                        ? 'Subscribe now'
                                                        : 'Continue to checkout'}
                                                </Link>
                                            </Button>
                                        </>
                                    ) : null}
                                </div>
                            </div>
                        );
                    })}
                </div>

                <div className="flex flex-wrap gap-3">
                    <Button asChild variant="secondary">
                        <Link href={onboarding()}>
                            <ArrowLeft className="size-4" aria-hidden />
                            Back to onboarding
                        </Link>
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={dashboard()}>Open dashboard</Link>
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href="/pricing">Public pricing</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
