<?php

namespace App\Http\Controllers;

use App\Http\Requests\RotateWhmcsIntegrationRequest;
use App\Http\Requests\StoreWhmcsIntegrationRequest;
use App\Http\Requests\UpdateWhmcsIntegrationHostConnectionRequest;
use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
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
                'host_connection_configured' => $integration->hasHostConnection(),
                'host_connection_summary' => $integration->hasHostConnection()
                    ? [
                        'protocol' => (string) ($integration->host_connection['protocol'] ?? 'ftp'),
                        'host' => (string) ($integration->host_connection['host'] ?? ''),
                        'port' => (int) ($integration->host_connection['port'] ?? 21),
                        'username' => (string) ($integration->host_connection['username'] ?? ''),
                        'remote_whmcs_root' => (string) ($integration->host_connection['remote_whmcs_root'] ?? ''),
                    ]
                    : null,
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

        // Fail closed with 404 so foreign/hidden integrations do not reveal existence.
        if (! $visible || $integration->tenant_id === null) {
            abort(404);
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

    public function updateHostConnection(
        UpdateWhmcsIntegrationHostConnectionRequest $request,
        WhmcsIntegration $integration,
        AuditEventWriter $audit,
    ): RedirectResponse {
        $user = $request->user();

        $visible = app(TenantRecordQuery::class)->integrations($user)
            ->whereKey($integration->id)
            ->exists();

        if (! $visible || $integration->tenant_id === null) {
            abort(404);
        }

        try {
            app(TenantRoleAuthorization::class)->require($user, $integration->tenant_id, 'integrations.manage');
        } catch (AuthorizationException) {
            abort(403);
        }

        if ($request->boolean('clear')) {
            $integration->forceFill(['host_connection' => null])->save();
            $event = 'integration.host_connection.cleared';
            $status = 'Host credentials removed.';
        } else {
            $validated = $request->validated();
            $root = str_replace('\\', '/', (string) $validated['remote_whmcs_root']);
            $root = rtrim($root, '/');
            if ($root === '' || str_contains($root, '..')) {
                return back()->withErrors(['remote_whmcs_root' => 'Enter an absolute WHMCS root path without .. segments.']);
            }

            $integration->forceFill([
                'host_connection' => [
                    'protocol' => $validated['protocol'],
                    'host' => $validated['host'],
                    'port' => (int) $validated['port'],
                    'username' => $validated['username'],
                    'password' => $validated['password'],
                    'remote_whmcs_root' => $root,
                ],
            ])->save();
            $event = 'integration.host_connection.updated';
            $status = 'Host credentials saved for allowlisted Safe Action fallbacks.';
        }

        $audit->append([
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'tenant_id' => $integration->tenant_id,
            'event_type' => $event,
            'subject_type' => 'whmcs_integration',
            'subject_id' => $integration->id,
            'metadata' => [
                'configured' => $integration->hasHostConnection(),
            ],
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        return back()->with('status', $status);
    }
}
