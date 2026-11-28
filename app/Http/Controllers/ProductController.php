<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __invoke(): Response
    {
        $packaging = Plan::query()
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['code', 'name', 'features', 'entitlements', 'description'])
            ->map(function (Plan $plan): array {
                $features = is_array($plan->features) ? $plan->features : [];
                $summary = $features !== []
                    ? implode(', ', array_slice(array_values(array_filter($features, 'is_string')), 0, 3))
                    : (string) ($plan->description ?? '');

                return [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'summary' => $summary,
                ];
            })
            ->values()
            ->all();

        if ($packaging === []) {
            $packaging = [
                [
                    'code' => 'starter',
                    'name' => 'Starter',
                    'summary' => '1 WHMCS install, guided playbooks, weekly digest',
                ],
                [
                    'code' => 'growth',
                    'name' => 'Growth',
                    'summary' => 'Multi-install ops, Safe Actions, alert channels',
                ],
                [
                    'code' => 'business',
                    'name' => 'Business',
                    'summary' => 'Auto-fix policies, monthly value reports, higher AI budget',
                ],
                [
                    'code' => 'enterprise',
                    'name' => 'Enterprise',
                    'summary' => 'Custom limits, dedicated support, partner onboarding help',
                ],
            ];
        }

        return Inertia::render('marketing/product', [
            'objections' => [
                [
                    'question' => 'WHMCS already shows this',
                    'answer' => 'WHMCS surfaces signals per install. We correlate, prioritize, and close findings across installs with playbooks, assignees, digests, and audit, as a product answer to "what should I fix first this week?"',
                ],
                [
                    'question' => 'AI will break us',
                    'answer' => 'Collect stays read-only. Recommendations are evidence-bound. Actions are allowlisted, human-approved, audited, and verified with before/after diagnostics. The model cannot invent free-form repairs.',
                ],
                [
                    'question' => 'Is this another script?',
                    'answer' => 'No. You get a signed addon, multi-tenant SaaS workflow, no customer/invoice/payment data in snapshots, and an ops SLA surface WHMCS alone does not productize.',
                ],
            ],
            'packaging' => $packaging,
            'marketplace' => [
                'positioning' => 'Ops intelligence for WHMCS, not a hosting panel replacement.',
                'one_liner' => 'WHMCS Ops Copilot: read-only diagnostics in, prioritized findings out, guided fixes and approved safe actions from one dashboard.',
            ],
        ]);
    }
}
