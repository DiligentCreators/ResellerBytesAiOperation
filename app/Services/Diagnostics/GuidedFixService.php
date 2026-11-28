<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\GuidedFixStep;
use App\Models\SafeActionExecution;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use App\Services\SafeActions\SafeActionMinutesSavedCalculator;
use App\Services\Tenancy\TenantEntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GuidedFixService
{
    public function __construct(
        private readonly FindingWorkflowService $workflow,
        private readonly FindingContinuityService $continuity,
        private readonly AuditEventWriter $audit,
        private readonly SafeActionMinutesSavedCalculator $minutesSaved,
        private readonly TenantEntitlementService $entitlements,
    ) {}

    /**
     * @return list<array{
     *     step_index: int,
     *     action: string,
     *     completed: bool,
     *     completed_at: string|null,
     *     completed_by_user_id: int|null
     * }>
     */
    public function stepsForFinding(DiagnosticFinding $finding): array
    {
        $catalog = $this->catalog($finding);
        $guidedSteps = is_array($catalog['guided_steps'] ?? null) ? $catalog['guided_steps'] : [];
        if ($guidedSteps === []) {
            return [];
        }

        $finding->loadMissing('diagnosticRun');
        $integrationId = $finding->diagnosticRun?->integration_id;
        $findingIds = $integrationId !== null
            ? $this->continuity->relatedFindingIds($finding, $integrationId)
            : [(string) $finding->id];

        if ($findingIds === []) {
            $findingIds = [(string) $finding->id];
        }

        $completed = GuidedFixStep::query()
            ->whereIn('finding_id', $findingIds)
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get()
            ->unique('step_index')
            ->keyBy('step_index');

        $steps = [];
        $index = 1;
        foreach ($guidedSteps as $action) {
            if (! is_string($action) || trim($action) === '') {
                continue;
            }

            /** @var GuidedFixStep|null $row */
            $row = $completed->get($index);
            $steps[] = [
                'step_index' => $index,
                'action' => $action,
                'completed' => $row?->completed_at !== null,
                'completed_at' => $row?->completed_at?->toIso8601String(),
                'completed_by_user_id' => $row?->completed_by_user_id,
            ];
            $index++;
        }

        return $steps;
    }

    public function markStepDone(DiagnosticFinding $finding, User $actor, int $stepIndex): GuidedFixStep
    {
        $this->requireGuidedEntitlement($finding);
        $steps = $this->stepsForFinding($finding);
        $max = count($steps);
        if ($stepIndex < 1 || $stepIndex > $max) {
            throw new InvalidArgumentException('Guided step index is out of range.');
        }

        if ($finding->tenant_id === null) {
            throw new InvalidArgumentException('Guided steps require a tenant-scoped finding.');
        }

        return DB::transaction(function () use ($finding, $actor, $stepIndex): GuidedFixStep {
            $step = GuidedFixStep::query()->firstOrNew([
                'finding_id' => $finding->id,
                'step_index' => $stepIndex,
            ]);

            $step->fill([
                'tenant_id' => $finding->tenant_id,
                'completed_by_user_id' => $actor->id,
                'completed_at' => now(),
            ])->save();

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'tenant_id' => $finding->tenant_id,
                'event_type' => 'guided_fix.step_completed',
                'subject_type' => 'diagnostic_finding',
                'subject_id' => $finding->id,
                'metadata' => [
                    'step_index' => $stepIndex,
                    'issue_key' => $finding->issue_key,
                ],
                'occurred_at' => now(),
            ]);

            return $step->fresh() ?? $step;
        });
    }

    public function requestRecheck(DiagnosticFinding $finding, User $actor): SafeActionExecution
    {
        $this->requireGuidedEntitlement($finding);

        $finding->loadMissing('diagnosticRun.integration');
        $integration = $finding->diagnosticRun?->integration;
        if ($integration === null) {
            throw new InvalidArgumentException('Finding has no installation to re-check.');
        }

        if (! is_string($finding->issue_key) || $finding->issue_key === '') {
            throw new InvalidArgumentException('Guided re-check requires a catalog issue key.');
        }

        return DB::transaction(function () use ($finding, $actor, $integration): SafeActionExecution {
            if ($finding->isActive() && $finding->status !== DiagnosticFinding::STATUS_IN_PROGRESS) {
                $this->workflow->update($finding, $actor, [
                    'status' => DiagnosticFinding::STATUS_IN_PROGRESS,
                    'assignee_user_id' => $finding->assignee_user_id ?? $actor->id,
                    'status_note' => 'Guided fix applied; waiting for the next scan to confirm.',
                ]);
            }

            $execution = SafeActionExecution::query()->create([
                'tenant_id' => $integration->tenant_id,
                'integration_id' => $integration->id,
                'finding_id' => $finding->id,
                'issue_key' => $finding->issue_key,
                'action_key' => 'pull_and_run_diagnostic_collect',
                'mode' => SafeActionExecution::MODE_GUIDED,
                'status' => SafeActionExecution::STATUS_AWAITING_AGENT,
                'risk_level' => 'medium',
                'requested_by_user_id' => $actor->id,
                'approved_by_user_id' => $actor->id,
                'approval_reason' => 'Guided fix completed; request re-collect to verify.',
                'requires_password_confirm' => false,
                'input' => ['mode' => SafeActionExecution::MODE_GUIDED],
                'items_total' => 1,
                'requested_at' => now(),
                'approved_at' => now(),
            ]);

            $integration->forceFill([
                'pending_diagnostic_collect_at' => now(),
                'pending_diagnostic_collect_execution_id' => $execution->id,
            ])->save();

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'tenant_id' => $integration->tenant_id,
                'event_type' => 'guided_fix.recheck_requested',
                'subject_type' => 'safe_action_execution',
                'subject_id' => $execution->id,
                'metadata' => [
                    'finding_id' => $finding->id,
                    'issue_key' => $finding->issue_key,
                    'mode' => SafeActionExecution::MODE_GUIDED,
                ],
                'occurred_at' => now(),
            ]);

            return $execution;
        });
    }

    /**
     * @return array{whmcs_admin_path: string|null, why_it_matters: string|null, title: string|null, fix_mode: string|null, never_one_click: bool, minutes_saved: int}
     */
    public function catalogMeta(DiagnosticFinding $finding): array
    {
        $catalog = $this->catalog($finding);

        return [
            'whmcs_admin_path' => is_string($catalog['whmcs_admin_path'] ?? null) ? $catalog['whmcs_admin_path'] : null,
            'why_it_matters' => is_string($catalog['why_it_matters'] ?? null) ? $catalog['why_it_matters'] : null,
            'title' => is_string($catalog['title'] ?? null) ? $catalog['title'] : null,
            'fix_mode' => is_string($catalog['fix_mode'] ?? null) ? $catalog['fix_mode'] : null,
            'never_one_click' => ($catalog['never_one_click'] ?? false) === true,
            'minutes_saved' => is_numeric($catalog['minutes_saved'] ?? null) ? (int) $catalog['minutes_saved'] : 0,
        ];
    }

    public function minutesSavedForIssue(?string $issueKey): int
    {
        return $this->minutesSaved->calculate($issueKey, 1);
    }

    private function requireGuidedEntitlement(DiagnosticFinding $finding): void
    {
        $finding->loadMissing('diagnosticRun.integration');
        $tenantId = $finding->tenant_id ?? $finding->diagnosticRun?->integration?->tenant_id;
        if ($tenantId === null) {
            return;
        }

        try {
            $this->entitlements->requireFeature($tenantId, 'fixes.guided');
        } catch (AuthorizationException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(DiagnosticFinding $finding): array
    {
        $issueKey = $finding->issue_key;
        if (! is_string($issueKey) || $issueKey === '') {
            return [];
        }

        return WhmcsIssueCatalog::get($issueKey) ?? [];
    }
}
