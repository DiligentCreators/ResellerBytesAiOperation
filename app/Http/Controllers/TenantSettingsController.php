<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantBrandingSettings;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantSettingsController extends Controller
{
    public function show(Request $request, Tenant $tenant, TenantRoleAuthorization $authorization): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.read');

        return response()->json([
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'branding' => ((array) $tenant->settings)['branding'] ?? [],
        ]);
    }

    public function update(Request $request, Tenant $tenant, TenantBrandingSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
            'support_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'primary_color' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
        ]);
        if ($data === []) {
            return response()->json(['message' => 'At least one branding setting must be supplied.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $updated = $settings->update($user, $tenant->id, $data, $request->ip());

        return response()->json([
            'tenant' => ['id' => $updated->id, 'name' => $updated->name],
            'branding' => ((array) $updated->settings)['branding'] ?? [],
        ]);
    }
}
