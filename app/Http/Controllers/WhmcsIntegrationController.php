<?php

namespace App\Http\Controllers;

use App\Http\Requests\RotateWhmcsIntegrationRequest;
use App\Http\Requests\StoreWhmcsIntegrationRequest;
use App\Models\WhmcsIntegration;
use App\Services\Tenancy\TenantRecordQuery;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Services\Whmcs\WhmcsIntegrationProvisioner;
use App\Services\Whmcs\WhmcsIntegrationSecretRotator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class WhmcsIntegrationController extends Controller
{
    public function index(): Response
    {
        $integrations = app(TenantRecordQuery::class)->integrations(Auth::user())
            ->withCount('diagnosticRuns')
            ->latest()
            ->paginate(20)
            ->through(fn (WhmcsIntegration $integration): array => [
                'id' => $integration->id,
                'name' => $integration->name,
                'status' => $integration->status,
                'addon_version' => $integration->addon_version,
                'last_seen_at' => $integration->last_seen_at?->toIso8601String(),
                'diagnostic_runs_count' => $integration->diagnostic_runs_count,
                'tenant_id' => $integration->tenant_id,
            ]);

        return Inertia::render('integrations/index', [
            'integrations' => $integrations,
        ]);
    }

    public function store(StoreWhmcsIntegrationRequest $request, WhmcsIntegrationProvisioner $provisioner): RedirectResponse
    {
        $user = $request->user();
        $tenantId = $request->resolvedTenantId();

        try {
            $result = $provisioner->create(
                $user,
                (string) $request->validated('name'),
                $tenantId,
                'user',
                (string) $user->getKey(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        } catch (AuthorizationException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        }

        return redirect()
            ->route('integrations.index')
            ->with('integration_credentials', [
                'integration_id' => $result['integration']->id,
                'secret' => $result['plainTextSecret'],
                'name' => $result['integration']->name,
                'action' => 'created',
            ]);
    }

    public function rotate(
        RotateWhmcsIntegrationRequest $request,
        WhmcsIntegration $integration,
        WhmcsIntegrationSecretRotator $rotator,
    ): RedirectResponse {
        $user = $request->user();

        $visible = app(TenantRecordQuery::class)->integrations($user)
            ->whereKey($integration->id)
            ->exists();

        if (! $visible || $integration->tenant_id === null) {
            abort(403);
        }

        try {
            app(TenantRoleAuthorization::class)->require($user, $integration->tenant_id, 'integrations.manage');
        } catch (AuthorizationException) {
            abort(403);
        }

        try {
            $result = $rotator->rotate(
                $integration->id,
                'user',
                (string) $user->getKey(),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['rotate' => $exception->getMessage()]);
        }

        return redirect()
            ->route('integrations.index')
            ->with('integration_credentials', [
                'integration_id' => $result['integration']->id,
                'secret' => $result['plainTextSecret'],
                'name' => $result['integration']->name,
                'action' => 'rotated',
            ]);
    }
}
