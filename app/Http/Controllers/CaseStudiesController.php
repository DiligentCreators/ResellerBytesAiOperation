<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class CaseStudiesController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('marketing/case-studies', [
            'studies' => [
                [
                    'slug' => 'cron-stall-multi-brand',
                    'title' => 'Cron stall caught across three brands',
                    'segment' => 'Multi-brand reseller',
                    'before' => 'Each WHMCS install looked “fine” in isolation. Invoice automation lagged for 36 hours before anyone correlated cron health.',
                    'after' => 'One dashboard ranked a critical cron finding first, assigned an operator, and verified clearance on the next signed snapshot.',
                    'metrics' => [
                        'Time to first resolved finding: under 1 business day',
                        'WHMCS writes by AI Ops: zero',
                        'Severity-1 incidents from Safe Actions: zero',
                    ],
                ],
                [
                    'slug' => 'permissions-writable-config',
                    'title' => 'Writable configuration.php flagged before change weekend',
                    'segment' => 'Hosting agency',
                    'before' => 'A permissions drift sat unnoticed until a failed deploy. No shared closure tracking across client installs.',
                    'after' => 'Permissions audit Safe Action produced a read-only report; the team fixed paths in WHMCS and re-checked until Last scan clean.',
                    'metrics' => [
                        'Playbook checklist copied into the change ticket',
                        'Accepted risk documented for one non-prod install',
                        'Weekly digest kept owners aligned without Slack archaeology',
                    ],
                ],
                [
                    'slug' => 'pilot-to-paid',
                    'title' => '14-day pilot → paid Growth',
                    'segment' => 'Solo operator → small team',
                    'before' => 'ChatGPT paste-ups of WHMCS screenshots with no evidence binding or audit.',
                    'after' => 'Pilot sold itself as “not ChatGPT + WHMCS”: signed collect, evidence-bound findings, guided Fix Center, human-approved Safe Actions.',
                    'metrics' => [
                        'One successful diagnostic cycle closed the pilot',
                        'Upgraded for multi-install + assignees',
                        'Safe Actions used for health ping + deep links only in week one',
                    ],
                ],
            ],
        ]);
    }
}
