<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * PLACEHOLDER SEED PRICES — edit in platform admin before launch.
         * These USD amounts are illustrative only and are not commercial commitments.
         */
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'For solo operators validating WHMCS diagnostics and AI triage.',
                'price_monthly_minor' => 4900, // PLACEHOLDER — edit before launch
                'price_yearly_minor' => 49000, // PLACEHOLDER — edit before launch
                'trial_days' => 14,
                'sort_order' => 10,
                'is_contact_sales' => false,
                'entitlements' => [
                    'diagnostics.analysis' => true,
                    'diagnostics.reanalyze' => true,
                    'limits' => [
                        'members' => 3,
                        'integrations' => 1,
                        'diagnostics_per_period' => 100,
                        'retention_days' => 30,
                        'ai_analysis_runs' => 50,
                    ],
                    'support_level' => 'email',
                ],
            ],
            [
                'code' => 'growth',
                'name' => 'Growth',
                'description' => 'For growing reseller teams with higher diagnostic volume.',
                'price_monthly_minor' => 14900, // PLACEHOLDER — edit before launch
                'price_yearly_minor' => 149000, // PLACEHOLDER — edit before launch
                'trial_days' => 14,
                'sort_order' => 20,
                'is_contact_sales' => false,
                'entitlements' => [
                    'diagnostics.analysis' => true,
                    'diagnostics.reanalyze' => true,
                    'limits' => [
                        'members' => 10,
                        'integrations' => 3,
                        'diagnostics_per_period' => 500,
                        'retention_days' => 90,
                        'ai_analysis_runs' => 250,
                    ],
                    'support_level' => 'priority_email',
                ],
            ],
            [
                'code' => 'business',
                'name' => 'Business',
                'description' => 'For multi-brand operations that need broader seats and retention.',
                'price_monthly_minor' => 39900, // PLACEHOLDER — edit before launch
                'price_yearly_minor' => 399000, // PLACEHOLDER — edit before launch
                'trial_days' => 14,
                'sort_order' => 30,
                'is_contact_sales' => false,
                'entitlements' => [
                    'diagnostics.analysis' => true,
                    'diagnostics.reanalyze' => true,
                    'limits' => [
                        'members' => 25,
                        'integrations' => 10,
                        'diagnostics_per_period' => 2000,
                        'retention_days' => 180,
                        'ai_analysis_runs' => 1000,
                    ],
                    'support_level' => 'priority',
                ],
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Custom limits, retention, and support. Contact sales to activate.',
                'price_monthly_minor' => 0, // PLACEHOLDER — contact sales
                'price_yearly_minor' => 0, // PLACEHOLDER — contact sales
                'trial_days' => 0,
                'sort_order' => 40,
                'is_contact_sales' => true,
                'entitlements' => [
                    'diagnostics.analysis' => true,
                    'diagnostics.reanalyze' => true,
                    'limits' => [
                        'members' => 100,
                        'integrations' => 50,
                        'diagnostics_per_period' => 10000,
                        'retention_days' => 365,
                        'ai_analysis_runs' => 5000,
                    ],
                    'support_level' => 'dedicated',
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['code' => $plan['code']],
                [
                    ...$plan,
                    'currency' => 'USD',
                    'is_active' => true,
                    'is_public' => true,
                    'kelviq_plan_identifier_monthly' => null,
                    'kelviq_plan_identifier_yearly' => null,
                ],
            );
        }
    }
}
