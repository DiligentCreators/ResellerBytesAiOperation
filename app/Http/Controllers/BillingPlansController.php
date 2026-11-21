<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Billing\TrialPolicy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Authenticated plan picker used by onboarding and billing CTAs.
 */
class BillingPlansController extends Controller
{
    public function __invoke(Request $request, TrialPolicy $trialPolicy): Response
    {
        $tenant = $request->user()?->tenants()->first();

        $plans = Plan::query()->publicCatalog()->get()->map(function (Plan $plan) use ($trialPolicy, $tenant): array {
            $trialDays = $tenant instanceof Tenant
                ? $trialPolicy->trialDaysFor($tenant, $plan)
                : $trialPolicy->marketingTrialDays($plan);

            return [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_monthly_minor' => $plan->price_monthly_minor,
                'price_yearly_minor' => $plan->price_yearly_minor,
                'currency' => $plan->currency,
                'trial_days' => $trialDays,
                'trial_enabled' => $trialDays >= 1,
                'monthly_active' => (bool) $plan->monthly_active,
                'yearly_active' => (bool) $plan->yearly_active,
                'is_contact_sales' => $plan->is_contact_sales,
                'features' => is_array($plan->features) ? $plan->features : null,
                'entitlements' => $plan->entitlements,
            ];
        });

        $scheduledChange = null;
        $currentTrial = null;
        $timezone = 'UTC';

        if ($tenant !== null) {
            $timezone = $tenant->timezone ?: 'UTC';
            $subscription = TenantSubscription::query()
                ->with(['plan:id,code,name', 'scheduledPlan:id,code,name'])
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', ['trialing', 'active', 'past_due', 'suspended'])
                ->latest('id')
                ->first();

            if ($subscription !== null && $subscription->isConvertibleTrial()) {
                $currentTrial = [
                    'plan_name' => $subscription->plan->name ?? $subscription->plan_code,
                    'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                    'days_remaining' => $subscription->trialDaysRemaining() ?? 0,
                ];
            }

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
            'pricesArePlaceholders' => Plan::catalogHasUnconfirmedPrices(),
            'tenantId' => $tenant?->id,
            'scheduledChange' => $scheduledChange,
            'currentTrial' => $currentTrial,
            'timezone' => $timezone,
        ]);
    }
}
