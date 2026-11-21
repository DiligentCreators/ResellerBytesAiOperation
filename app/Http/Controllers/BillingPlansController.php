<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\TenantSubscription;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Authenticated plan picker used by onboarding and billing CTAs.
 */
class BillingPlansController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $plans = Plan::query()->publicCatalog()->get()->map(fn (Plan $plan): array => [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_monthly_minor' => $plan->price_monthly_minor,
            'price_yearly_minor' => $plan->price_yearly_minor,
            'currency' => $plan->currency,
            'trial_days' => $plan->trial_days,
            'is_contact_sales' => $plan->is_contact_sales,
            'entitlements' => $plan->entitlements,
        ]);

        $tenant = $request->user()?->tenants()->first();
        $scheduledChange = null;
        $timezone = 'UTC';

        if ($tenant !== null) {
            $timezone = $tenant->timezone ?: 'UTC';
            $subscription = TenantSubscription::query()
                ->with('scheduledPlan:id,code,name')
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', ['trialing', 'active', 'past_due', 'suspended'])
                ->whereNotNull('scheduled_plan_id')
                ->latest('id')
                ->first();

            if ($subscription !== null
                && $subscription->scheduled_plan_id !== null
                && $subscription->scheduled_change_at !== null
                && in_array($subscription->scheduled_charge_period, ['monthly', 'yearly'], true)) {
                $scheduledPlan = $subscription->scheduledPlan;
                $scheduledChange = [
                    'plan_id' => (int) $subscription->scheduled_plan_id,
                    'plan_code' => $scheduledPlan->code ?? '',
                    'plan_name' => $scheduledPlan->name ?? (string) $subscription->scheduled_plan_id,
                    'charge_period' => $subscription->scheduled_charge_period,
                    'change_at' => $subscription->scheduled_change_at->toIso8601String(),
                ];
            }
        }

        return Inertia::render('billing/plans', [
            'plans' => $plans,
            'pricesArePlaceholders' => true,
            'tenantId' => $tenant?->id,
            'scheduledChange' => $scheduledChange,
            'timezone' => $timezone,
        ]);
    }
}
