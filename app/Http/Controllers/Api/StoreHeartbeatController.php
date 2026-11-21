<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreHeartbeatRequest;
use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class StoreHeartbeatController extends Controller
{
    public function __invoke(StoreHeartbeatRequest $request): JsonResponse
    {
        /** @var WhmcsIntegration $integration */
        $integration = $request->attributes->get('whmcs_integration');
        $validated = $request->validated();

        $sentAt = Carbon::parse($validated['sent_at'])->utc();
        $attributes = [
            'addon_version' => $validated['addon_version'],
            'last_heartbeat_at' => $sentAt,
            'cron_php_version' => $validated['cli_php_version'],
            'last_seen_at' => now(),
        ];

        if ($validated['cron_kind'] === 'daily') {
            $attributes['last_cron_at'] = $sentAt;
        }

        $integration->forceFill($attributes)->save();

        app(AuditEventWriter::class)->append([
            'actor_type' => 'integration',
            'actor_id' => $integration->id,
            'tenant_id' => $integration->tenant_id,
            'event_type' => 'whmcs_heartbeat.accepted',
            'subject_type' => 'whmcs_integration',
            'subject_id' => $integration->id,
            'metadata' => [
                'cron_kind' => $validated['cron_kind'],
                'whmcs_version' => $validated['whmcs_version'],
            ],
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'accepted' => true,
            ],
        ], 202);
    }
}
