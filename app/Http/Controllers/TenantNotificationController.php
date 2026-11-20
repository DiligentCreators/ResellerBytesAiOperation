<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantNotification;
use App\Models\User;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantNotificationController extends Controller
{
    public function index(Request $request, Tenant $tenant, TenantRoleAuthorization $authorization): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.read');

        $notifications = TenantNotification::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->paginate(50);

        $payload = [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'notifications' => $notifications,
        ];

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return Inertia::render('tenants/notifications', $payload);
    }

    public function markRead(Request $request, Tenant $tenant, int $notification, TenantRoleAuthorization $authorization): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.read');

        TenantNotification::query()
            ->whereKey($notification)
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if (! $request->header('X-Inertia')) {
            return response()->json(['updated' => true]);
        }

        return back();
    }
}
