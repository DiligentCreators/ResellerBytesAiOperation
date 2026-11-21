<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('marketing/product', [
            'objections' => [
                [
                    'question' => 'WHMCS already shows this',
                    'answer' => 'WHMCS surfaces signals per install. We correlate, prioritize, and close findings across installs with playbooks, assignees, digests, and audit — as a product answer to “what should I fix first this week?”',
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
            'packaging' => [
                [
                    'code' => 'starter',
                    'name' => 'Starter',
                    'summary' => '1 install, guided playbooks, weekly digest',
                ],
                [
                    'code' => 'growth',
                    'name' => 'Growth',
                    'summary' => 'Multi-install, assignees, re-check diffs, Safe Actions',
                ],
                [
                    'code' => 'agency',
                    'name' => 'Agency',
                    'summary' => 'Staff access, higher AI budget, Safe Actions pack',
                ],
            ],
            'marketplace' => [
                'positioning' => 'Ops intelligence for WHMCS — not a hosting panel replacement.',
                'one_liner' => 'WHMCS Ops Copilot — read-only diagnostics in → prioritized findings out → guided fixes + approved safe actions from one dashboard.',
                'listing_checklist' => [
                    'Marketplace / partner listing copy ready (ops intelligence wedge)',
                    'Hero demo: multi-install hosting dashboard',
                    'Sanitized before/after case studies published',
                    'Objection handling FAQ live on product + pricing',
                ],
            ],
        ]);
    }
}
