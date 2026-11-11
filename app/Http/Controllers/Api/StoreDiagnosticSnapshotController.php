<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDiagnosticSnapshotRequest;
use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\AuditEvent;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StoreDiagnosticSnapshotController extends Controller
{
    public function __invoke(StoreDiagnosticSnapshotRequest $request): JsonResponse
    {
        /** @var WhmcsIntegration $integration */
        $integration = $request->attributes->get('whmcs_integration');
        $validated = $request->validated();
        $payloadHash = hash('sha256', $request->getContent());

        $existing = $this->findExistingRun($integration, $validated['snapshot_uuid']);

        if ($existing) {
            return $this->idempotentResponse($existing, $payloadHash);
        }

        try {
            $run = DB::transaction(function () use ($integration, $validated, $payloadHash, $request): DiagnosticRun {
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

                return $run;
            });
        } catch (QueryException $exception) {
            // A concurrent retry may have inserted the same integration/snapshot pair.
            $existing = $this->findExistingRun($integration, $validated['snapshot_uuid']);

            if (! $existing) {
                throw $exception;
            }

            return $this->idempotentResponse($existing, $payloadHash);
        }

        if (filled(config('services.openai.key'))) {
            $run->forceFill(['status' => 'queued'])->save();

            try {
                AnalyzeDiagnosticRun::dispatch($run->id)->afterCommit();
            } catch (Throwable $exception) {
                $run->forceFill(['status' => 'received'])->save();

                Log::warning('Diagnostic analysis could not be queued.', [
                    'diagnostic_run_id' => $run->id,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return $this->acceptedResponse($run->refresh(), duplicate: false);
    }

    private function findExistingRun(WhmcsIntegration $integration, string $snapshotUuid): ?DiagnosticRun
    {
        return DiagnosticRun::query()
            ->where('integration_id', $integration->id)
            ->where('snapshot_uuid', $snapshotUuid)
            ->first();
    }

    private function idempotentResponse(DiagnosticRun $run, string $payloadHash): JsonResponse
    {
        if (! hash_equals($run->payload_sha256, $payloadHash)) {
            return response()->json(['message' => 'Snapshot identifier conflict.'], 409);
        }

        return $this->acceptedResponse($run, duplicate: true);
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
