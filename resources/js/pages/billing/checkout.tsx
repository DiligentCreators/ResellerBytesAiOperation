import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/input-error';
import { formatDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';

type ScheduledChange = {
    plan_id: number;
    plan_code: string;
    plan_name: string;
    charge_period: string;
    change_at: string;
};

type Proration = {
    new_price_minor: number;
    credit_minor: number;
    total_due_minor: number;
    remaining_days: number;
    total_days: number;
    currency: string;
};

type Props = {
    tenant: { id: string; name: string };
    plan: {
        id: number;
        code: string;
        name: string;
        description: string | null;
        price_monthly_minor: number;
        price_yearly_minor: number;
        currency: string;
        trial_days: number;
    };
    period: 'monthly' | 'yearly';
    countries: Array<{ code: string; name: string }>;
    billingDetails: Record<string, string>;
    pricesArePlaceholders: boolean;
    changeMode?: 'new' | 'upgrade' | 'downgrade';
    proration?: Proration | null;
    currentPlan?: {
        id: number;
        plan_code: string;
        plan_name: string;
        billing_period: string;
        current_period_ends_at: string | null;
    } | null;
    scheduledChange?: ScheduledChange | null;
    timezone?: string;
};

export default function CheckoutPage({
    tenant,
    plan,
    period,
    countries,
    billingDetails,
    pricesArePlaceholders,
    changeMode = 'new',
    proration = null,
    currentPlan = null,
    scheduledChange = null,
    timezone = 'UTC',
}: Props) {
    const listPrice =
        period === 'yearly'
            ? plan.price_yearly_minor
            : plan.price_monthly_minor;
    const amountDue = proration?.total_due_minor ?? listPrice;
    const isDowngrade = changeMode === 'downgrade';
    const scheduleDate = currentPlan?.current_period_ends_at;

    return (
        <>
            <Head title="Checkout" />
            <div className="mx-auto max-w-3xl space-y-8 p-6 md:p-10">
                <div>
                    <p className="text-sm font-medium text-muted-foreground uppercase">
                        Checkout
                    </p>
                    <h1 className="mt-2 text-2xl font-bold tracking-tight">
                        {isDowngrade ? 'Schedule plan change' : 'Review & pay'}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {tenant.name} · {plan.name} · {period}
                    </p>
                    {pricesArePlaceholders && (
                        <p className="mt-3 rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning-fg">
                            Placeholder seed price.
                        </p>
                    )}
                    {scheduledChange && (
                        <p
                            role="status"
                            className="mt-3 rounded-md border border-border bg-surface px-4 py-3 text-sm"
                        >
                            This replaces your scheduled change to{' '}
                            {scheduledChange.plan_name} (
                            {scheduledChange.charge_period}) on{' '}
                            {formatDate(scheduledChange.change_at, {
                                timeZone: timezone,
                            })}
                            .
                        </p>
                    )}
                </div>

                <div className="rounded-lg border border-border bg-white p-5">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="font-semibold">{plan.name}</p>
                            <p className="text-sm text-muted-foreground">
                                Billed {period}
                            </p>
                        </div>
                        <p className="text-xl font-bold tabular-nums">
                            {formatMoney(listPrice, plan.currency)}
                        </p>
                    </div>

                    {isDowngrade && scheduleDate && (
                        <p className="mt-4 rounded-md border border-border bg-surface px-4 py-3 text-sm">
                            This is a downgrade. Your current plan stays active
                            until{' '}
                            {formatDate(scheduleDate, { timeZone: timezone })}.
                            No payment is taken today.
                        </p>
                    )}

                    {proration && changeMode === 'upgrade' && (
                        <div className="mt-4 space-y-2 border-t border-border pt-4 text-sm">
                            <div className="flex justify-between">
                                <span>New plan price</span>
                                <span className="tabular-nums">
                                    {formatMoney(
                                        proration.new_price_minor,
                                        proration.currency,
                                    )}
                                </span>
                            </div>
                            <div className="flex justify-between text-muted-foreground">
                                <span>
                                    Credit for unused time on{' '}
                                    {currentPlan?.plan_name ?? 'current plan'} (
                                    {proration.remaining_days} days)
                                </span>
                                <span className="tabular-nums">
                                    {formatMoney(
                                        -proration.credit_minor,
                                        proration.currency,
                                    )}
                                </span>
                            </div>
                            <div className="flex justify-between border-t border-border pt-2 font-semibold">
                                <span>Total due today</span>
                                <span className="tabular-nums">
                                    {formatMoney(
                                        proration.total_due_minor,
                                        proration.currency,
                                    )}
                                </span>
                            </div>
                        </div>
                    )}
                </div>

                <Form
                    action={`/tenants/${tenant.id}/billing/checkout/${plan.id}`}
                    method="post"
                    className="space-y-4 rounded-lg border border-border bg-white p-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <input type="hidden" name="period" value={period} />
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="company_name">
                                        Company name
                                    </Label>
                                    <Input
                                        id="company_name"
                                        name="company_name"
                                        defaultValue={
                                            billingDetails.company_name
                                        }
                                        required
                                    />
                                    <InputError message={errors.company_name} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="billing_email">
                                        Billing email
                                    </Label>
                                    <Input
                                        id="billing_email"
                                        name="billing_email"
                                        type="email"
                                        defaultValue={
                                            billingDetails.billing_email
                                        }
                                        required
                                    />
                                    <InputError
                                        message={errors.billing_email}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="country">Country</Label>
                                    <select
                                        id="country"
                                        name="country"
                                        defaultValue={billingDetails.country}
                                        className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                        required
                                    >
                                        {countries.map((country) => (
                                            <option
                                                key={country.code}
                                                value={country.code}
                                            >
                                                {country.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.country} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="tax_id">
                                        Tax / VAT ID (optional)
                                    </Label>
                                    <Input
                                        id="tax_id"
                                        name="tax_id"
                                        defaultValue={billingDetails.tax_id}
                                    />
                                </div>
                                <div className="space-y-2 md:col-span-2">
                                    <Label htmlFor="address_line1">
                                        Address
                                    </Label>
                                    <Input
                                        id="address_line1"
                                        name="address_line1"
                                        defaultValue={
                                            billingDetails.address_line1
                                        }
                                        required
                                    />
                                    <InputError
                                        message={errors.address_line1}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="city">City</Label>
                                    <Input
                                        id="city"
                                        name="city"
                                        defaultValue={billingDetails.city}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="postal_code">
                                        Postal code
                                    </Label>
                                    <Input
                                        id="postal_code"
                                        name="postal_code"
                                        defaultValue={
                                            billingDetails.postal_code
                                        }
                                    />
                                </div>
                            </div>
                            <InputError message={errors.checkout} />
                            <div className="flex gap-3 pt-2">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="bg-primary hover:bg-primary-hover"
                                >
                                    {isDowngrade
                                        ? `Schedule change for ${formatDate(scheduleDate, { timeZone: timezone })}`
                                        : amountDue === 0
                                          ? 'Confirm upgrade'
                                          : `Pay ${formatMoney(amountDue, plan.currency)}`}
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href="/billing/plans">
                                        Back to plans
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
