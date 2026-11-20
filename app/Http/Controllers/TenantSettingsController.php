<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantBrandingSettings;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantSettingsController extends Controller
{
    public function show(Request $request, Tenant $tenant, TenantRoleAuthorization $authorization): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $authorization->require($user, $tenant->id, 'tenant.read');
        $canManage = $authorization->allows($membership, 'tenant.settings.manage');

        $payload = [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'branding' => ((array) $tenant->settings)['branding'] ?? [],
            'canManage' => $canManage,
            'status' => $request->session()->get('status'),
        ];

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return Inertia::render('tenants/settings', $payload);
    }

    public function update(Request $request, Tenant $tenant, TenantBrandingSettings $settings): RedirectResponse|JsonResponse
    {
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

        /** @var User $user */
        $user = $request->user();
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
}
