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
     *     customer: list<array{title: string, href: string, children?: list<array{title: string, href: string}>}>,
     *     platform: list<array{title: string, href: string}>
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
     * @return list<array{title: string, href: string, children?: list<array{title: string, href: string}>}>
     */
    private function customerItems(?string $tenantId): array
    {
        $items = [
            [
                'title' => 'Home',
                'href' => '/dashboard',
            ],
            [
                'title' => 'Fix Center',
                'href' => '/fix-center',
            ],
            [
                'title' => 'Reports',
                'href' => '/reports',
            ],
            [
                'title' => 'Installations',
                'href' => '/integrations',
                'children' => [
                    ['title' => 'Addon setup', 'href' => '/whmcs-addon/setup'],
                    ['title' => 'Download', 'href' => '/whmcs-addon/download'],
                    ['title' => 'Host connection', 'href' => '/integrations#host-connection'],
                    ['title' => 'History', 'href' => '/diagnostic-runs'],
                ],
            ],
        ];

        if ($tenantId !== null && $tenantId !== '') {
            $items[] = [
                'title' => 'Settings',
                'href' => "/tenants/{$tenantId}/settings",
                'children' => [
                    ['title' => 'Workspace', 'href' => "/tenants/{$tenantId}/settings"],
                    ['title' => 'Members', 'href' => "/tenants/{$tenantId}/memberships"],
                    ['title' => 'Alerts', 'href' => "/tenants/{$tenantId}/alerts"],
                    ['title' => 'Auto-fix', 'href' => "/tenants/{$tenantId}/auto-fix"],
                    ['title' => 'Billing and invoices', 'href' => "/tenants/{$tenantId}/billing"],
                    ['title' => 'Notifications', 'href' => "/tenants/{$tenantId}/notifications"],
                ],
            ];
        }

        return $items;
    }

    /**
     * @return list<array{title: string, href: string}>
     */
    private function platformItems(?User $user): array
    {
        $permissions = $this->authorization->permissions($user);
        $items = [];

        if (in_array('billing', $permissions, true)) {
            $items[] = ['title' => 'Overview / MRR', 'href' => '/platform/billing'];
            $items[] = ['title' => 'Plan catalog', 'href' => '/platform/billing/plans'];
            $items[] = ['title' => 'Gateway', 'href' => '/platform/billing/gateway'];
        }

        if (in_array('billing.settings.manage', $permissions, true)) {
            $items[] = ['title' => 'Billing settings', 'href' => '/platform/billing/settings'];
        }

        if (in_array('settings', $permissions, true)) {
            $items[] = ['title' => 'Platform settings', 'href' => '/platform/settings'];
            $items[] = ['title' => 'Mail', 'href' => '/platform/mail-settings'];
            $items[] = ['title' => 'AI and signing', 'href' => '/platform/ai-settings'];
        }

        if ($this->authorization->isSuperAdmin($user)) {
            $items[] = ['title' => 'Staff', 'href' => '/platform/staff'];
        }

        return $items;
    }
}
