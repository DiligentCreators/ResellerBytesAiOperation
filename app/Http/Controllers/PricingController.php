<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\TrialPolicy;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    public function __invoke(TrialPolicy $trialPolicy): Response
    {
        $pricesArePlaceholders = Plan::catalogHasUnconfirmedPrices();

        $plans = Plan::query()->publicCatalog()->get()->map(fn (Plan $plan): array => [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_monthly_minor' => $plan->price_monthly_minor,
            'price_yearly_minor' => $plan->price_yearly_minor,
            'currency' => $plan->currency,
            'trial_days' => $trialPolicy->marketingTrialDays($plan),
            'trial_enabled' => $trialPolicy->marketingTrialDays($plan) >= 1,
            'monthly_active' => (bool) $plan->monthly_active,
            'yearly_active' => (bool) $plan->yearly_active,
            'is_contact_sales' => $plan->is_contact_sales,
            'features' => is_array($plan->features) ? $plan->features : null,
            'entitlements' => $plan->entitlements,
        ]);

        return Inertia::render('marketing/pricing', [
            'plans' => $plans,
            'pricesArePlaceholders' => $pricesArePlaceholders,
            'faq' => [
                [
                    'question' => 'Can I change plans later?',
                    'answer' => 'Yes. Owners and billing admins can upgrade, downgrade, or cancel from Billing settings.',
                ],
                [
                    'question' => 'Do you support yearly billing?',
                    'answer' => 'Yes. Toggle monthly or yearly on this page before checkout. Some plans may offer only one interval.',
                ],
                [
                    'question' => 'Are these launch prices final?',
                    'answer' => $pricesArePlaceholders
                        ? 'Not yet. Some public plans still show unconfirmed placeholder amounts until platform admins save confirmed prices.'
                        : 'Yes. Platform admins have confirmed the prices shown on this page.',
                ],
                [
                    'question' => 'Which payment methods are accepted?',
                    'answer' => 'Checkout uses the configured payment gateway. Local development uses a labelled sandbox that does not charge a real card.',
                ],
            ],
        ]);
    }
}
