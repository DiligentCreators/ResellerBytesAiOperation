import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/money';

type Plan = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    price_monthly_minor: number;
    price_yearly_minor: number;
    currency: string;
    trial_days: number;
    sort_order: number;
    is_active: boolean;
    is_public: boolean;
    is_contact_sales: boolean;
    kelviq_plan_identifier_monthly: string | null;
    kelviq_plan_identifier_yearly: string | null;
    entitlements: {
        limits?: Record<string, number>;
        support_level?: string;
        'diagnostics.analysis'?: boolean;
        'diagnostics.reanalyze'?: boolean;
    };
};

type Props = {
    plans: Plan[];
    pricesArePlaceholders: boolean;
};

export default function PlatformPlansPage({
    plans,
    pricesArePlaceholders,
}: Props) {
    return (
        <AppLayout>
            <Head title="Plan catalog" />
            <div className="mx-auto max-w-5xl space-y-8 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Plans
                        </h1>
                        {pricesArePlaceholders && (
                            <p className="mt-2 text-sm text-[#B45309]">
                                Placeholder seed prices — edit before launch.
                            </p>
                        )}
                    </div>
                    <Link
                        href="/platform/billing"
                        className="text-sm text-[#0779A8]"
                    >
                        Back to billing
                    </Link>
                </div>

                <div className="space-y-6">
                    {plans.map((plan) => (
                        <Form
                            key={plan.id}
                            action={`/platform/billing/plans/${plan.id}`}
                            method="put"
                            className="space-y-4 rounded-lg border border-[#E3E8EE] bg-white p-5"
                        >
                            {({ processing }) => (
                                <>
                                    <div className="flex items-center justify-between">
                                        <h2 className="text-base font-semibold">
                                            {plan.name}{' '}
                                            <span className="font-mono text-xs text-[#5B6B7D]">
                                                {plan.code}
                                            </span>
                                        </h2>
                                        <p className="text-sm text-[#5B6B7D] tabular-nums">
                                            {formatMoney(
                                                plan.price_monthly_minor,
                                                plan.currency,
                                            )}
                                            /mo
                                        </p>
                                    </div>
                                    <input
                                        type="hidden"
                                        name="code"
                                        value={plan.code}
                                    />
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Name</Label>
                                            <Input
                                                name="name"
                                                defaultValue={plan.name}
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Currency</Label>
                                            <Input
                                                name="currency"
                                                defaultValue={plan.currency}
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Monthly (minor units)</Label>
                                            <Input
                                                name="price_monthly_minor"
                                                type="number"
                                                defaultValue={
                                                    plan.price_monthly_minor
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Yearly (minor units)</Label>
                                            <Input
                                                name="price_yearly_minor"
                                                type="number"
                                                defaultValue={
                                                    plan.price_yearly_minor
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Trial days</Label>
                                            <Input
                                                name="trial_days"
                                                type="number"
                                                defaultValue={plan.trial_days}
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Sort order</Label>
                                            <Input
                                                name="sort_order"
                                                type="number"
                                                defaultValue={plan.sort_order}
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>Description</Label>
                                            <Input
                                                name="description"
                                                defaultValue={
                                                    plan.description ?? ''
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Members limit</Label>
                                            <Input
                                                name="entitlements[limits][members]"
                                                type="number"
                                                defaultValue={
                                                    plan.entitlements.limits
                                                        ?.members ?? 0
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Integrations limit</Label>
                                            <Input
                                                name="entitlements[limits][integrations]"
                                                type="number"
                                                defaultValue={
                                                    plan.entitlements.limits
                                                        ?.integrations ?? 0
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Diagnostics / period</Label>
                                            <Input
                                                name="entitlements[limits][diagnostics_per_period]"
                                                type="number"
                                                defaultValue={
                                                    plan.entitlements.limits
                                                        ?.diagnostics_per_period ??
                                                    0
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Retention days</Label>
                                            <Input
                                                name="entitlements[limits][retention_days]"
                                                type="number"
                                                defaultValue={
                                                    plan.entitlements.limits
                                                        ?.retention_days ?? 30
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Support level</Label>
                                            <Input
                                                name="entitlements[support_level]"
                                                defaultValue={
                                                    plan.entitlements
                                                        .support_level ??
                                                    'email'
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>
                                                Kelviq plan id (monthly)
                                            </Label>
                                            <Input
                                                name="kelviq_plan_identifier_monthly"
                                                defaultValue={
                                                    plan.kelviq_plan_identifier_monthly ??
                                                    ''
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>
                                                Kelviq plan id (yearly)
                                            </Label>
                                            <Input
                                                name="kelviq_plan_identifier_yearly"
                                                defaultValue={
                                                    plan.kelviq_plan_identifier_yearly ??
                                                    ''
                                                }
                                            />
                                        </div>
                                    </div>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="is_active"
                                            value="1"
                                            defaultChecked={plan.is_active}
                                        />
                                        Active
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="is_public"
                                            value="1"
                                            defaultChecked={plan.is_public}
                                        />
                                        Public
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="is_contact_sales"
                                            value="1"
                                            defaultChecked={
                                                plan.is_contact_sales
                                            }
                                        />
                                        Contact sales only
                                    </label>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="bg-[#0779A8] hover:bg-[#066A93]"
                                    >
                                        Save plan
                                    </Button>
                                </>
                            )}
                        </Form>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
