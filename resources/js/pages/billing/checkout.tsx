import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/input-error';
import { formatMoney } from '@/lib/money';

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
};

export default function CheckoutPage({
    tenant,
    plan,
    period,
    countries,
    billingDetails,
    pricesArePlaceholders,
}: Props) {
    const amount =
        period === 'yearly'
            ? plan.price_yearly_minor
            : plan.price_monthly_minor;

    return (
        <>
            <Head title="Checkout" />
            <div className="mx-auto max-w-3xl space-y-8 p-6 md:p-10">
                <div>
                    <p className="text-sm font-medium text-[#5B6B7D] uppercase">
                        Checkout
                    </p>
                    <h1 className="mt-2 text-2xl font-bold tracking-tight">
                        Review & pay
                    </h1>
                    <p className="mt-2 text-sm text-[#5B6B7D]">
                        {tenant.name} · {plan.name} · {period}
                    </p>
                    {pricesArePlaceholders && (
                        <p className="mt-3 rounded-lg bg-[#FFFBEB] px-4 py-3 text-sm text-[#B45309]">
                            Placeholder seed price.
                        </p>
                    )}
                </div>

                <div className="rounded-lg border border-[#E3E8EE] bg-white p-5">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="font-semibold">{plan.name}</p>
                            <p className="text-sm text-[#5B6B7D]">
                                Billed {period}
                            </p>
                        </div>
                        <p className="text-xl font-bold tabular-nums">
                            {formatMoney(amount, plan.currency)}
                        </p>
                    </div>
                </div>

                <Form
                    action={`/tenants/${tenant.id}/billing/checkout/${plan.id}`}
                    method="post"
                    className="space-y-4 rounded-lg border border-[#E3E8EE] bg-white p-5"
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
                                    className="bg-[#0779A8] hover:bg-[#066A93]"
                                >
                                    Pay {formatMoney(amount, plan.currency)}
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
