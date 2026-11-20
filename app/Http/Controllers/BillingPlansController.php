<?php

namespace App\Http\Controllers;

use App\Models\Plan;
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

        return Inertia::render('billing/plans', [
            'plans' => $plans,
            'pricesArePlaceholders' => true,
            'tenantId' => $request->user()?->tenants()->first()?->id,
        ]);
    }
}
