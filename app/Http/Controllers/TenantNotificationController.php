<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantNotification;
use App\Models\User;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TenantNotificationController extends Controller
{
    public function index(Request $request, Tenant $tenant, TenantRoleAuthorization $authorization): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.read');

        $notifications = TenantNotification::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, Tenant $tenant, int $notification, TenantRoleAuthorization $authorization): \Illuminate\Http\RedirectResponse
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

        return response()->json(['updated' => true]);
    }
}
