<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreHeartbeatRequest;
use App\Models\SafeActionExecution;
use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use App\Services\Monitoring\AutoCollectDueEvaluator;
use App\Services\Monitoring\HeartbeatStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class StoreHeartbeatController extends Controller
{
    public function __invoke(
        StoreHeartbeatRequest $request,
        AutoCollectDueEvaluator $autoCollect,
        HeartbeatStateService $heartbeatStates,
    ): JsonResponse {
        /** @var WhmcsIntegration $integration */
        $integration = $request->attributes->get('whmcs_integration');
        $validated = $request->validated();

        $sentAt = Carbon::parse($validated['sent_at'])->utc();
        $attributes = [
            'addon_version' => $validated['addon_version'],
            'last_heartbeat_at' => $sentAt,
            'last_cron_at' => $sentAt,
            'cron_php_version' => $validated['cli_php_version'],
            'last_seen_at' => now(),
        ];

        if ($validated['cron_kind'] === 'daily') {
            $attributes['last_daily_cron_at'] = $sentAt;
        }

        $integration->forceFill($attributes)->save();
        $heartbeatStates->markOk($integration->fresh() ?? $integration);

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

        $fresh = $integration->fresh() ?? $integration;
        $pendingApprovedActions = SafeActionExecution::query()
            ->where('integration_id', $fresh->id)
            ->where('status', SafeActionExecution::STATUS_AWAITING_AGENT)
            ->exists();

        return response()->json([
            'data' => [
                'accepted' => true,
                'auto_collect_due' => $autoCollect->isDue($fresh),
                'pending_approved_actions' => $pendingApprovedActions,
            ],
        ], 202);
    }
}
