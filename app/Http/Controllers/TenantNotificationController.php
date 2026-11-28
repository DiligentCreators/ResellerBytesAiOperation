<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantNotification;
use App\Models\User;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class TenantNotificationController extends Controller
{
    public function index(Request $request, Tenant $tenant, TenantRoleAuthorization $authorization): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.read');

        /** @var LengthAwarePaginator<int, array<string, mixed>> $notifications */
        $notifications = TenantNotification::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->paginate(50)
            ->through(fn (TenantNotification $notification): array => $this->presentNotification($notification));

        $payload = [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'timezone' => $tenant->timezone ?: 'UTC',
            ],
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

    /**
     * @return array{
     *     id: int,
     *     event_type: string,
     *     type: string,
     *     title: string,
     *     message: string|null,
     *     payload: array<string, mixed>,
     *     read_at: string|null,
     *     created_at: string|null
     * }
     */
    private function presentNotification(TenantNotification $notification): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($notification->payload) ? $notification->payload : [];
        $eventType = (string) $notification->event_type;

        return [
            'id' => (int) $notification->id,
            'event_type' => $eventType,
            'type' => $eventType,
            'title' => $this->titleFor($eventType, $payload),
            'message' => $this->messageFor($payload),
            'payload' => $payload,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function titleFor(string $eventType, array $payload): string
    {
        if (isset($payload['title']) && is_string($payload['title']) && $payload['title'] !== '') {
            return $payload['title'];
        }

        return $eventType !== '' ? str_replace('.', ' · ', $eventType) : 'Notification';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function messageFor(array $payload): ?string
    {
        if (isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
            return $payload['message'];
        }

        if (isset($payload['body']) && is_string($payload['body']) && $payload['body'] !== '') {
            return $payload['body'];
        }

        $parts = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, ['title', 'message', 'body'], true)) {
                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $parts[] = $key.': '.(string) $value;

            if (count($parts) >= 4) {
                break;
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
