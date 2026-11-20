<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    public function __invoke(): Response
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

        return Inertia::render('marketing/pricing', [
            'plans' => $plans,
            'pricesArePlaceholders' => true,
            'faq' => [
                [
                    'question' => 'Can I change plans later?',
                    'answer' => 'Yes. Owners and billing admins can upgrade, downgrade, or cancel from Billing settings.',
                ],
                [
                    'question' => 'Do you support yearly billing?',
                    'answer' => 'Yes. Toggle monthly or yearly on this page before checkout.',
                ],
                [
                    'question' => 'Are these launch prices final?',
                    'answer' => 'No. Seeded prices are placeholders and will be edited by platform admins before commercial launch.',
                ],
                [
                    'question' => 'Which payment methods are accepted?',
                    'answer' => 'Checkout uses the configured payment gateway. Local development uses a labelled sandbox that does not charge a real card.',
                ],
            ],
        ]);
    }
}
