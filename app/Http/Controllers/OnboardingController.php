<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use App\Services\Tenancy\OnboardingProgress;
use App\Services\Tenancy\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request, TenantAccess $access, OnboardingProgress $onboardingProgress): Response|RedirectResponse
    {
        $tenant = $this->ownedTenant($request, $access);
        $progress = $onboardingProgress->forTenant($tenant->fresh());

        if (! $progress['needed']) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('onboarding/index', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'country_code' => $tenant->country_code,
                'timezone' => $tenant->timezone ?: 'UTC',
                'require_two_factor' => (bool) $tenant->require_two_factor,
            ],
            'steps' => $progress['steps'],
            'currentStep' => $progress['current_step'],
            'timezones' => timezone_identifiers_list(),
            'plansUrl' => route('billing.plans'),
            'integrationsUrl' => route('integrations.index'),
            'addonSetupUrl' => route('whmcs-addon.setup'),
        ]);
    }

    public function updateWorkspace(Request $request, TenantAccess $access, AuditEventWriter $audit, OnboardingProgress $onboardingProgress): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $this->ownedTenant($request, $access);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'require_two_factor' => ['sometimes', 'boolean'],
        ]);

        $settings = (array) $tenant->settings;
        $settings['branding'] = array_merge((array) ($settings['branding'] ?? []), [
            'display_name' => $data['name'],
        ]);
        $settings['onboarding'] = array_merge((array) ($settings['onboarding'] ?? []), [
            'workspace_completed_at' => now()->toIso8601String(),
        ]);

        $requireTwoFactor = $request->boolean('require_two_factor');

        $tenant->forceFill([
            'name' => $data['name'],
            'timezone' => $data['timezone'],
            'require_two_factor' => $requireTwoFactor,
            'settings' => $settings,
        ])->save();

        $audit->append([
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'tenant_id' => (string) $tenant->id,
            'event_type' => 'tenant.onboarding.workspace_completed',
            'subject_type' => Tenant::class,
            'subject_id' => (string) $tenant->id,
            'metadata' => [
                'timezone' => $data['timezone'],
                'require_two_factor' => $requireTwoFactor,
            ],
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        $progress = $onboardingProgress->forTenant($tenant->fresh());

        if (! $progress['needed']) {
            return redirect()->route('dashboard')->with('status', 'Workspace onboarding is complete.');
        }

        return redirect()->route('onboarding.show');
    }

    public function completePlan(Request $request, TenantAccess $access, AuditEventWriter $audit, OnboardingProgress $onboardingProgress): RedirectResponse
    {
        return $this->completeStep($request, $access, $audit, $onboardingProgress, 'plan', 'tenant.onboarding.plan_completed');
    }

    public function completeWhmcs(Request $request, TenantAccess $access, AuditEventWriter $audit, OnboardingProgress $onboardingProgress): RedirectResponse
    {
        return $this->completeStep($request, $access, $audit, $onboardingProgress, 'whmcs', 'tenant.onboarding.whmcs_completed');
    }

    private function completeStep(
        Request $request,
        TenantAccess $access,
        AuditEventWriter $audit,
        OnboardingProgress $onboardingProgress,
        string $step,
        string $eventType,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $tenant = $this->ownedTenant($request, $access);

        $settings = (array) $tenant->settings;
        $onboarding = (array) ($settings['onboarding'] ?? []);
        $onboarding[$step.'_completed_at'] = now()->toIso8601String();
        $settings['onboarding'] = $onboarding;

        $tenant->forceFill(['settings' => $settings])->save();

        $progress = $onboardingProgress->forTenant($tenant->fresh());

        $audit->append([
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'tenant_id' => (string) $tenant->id,
            'event_type' => $eventType,
            'subject_type' => Tenant::class,
            'subject_id' => (string) $tenant->id,
            'metadata' => [
                'onboarding_completed' => ! $progress['needed'],
            ],
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        if (! $progress['needed']) {
            return redirect()->route('dashboard')->with('status', 'Workspace onboarding is complete.');
        }

        return redirect()->route('onboarding.show');
    }

    private function ownedTenant(Request $request, TenantAccess $access): Tenant
    {
        /** @var User $user */
        $user = $request->user();

        $membership = $user->tenantMemberships()
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->where('role', TenantMembership::ROLE_OWNER)
            ->whereHas('tenant', fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->with('tenant')
            ->orderBy('created_at')
            ->first();

        if ($membership === null) {
            abort(403);
        }

        $access->requireMembership($user, (string) $membership->tenant_id, [TenantMembership::ROLE_OWNER]);

        return $membership->tenant;
    }
}
