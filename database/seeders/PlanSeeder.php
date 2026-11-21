<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Catalog tiers only — no commercial prices. Platform admins set amounts
         * at /platform/billing/plans before plans become purchasable.
         *
         * Existing plans are never overwritten (prices, active flags, names, etc.).
         * Missing entitlement keys may be merged in; admin values always win.
         */
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'For solo operators validating WHMCS diagnostics and AI triage.',
                'price_monthly_minor' => null,
                'price_yearly_minor' => null,
                'trial_days' => 14,
                'sort_order' => 10,
                'is_active' => false,
                'is_contact_sales' => false,
                'features' => [
                    '1 WHMCS install',
                    'Guided fix playbooks',
                    'Weekly email digest',
                    'Last-scan-clean health state',
                ],
                'entitlements' => [
                    'diagnostics.analysis' => true,
                    'diagnostics.reanalyze' => true,
                    'safe_actions.enabled' => false,
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
                'description' => 'Multi-install ops with assignees, re-check diffs, and Safe Actions.',
                'price_monthly_minor' => null,
                'price_yearly_minor' => null,
                'trial_days' => 14,
                'sort_order' => 20,
                'is_active' => false,
                'is_contact_sales' => false,
                'features' => [
                    'Up to 3 WHMCS installs',
                    'Assignees + re-check diffs',
                    'Safe Actions allowlist pack',
                    'Weekly email digest',
                ],
                'entitlements' => [
                    'diagnostics.analysis' => true,
                    'diagnostics.reanalyze' => true,
                    'safe_actions.enabled' => true,
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
                'name' => 'Agency',
                'description' => 'Agency packing: staff seats, higher AI budget, and Safe Actions for client estates.',
                'price_monthly_minor' => null,
                'price_yearly_minor' => null,
                'trial_days' => 14,
                'sort_order' => 30,
                'is_active' => false,
                'is_contact_sales' => false,
                'features' => [
                    'Up to 10 WHMCS installs',
                    'Staff access + higher AI budget',
                    'Safe Actions pack + audit trail',
                    'Priority support',
                ],
                'entitlements' => [
                    'diagnostics.analysis' => true,
                    'diagnostics.reanalyze' => true,
                    'safe_actions.enabled' => true,
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
                'price_monthly_minor' => null,
                'price_yearly_minor' => null,
                'trial_days' => 0,
                'sort_order' => 40,
                'is_active' => true,
                'is_contact_sales' => true,
                'features' => [
                    'Custom install limits',
                    'Dedicated support',
                    'Safe Actions + custom allowlist review',
                    'Marketplace / partner onboarding help',
                ],
                'entitlements' => [
                    'diagnostics.analysis' => true,
                    'diagnostics.reanalyze' => true,
                    'safe_actions.enabled' => true,
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
            $existing = Plan::query()->where('code', $plan['code'])->first();

            if ($existing === null) {
                Plan::query()->create([
                    ...$plan,
                    'currency' => 'USD',
                    'is_public' => true,
                    'prices_confirmed_at' => null,
                    'kelviq_plan_identifier_monthly' => null,
                    'kelviq_plan_identifier_yearly' => null,
                ]);

                continue;
            }

            $this->mergeMissingEntitlements($existing, $plan['entitlements']);
        }
    }

    /**
     * Fill entitlement keys present in the seed catalog but missing on the plan.
     * Existing (admin-edited) values always win — never overwrite.
     *
     * @param  array<string, mixed>  $seedEntitlements
     */
    private function mergeMissingEntitlements(Plan $plan, array $seedEntitlements): void
    {
        $current = is_array($plan->entitlements) ? $plan->entitlements : [];
        $merged = array_replace_recursive($seedEntitlements, $current);

        if ($merged === $current) {
            return;
        }

        $plan->forceFill(['entitlements' => $merged])->save();
    }
}
