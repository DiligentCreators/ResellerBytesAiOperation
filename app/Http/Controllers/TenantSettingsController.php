<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenants\UpdateTenantHourlyRateRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use App\Services\Health\TimeSavedCalculator;
use App\Services\PlatformSettings;
use App\Services\Tenancy\TenantBrandingSettings;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\TwoFactorPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TenantSettingsController extends Controller
{
    public function show(
        Request $request,
        Tenant $tenant,
        TenantRoleAuthorization $authorization,
        TimeSavedCalculator $timeSaved,
        PlatformSettings $platformSettings,
    ): Response|JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $membership = $authorization->require($user, $tenant->id, 'tenant.read');
        $canManage = $authorization->allows($membership, 'tenant.settings.manage');

        $platformDefault = (int) $platformSettings->get('reports.default_hourly_rate_usd');

        $payload = [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'hourly_rate_usd' => $tenant->hourly_rate_usd,
                'require_two_factor' => (bool) $tenant->require_two_factor,
            ],
            'branding' => ((array) $tenant->settings)['branding'] ?? [],
            'hourly_rate' => [
                'value' => $tenant->hourly_rate_usd,
                'effective' => $timeSaved->hourlyRateUsd($tenant),
                'platform_default' => $platformDefault,
            ],
            'security' => [
                'require_two_factor' => (bool) $tenant->require_two_factor,
                'platform_two_factor_required' => app(TwoFactorPolicy::class)->isPlatformRequirementEnabled(),
            ],
            'canManage' => $canManage,
            'status' => $request->session()->get('status'),
        ];

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return Inertia::render('tenants/settings', $payload);
    }

    public function update(Request $request, Tenant $tenant, TenantBrandingSettings $settings, TenantRoleAuthorization $authorization): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        // Authorize before validation so outsiders cannot probe tenant existence via 422s.
        $authorization->require($user, $tenant->id, 'tenant.settings.manage');

        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
            'support_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'primary_color' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
        ]);
        if ($data === []) {
            if (! $request->header('X-Inertia')) {
                return response()->json(['message' => 'At least one branding setting must be supplied.'], 422);
            }

            return back()->withErrors(['display_name' => 'At least one branding setting must be supplied.']);
        }

        $updated = $settings->update($user, $tenant->id, $data, $request->ip());

        $payload = [
            'tenant' => ['id' => $updated->id, 'name' => $updated->name],
            'branding' => ((array) $updated->settings)['branding'] ?? [],
        ];

        if (! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return back()->with('status', 'Workspace branding updated.');
    }

    public function updateHourlyRate(
        UpdateTenantHourlyRateRequest $request,
        Tenant $tenant,
    ): RedirectResponse|JsonResponse {
        $rate = $request->validated('hourly_rate_usd');

        DB::transaction(function () use ($tenant, $rate): void {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['hourly_rate_usd' => $rate])->save();
        });

        $tenant->refresh();

        if (! $request->header('X-Inertia')) {
            return response()->json([
                'tenant' => [
                    'id' => $tenant->id,
                    'hourly_rate_usd' => $tenant->hourly_rate_usd,
                ],
            ]);
        }

        return back()->with('status', 'Hourly rate updated.');
    }

    public function updateSecurity(
        Request $request,
        Tenant $tenant,
        TenantRoleAuthorization $authorization,
        AuditEventWriter $audit,
    ): RedirectResponse|JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.settings.manage');

        $request->validate([
            'require_two_factor' => ['sometimes', 'boolean'],
        ]);

        $requireTwoFactor = $request->boolean('require_two_factor');

        DB::transaction(function () use ($tenant, $requireTwoFactor, $user, $audit, $request): void {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $previous = (bool) $locked->require_two_factor;
            $locked->forceFill(['require_two_factor' => $requireTwoFactor])->save();

            $audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $user->getKey(),
                'tenant_id' => $tenant->id,
                'event_type' => 'tenant.security.updated',
                'subject_type' => 'tenant',
                'subject_id' => $tenant->id,
                'metadata' => [
                    'require_two_factor' => $requireTwoFactor,
                    'previous_require_two_factor' => $previous,
                ],
                'ip_address' => $request->ip(),
                'occurred_at' => now(),
            ]);
        });

        $tenant->refresh();

        if (! $request->header('X-Inertia')) {
            return response()->json([
                'tenant' => [
                    'id' => $tenant->id,
                    'require_two_factor' => (bool) $tenant->require_two_factor,
                ],
            ]);
        }

        return back()->with('status', 'Workspace security settings updated.');
    }
}
