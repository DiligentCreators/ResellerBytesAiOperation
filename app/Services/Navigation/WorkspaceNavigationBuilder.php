<?php

namespace App\Services\Navigation;

use App\Models\User;
use App\Services\CentralStaffAuthorization;
use Illuminate\Http\Request;

class WorkspaceNavigationBuilder
{
    public function __construct(private readonly CentralStaffAuthorization $authorization) {}

    /**
     * Build shared navigation props for customer and platform sidebars.
     *
     * @return array{
     *     customer: list<array{title: string, href: string, children?: list<array{title: string, href: string, children?: list<array{title: string, href: string}>}>}>,
     *     platform: list<array{title: string, href: string, children?: list<array{title: string, href: string}>}>
     * }
     */
    public function forRequest(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $tenantId = $user?->tenantMemberships()
            ->where('status', 'active')
            ->orderBy('created_at')
            ->value('tenant_id');

        $hasWorkspace = is_string($tenantId) && $tenantId !== '';
        $isPlatformOperator = $this->authorization->isPlatformOperator($user);
        $showCustomer = $hasWorkspace || ! $isPlatformOperator;

        return [
            'customer' => $showCustomer ? $this->customerItems($tenantId) : [],
            'platform' => $isPlatformOperator ? $this->platformItems($user) : [],
        ];
    }

    /**
     * @return list<array{title: string, href: string, children?: list<array{title: string, href: string, children?: list<array{title: string, href: string}>}>}>
     */
    private function customerItems(?string $tenantId): array
    {
        $items = [
            [
                'title' => 'Home',
                'href' => '/dashboard',
            ],
            [
                'title' => 'Fix issues',
                'href' => '/fix-center',
                'children' => [
                    ['title' => 'Full issue list', 'href' => '/findings'],
                    ['title' => 'Approvals', 'href' => '/safe-actions'],
                ],
            ],
            [
                'title' => 'My WHMCS',
                'href' => '/integrations',
                'children' => [
                    ['title' => 'Connect WHMCS (step by step)', 'href' => '/whmcs-addon/setup'],
                    ['title' => 'Download the addon', 'href' => '/whmcs-addon/download'],
                    ['title' => 'Check history', 'href' => '/diagnostic-runs'],
                ],
            ],
            [
                'title' => 'Reports',
                'href' => '/reports',
                'children' => [
                    ['title' => 'Progress over time', 'href' => '/reports/trends'],
                    ['title' => 'Fix log', 'href' => '/reports#fix-log'],
                ],
            ],
        ];

        if ($tenantId !== null && $tenantId !== '') {
            $items[] = [
                'title' => 'Settings',
                'href' => "/tenants/{$tenantId}/settings",
                'children' => [
                    ['title' => 'Account', 'href' => "/tenants/{$tenantId}/settings"],
                    ['title' => 'Team', 'href' => "/tenants/{$tenantId}/memberships"],
                    ['title' => 'Alerts', 'href' => "/tenants/{$tenantId}/alerts"],
                    ['title' => 'Inbox', 'href' => "/tenants/{$tenantId}/notifications"],
                    ['title' => 'Plan and billing', 'href' => "/tenants/{$tenantId}/billing"],
                    [
                        'title' => 'Advanced',
                        'href' => "/tenants/{$tenantId}/auto-fix",
                        'children' => [
                            ['title' => 'Auto-fix', 'href' => "/tenants/{$tenantId}/auto-fix"],
                            ['title' => 'Backup connection', 'href' => '/integrations#host-connection'],
                        ],
                    ],
                ],
            ];
        }

        return $items;
    }

    /**
     * @return list<array{title: string, href: string, children?: list<array{title: string, href: string}>}>
     */
    private function platformItems(?User $user): array
    {
        $permissions = $this->authorization->permissions($user);
        $items = [];

        $money = [];
        if (in_array('billing', $permissions, true)) {
            $money[] = ['title' => 'Overview / MRR', 'href' => '/platform/billing'];
            $money[] = ['title' => 'Plan catalog', 'href' => '/platform/billing/plans'];
            $money[] = ['title' => 'Gateway', 'href' => '/platform/billing/gateway'];
        }

        if (in_array('billing.settings.manage', $permissions, true)) {
            $money[] = ['title' => 'Billing settings', 'href' => '/platform/billing/settings'];
        }

        if ($money !== []) {
            $items[] = [
                'title' => 'Admin > Money',
                'href' => $money[0]['href'],
                'children' => $money,
            ];
        }

        $system = [];
        if (in_array('settings', $permissions, true)) {
            $system[] = ['title' => 'Platform settings', 'href' => '/platform/settings'];
            $system[] = ['title' => 'Mail', 'href' => '/platform/mail-settings'];
            $system[] = ['title' => 'AI and signing', 'href' => '/platform/ai-settings'];
        }

        if ($system !== []) {
            $items[] = [
                'title' => 'Admin > System',
                'href' => $system[0]['href'],
                'children' => $system,
            ];
        }

        if ($this->authorization->isSuperAdmin($user)) {
            $items[] = [
                'title' => 'Admin > People',
                'href' => '/platform/staff',
                'children' => [
                    ['title' => 'Staff', 'href' => '/platform/staff'],
                ],
            ];
        }

        return $items;
    }
}
