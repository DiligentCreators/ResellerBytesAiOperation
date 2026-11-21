<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named per-route rate limiters so unrelated actions do not share a throttle bucket.
 * Numeric limits match the previous inline throttle:N,1 middleware values.
 */
class RouteRateLimiterServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, int>
     */
    public const LIMITS = [
        'webhooks-kelviq' => 120,
        'onboarding-workspace' => 20,
        'onboarding-plan' => 20,
        'onboarding-whmcs' => 20,
        'billing-checkout-store' => 10,
        'billing-sandbox-approve' => 20,
        'billing-sandbox-decline' => 20,
        'platform-staff-store' => 10,
        'platform-staff-update' => 20,
        'platform-staff-destroy' => 10,
        'platform-mail-settings-update' => 10,
        'platform-mail-settings-test' => 3,
        'platform-ai-settings-update' => 10,
        'platform-ai-settings-generate-signing-keys' => 5,
        'platform-billing-gateway-replay' => 20,
        'platform-billing-seller-update' => 10,
        'platform-billing-plans-store' => 20,
        'platform-billing-plans-update' => 20,
        'tenants-settings-branding-update' => 20,
        'tenants-memberships-invite' => 10,
        'tenants-memberships-invitation-show' => 20,
        'tenants-memberships-accept' => 10,
        'tenants-memberships-role' => 20,
        'tenants-memberships-suspend' => 20,
        'tenants-memberships-revoke' => 20,
        'tenants-notifications-index' => 30,
        'tenants-notifications-read' => 30,
        'tenants-audit-events-export' => 3,
        'billing-settings-details' => 20,
        'billing-subscriptions-cancel' => 10,
        'billing-subscriptions-resume' => 10,
        'integrations-store' => 10,
        'integrations-rotate' => 5,
        'diagnostic-runs-reanalyze' => 5,
        'whmcs-addon-download' => 10,
        'api-whmcs-diagnostic-snapshots' => 30,
        'user-password-update' => 6,
    ];

    public function boot(): void
    {
        foreach (self::LIMITS as $name => $perMinute) {
            RateLimiter::for($name, function (Request $request) use ($name, $perMinute) {
                $actor = $request->user()?->getAuthIdentifier() ?? $request->ip();

                return Limit::perMinute($perMinute)->by($name.'|'.$actor);
            });
        }
    }
}
