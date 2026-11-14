<?php

namespace App\Jobs;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\TenantMembership;
use App\Jobs\Middleware\SetTenantDatabaseContext;
use App\Services\Diagnostics\AiBudgetService;
use App\Services\Diagnostics\WhmcsDiagnosticAnalyzer;
use App\Services\Tenancy\TenantEntitlementService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnalyzeDiagnosticRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    private const ANALYSIS_LEASE_SECONDS = 100;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public string $diagnosticRunId, public int $ownerUserId, public ?string $tenantId = null)
    {
        if ($this->ownerUserId < 1) {
            throw new \InvalidArgumentException('A positive diagnostic owner user ID is required.');
        }
    }

    public function middleware(): array
    {
        return [new SetTenantDatabaseContext($this->tenantId, $this->ownerUserId)];
    }

    public function handle(WhmcsDiagnosticAnalyzer $analyzer, AiBudgetService $budget): void
    {
        $run = DB::transaction(function (): ?DiagnosticRun {
            $run = $this->authorizedRunQuery($this->diagnosticRunId)
                ->with('integration')
                ->lockForUpdate()
                ->first();

            if (! $run || $run->status === 'analyzed') {
                return null;
            }

            // Expired diagnostics must never enter a new billable analysis attempt.
            $retentionCutoff = now('UTC')->subDays(365);
            if ($run->received_at === null || $run->received_at->lessThanOrEqualTo($retentionCutoff)) {
                return null;
            }

            if ($run->status === 'analyzing') {
                if ($run->analysis_started_at?->greaterThan(now()->subSeconds(self::ANALYSIS_LEASE_SECONDS))) {
                    return null;
                }
            } elseif (! in_array($run->status, ['received', 'queued', 'analysis_failed'], true)) {
                return null;
            }

            $run->forceFill([
                'status' => 'analyzing',
                'analysis_started_at' => now(),
                'analysis_error_code' => null,
                'analysis_attempts' => $run->analysis_attempts + 1,
            ])->save();

            return $run->fresh();
        });

        if (! $run) {
            return;
        }

        $attempt = null;

        try {
            if ($this->tenantId !== null) {
                app(TenantEntitlementService::class)->requireFeature($this->tenantId, 'diagnostics.analysis');
            }

            $model = (string) config('services.openai.model', 'gpt-5.6-sol');
            $attempt = $budget->reserve($run, $model, WhmcsDiagnosticAnalyzer::PROMPT_VERSION);

            $result = $analyzer->analyze(
                $run,
                function () use ($budget, $attempt, $run): void {
                    DB::transaction(function () use ($budget, $attempt, $run): void {
                        $currentRun = DiagnosticRun::query()
                            ->whereKey($run->id)
                            ->lockForUpdate()
                            ->first();

                        if ($currentRun === null || ! $this->authorizedRunQuery($currentRun->id)->exists()) {
                            throw new RuntimeException('analysis_context_changed');
                        }

                        if ($currentRun->received_at === null
                            || $currentRun->received_at->lessThanOrEqualTo(now('UTC')->subDays(365))) {
                            throw new RuntimeException('diagnostic_retention_expired');
                        }

                        $budget->markSubmitted($attempt);
                    }, 3);
                },
                function (array $body, array $usage) use ($budget, $attempt): void {
                    $responseId = is_string($body['id'] ?? null) ? substr($body['id'], 0, 100) : null;
                    $budget->settle($attempt, $usage, $responseId);
                },
            );
        } catch (Throwable $exception) {
            if ($attempt !== null) {
                try {
                    $attempt->refresh();
                    if ($attempt->status === 'reserved') {
                        $budget->releaseBeforeSubmit($attempt, $this->safeErrorCode($exception));
                    } elseif ($attempt->status === 'submitted') {
                        $budget->markAmbiguous($attempt, $this->safeErrorCode($exception));
                    }
                } catch (Throwable) {
                    // Preserve the original failure; unresolved reservations remain counted.
                }
            }

            $this->authorizedRunQuery($run->id)
                ->where('status', '!=', 'analyzed')
                ->update([
                    'status' => 'analysis_failed',
                    'analysis_error_code' => $this->safeErrorCode($exception),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        DB::transaction(function () use ($run, $result): void {
            $lockedRun = $this->authorizedRunQuery($run->id)
                ->lockForUpdate()
                ->first();

            if ($lockedRun === null) {
                return;
            }

            // The provider response may arrive after the absolute retention deadline.
            // Recheck under the same row lock used for persistence so expired content
            // cannot be reintroduced by a long-running worker.
            if ($lockedRun->received_at === null
                || $lockedRun->received_at->lessThanOrEqualTo(now('UTC')->subDays(365))) {
                $lockedRun->forceFill([
                    'status' => 'analysis_failed',
                    'analysis_error_code' => 'diagnostic_retention_expired',
                ])->save();

                return;
            }

            $lockedRun->findings()->delete();
            $createdFingerprints = [];

            foreach ($result['findings'] as $findingData) {
                $evidencePaths = array_values(array_unique($findingData['evidence_paths']));
                sort($evidencePaths, SORT_STRING);

                $fingerprint = hash('sha256', implode("\n", [
                    $findingData['category'],
                    mb_strtolower($findingData['title']),
                    implode('|', $evidencePaths),
                ]));

                if (isset($createdFingerprints[$fingerprint])) {
                    continue;
                }

                $createdFingerprints[$fingerprint] = true;
                $evidence = [];
                foreach ($evidencePaths as $path) {
                    $evidence[$path] = data_get($lockedRun->payload, $path);
                }

                $finding = DiagnosticFinding::create([
                    'diagnostic_run_id' => $lockedRun->id,
                    'tenant_id' => $lockedRun->tenant_id,
                    'fingerprint' => $fingerprint,
                    'category' => $findingData['category'],
                    'severity' => $findingData['severity'],
                    'title' => $findingData['title'],
                    'summary' => $findingData['summary'],
                    'evidence' => $evidence,
                    'confidence' => $findingData['confidence'],
                    'status' => 'open',
                ]);

                foreach ($findingData['recommendations'] as $recommendation) {
                    $finding->recommendations()->create([
                        'tenant_id' => $lockedRun->tenant_id,
                        'recommendation' => $recommendation['recommendation'],
                        'rationale' => $recommendation['rationale'],
                        'risk_level' => $recommendation['risk_level'],
                        'requires_manual_action' => true,
                    ]);
                }
            }

            $lockedRun->forceFill([
                'status' => 'analyzed',
                'analysis_model' => (string) config('services.openai.model', 'gpt-5.6-sol'),
                'analysis_prompt_version' => WhmcsDiagnosticAnalyzer::PROMPT_VERSION,
                'analysis_response_id' => $result['response_id'],
                'analysis_summary' => $result['summary'],
                'no_findings_reason' => $result['no_findings_reason'],
                'analysis_completed_at' => now(),
                'analysis_error_code' => null,
                'analysis_usage' => $result['usage'],
            ])->save();

            if ($this->tenantId !== null) {
                $recipientIds = TenantMembership::query()
                    ->where('tenant_id', $this->tenantId)
                    ->where('status', TenantMembership::STATUS_ACTIVE)
                    ->whereIn('role', [
                        TenantMembership::ROLE_OWNER,
                        TenantMembership::ROLE_ADMIN,
                        TenantMembership::ROLE_OPERATOR,
                        TenantMembership::ROLE_VIEWER,
                    ])
                    ->pluck('user_id');

                foreach ($recipientIds as $recipientId) {
                    DB::table('tenant_notifications')->insertOrIgnore([
                        'tenant_id' => $this->tenantId,
                        'user_id' => $recipientId,
                        'event_type' => 'diagnostics.run.completed',
                        'notification_key' => 'diagnostic-run:'.$lockedRun->id.':analyzed',
                        'payload' => json_encode(['run_id' => $lockedRun->id, 'status' => 'analyzed'], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantDatabaseContext::class)->run($this->tenantId, $this->ownerUserId, function () use ($exception): void {
            $this->authorizedRunQuery($this->diagnosticRunId)
                ->where('status', '!=', 'analyzed')
                ->update([
                    'status' => 'analysis_failed',
                    'analysis_error_code' => $this->safeErrorCode($exception),
                    'updated_at' => now(),
                ]);
        });
    }


    /**
     * Revalidate the immutable tenant context captured at dispatch. Legacy jobs
     * remain owner-bound; tenant-owned jobs require an active tenant.
     *
     * @return Builder<DiagnosticRun>
     */
    private function authorizedRunQuery(string $runId): Builder
    {
        return DiagnosticRun::query()
            ->whereKey($runId)
            ->whereHas('integration', function (Builder $integration): void {
                $integration->where('tenant_id', $this->tenantId);

                if ($this->tenantId !== null) {
                    $integration->whereHas('tenant', fn (Builder $tenant) => $tenant->where('status', 'active'));
                } else {
                    $integration->where('owner_user_id', $this->ownerUserId);
                }
            });
    }

    private function safeErrorCode(?Throwable $exception): string
    {
        $allowed = [
            'provider_not_configured',
            'provider_request_failed',
            'provider_response_incomplete',
            'provider_output_missing',
            'provider_output_invalid_json',
            'provider_output_invalid_shape',
            'provider_output_schema_invalid',
            'provider_no_findings_reason_missing',
            'provider_no_findings_reason_unexpected',
            'provider_evidence_reference_invalid',
            'budget_input_limit_exceeded',
            'diagnostic_retention_expired',
            'analysis_context_changed',
            'budget_configuration_invalid',
            'budget_tenant_inactive',
            'tenant_entitlement_denied',
            'budget_tenant_configuration_invalid',
            'budget_tenant_limit_configuration_changed',
            'budget_tenant_exhausted',
            'budget_tenant_reservation_invariant_failed',
            'budget_attempt_unresolved',
            'budget_attempt_not_reservable',
            'budget_exhausted',
            'budget_limit_configuration_changed',
            'budget_price_card_unavailable',
            'budget_price_card_expired',
            'budget_usage_invalid',
            'provider_usage_missing',
        ];

        if ($exception instanceof AuthorizationException) {
            return 'tenant_entitlement_denied';
        }

        $message = $exception?->getMessage() ?? '';

        return in_array($message, $allowed, true) ? $message : 'analysis_failed';
    }
}
