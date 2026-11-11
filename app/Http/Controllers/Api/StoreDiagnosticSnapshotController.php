<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDiagnosticSnapshotRequest;
use App\Models\AuditEvent;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class StoreDiagnosticSnapshotController extends Controller
{
    public function __invoke(StoreDiagnosticSnapshotRequest $request): JsonResponse
    {
        /** @var WhmcsIntegration $integration */
        $integration = $request->attributes->get('whmcs_integration');
        $validated = $request->validated();
        $payloadHash = hash('sha256', $request->getContent());

        $existing = DiagnosticRun::query()
            ->where('integration_id', $integration->id)
            ->where('snapshot_uuid', $validated['snapshot_uuid'])
            ->first();

        if ($existing) {
            return $this->acceptedResponse($existing, duplicate: true);
        }

        try {
            $run = DiagnosticRun::create([
                'integration_id' => $integration->id,
                'snapshot_uuid' => $validated['snapshot_uuid'],
                'schema_version' => $validated['schema_version'],
                'addon_version' => $validated['addon_version'],
                'status' => 'received',
                'collected_at' => Carbon::parse($validated['collected_at']),
                'received_at' => now(),
                'payload' => $validated['payload'],
                'payload_sha256' => $payloadHash,
            ]);
        } catch (QueryException) {
            // A concurrent retry may have inserted the same integration/snapshot pair.
            $run = DiagnosticRun::query()
                ->where('integration_id', $integration->id)
                ->where('snapshot_uuid', $validated['snapshot_uuid'])
                ->first();

            if (! $run) {
                throw new \RuntimeException('Unable to persist diagnostic snapshot.');
            }

            return $this->acceptedResponse($run, duplicate: true);
        }

        $integration->forceFill([
            'addon_version' => $validated['addon_version'],
            'last_seen_at' => now(),
        ])->save();

        AuditEvent::create([
            'actor_type' => 'integration',
            'actor_id' => $integration->id,
            'event_type' => 'diagnostic_snapshot.accepted',
            'subject_type' => 'diagnostic_run',
            'subject_id' => $run->id,
            'metadata' => ['payload_sha256' => $payloadHash],
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        return $this->acceptedResponse($run, duplicate: false);
    }

    private function acceptedResponse(DiagnosticRun $run, bool $duplicate): JsonResponse
    {
        return response()->json([
            'data' => [
                'run_id' => $run->id,
                'snapshot_uuid' => $run->snapshot_uuid,
                'status' => $run->status,
                'duplicate' => $duplicate,
            ],
        ], $duplicate ? 200 : 202);
    }
}
