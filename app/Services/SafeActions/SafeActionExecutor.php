<?php

namespace App\Services\SafeActions;

use App\Events\FixActionFailed;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use App\Services\Diagnostics\FindingContinuityService;
use App\Services\Diagnostics\FindingPlaybookBuilder;
use App\Services\Diagnostics\WhmcsIssueCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class SafeActionExecutor
{
    public function __construct(
        private readonly SafeActionRegistry $registry,
        private readonly AuditEventWriter $audit,
        private readonly FindingPlaybookBuilder $playbookBuilder,
        private readonly FindingContinuityService $continuity,
        private readonly SafeActionPreviewBuilder $previewBuilder,
        private readonly SafeActionMinutesSavedCalculator $minutesSaved,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function request(
        WhmcsIntegration $integration,
        User $actor,
        string $actionKey,
        array $input = [],
        ?DiagnosticFinding $finding = null,
    ): SafeActionExecution {
        $definition = $this->registry->get($actionKey);

        if ($definition['requires_finding'] && $finding === null) {
            throw new InvalidArgumentException('This safe action requires a finding context.');
        }

        if ($finding !== null) {
            $this->assertFindingBelongsToIntegration($finding, $integration);
            $this->assertActionBoundToFinding($actionKey, $definition, $finding);
        }

        $mode = $this->resolveMode($definition, $input);
        // Ignore client-supplied free-form command params (messagename, LocalAPI values, etc.).
        $input = $this->sanitizeClientInput($input, $mode);
        $resolvedParams = $this->registry->resolveParams($actionKey);
        $usesLocalApiTargets = ($definition['entitlement'] ?? '') === 'fixes.one_click'
            || $actionKey === 'whmcs_clear_template_cache';

        $preview = null;
        $targetIds = null;
        $itemsTotal = null;
        $batchId = null;

        if ($usesLocalApiTargets) {
            if ($actionKey !== 'whmcs_clear_template_cache' && $finding === null) {
                throw new InvalidArgumentException('This safe action requires a finding context.');
            }

            $selectedIds = $this->selectedIdsFromInput($input);
            $preview = $this->previewBuilder->build(
                $actionKey,
                $integration,
                $finding,
                $selectedIds,
                $mode,
                $resolvedParams,
            );

            if ($preview['items_total'] < 1 && $actionKey !== 'whmcs_clear_template_cache') {
                $refusedReasons = array_values(array_unique(array_map(
                    static fn (array $row): string => (string) ($row['reason'] ?? ''),
                    $preview['refused'],
                )));

                if (in_array('terminate_refused', $refusedReasons, true) && count($refusedReasons) === 1) {
                    throw new InvalidArgumentException('Module terminate queue items are refused. Use guided remediation only.');
                }

                if ($mode === SafeActionExecution::MODE_AUTO && in_array('auto_action_refused', $refusedReasons, true)) {
                    throw new InvalidArgumentException('No auto-safe targets remain after preview validation.');
                }

                throw new InvalidArgumentException('No executable targets remain after preview validation.');
            }

            $targetIds = $preview['target_ids'];
            $itemsTotal = $preview['items_total'];
            $batchId = ($mode === SafeActionExecution::MODE_BULK || $itemsTotal > 1)
                ? (string) Str::uuid()
                : null;
            $input = array_merge($input, [
                'target_ids' => $targetIds,
                'preview_commands' => array_map(
                    static fn (array $item): string => (string) $item['command'],
                    $preview['items'],
                ),
            ]);
        }

        return DB::transaction(function () use ($integration, $actor, $actionKey, $definition, $input, $finding, $mode, $preview, $batchId, $itemsTotal, $targetIds, $resolvedParams): SafeActionExecution {
            $execution = SafeActionExecution::query()->create([
                'tenant_id' => $integration->tenant_id,
                'integration_id' => $integration->id,
                'finding_id' => $finding?->id,
                'issue_key' => $finding?->issue_key,
                'action_key' => $actionKey,
                'mode' => $mode,
                'batch_id' => $batchId,
                'items_total' => $itemsTotal,
                'status' => SafeActionExecution::STATUS_PENDING_APPROVAL,
                'risk_level' => $definition['risk_level'],
                'requested_by_user_id' => $actor->id,
                'requires_password_confirm' => $definition['requires_password_confirm'],
                'input' => $input,
                'preview' => $preview,
                'target_ids' => $targetIds,
                'resolved_params' => $resolvedParams === [] ? null : $resolvedParams,
                'before_evidence' => $this->captureBeforeEvidence($integration),
                'requested_at' => now(),
            ]);

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'tenant_id' => $integration->tenant_id,
                'event_type' => 'safe_action.requested',
                'subject_type' => 'safe_action_execution',
                'subject_id' => $execution->id,
                'metadata' => [
                    'action_key' => $actionKey,
                    'risk_level' => $definition['risk_level'],
                    'integration_id' => $integration->id,
                    'mode' => $mode,
                    'items_total' => $itemsTotal,
                    'batch_id' => $batchId,
                ],
                'occurred_at' => now(),
            ]);

            return $execution;
        });
    }

    public function approve(SafeActionExecution $execution, User $actor, string $reason): SafeActionExecution
    {
        return DB::transaction(function () use ($execution, $actor, $reason): SafeActionExecution {
            /** @var SafeActionExecution $locked */
            $locked = SafeActionExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SafeActionExecution::STATUS_PENDING_APPROVAL) {
                throw new RuntimeException('Only pending safe actions can be approved.');
            }

            $definition = $this->registry->get($locked->action_key);

            $locked->forceFill([
                'status' => SafeActionExecution::STATUS_APPROVED,
                'approved_by_user_id' => $actor->id,
                'approval_reason' => $reason,
                'approved_at' => now(),
            ])->save();

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'tenant_id' => $locked->tenant_id,
                'event_type' => 'safe_action.approved',
                'subject_type' => 'safe_action_execution',
                'subject_id' => $locked->id,
                'metadata' => [
                    'action_key' => $locked->action_key,
                    'reason' => $reason,
                ],
                'occurred_at' => now(),
            ]);

            $fresh = $locked->fresh() ?? $locked;

            if (($definition['execution_target'] ?? 'platform') === 'addon') {
                return $this->queueForAddon($fresh, $definition);
            }

            return $this->execute($fresh, $definition);
        });
    }

    public function reject(SafeActionExecution $execution, User $actor, ?string $reason = null): SafeActionExecution
    {
        return DB::transaction(function () use ($execution, $actor, $reason): SafeActionExecution {
            /** @var SafeActionExecution $locked */
            $locked = SafeActionExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();

            $previousStatus = $locked->status;

            if (! in_array($previousStatus, [
                SafeActionExecution::STATUS_PENDING_APPROVAL,
                SafeActionExecution::STATUS_AWAITING_AGENT,
            ], true)) {
                throw new RuntimeException('Only pending or awaiting-agent safe actions can be rejected or cancelled.');
            }

            if ($previousStatus === SafeActionExecution::STATUS_AWAITING_AGENT
                && $locked->wasPickedUpByAgent()) {
                throw ValidationException::withMessages([
                    'rejection_reason' => SafeActionExecution::REJECT_AFTER_PICKUP_MESSAGE,
                ]);
            }

            $terminalStatus = $previousStatus === SafeActionExecution::STATUS_AWAITING_AGENT
                ? SafeActionExecution::STATUS_CANCELLED
                : SafeActionExecution::STATUS_REJECTED;

            $normalizedReason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

            $locked->forceFill([
                'status' => $terminalStatus,
                'rejected_by_user_id' => $actor->id,
                'rejection_reason' => $normalizedReason,
                'rejected_at' => now(),
            ])->save();

            $locked->loadMissing('integration');
            $integration = $locked->integration;
            if ($integration !== null
                && $integration->pending_diagnostic_collect_execution_id === $locked->id) {
                $integration->forceFill([
                    'pending_diagnostic_collect_at' => null,
                    'pending_diagnostic_collect_execution_id' => null,
                ])->save();
            }

            $eventType = $terminalStatus === SafeActionExecution::STATUS_CANCELLED
                ? 'safe_action.cancelled'
                : 'safe_action.rejected';

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'tenant_id' => $locked->tenant_id,
                'event_type' => $eventType,
                'subject_type' => 'safe_action_execution',
                'subject_id' => $locked->id,
                'metadata' => [
                    'action_key' => $locked->action_key,
                    'previous_status' => $previousStatus,
                    'status' => $terminalStatus,
                    'reason' => $normalizedReason,
                    'rejected_by_user_id' => $actor->id,
                    'rejected_at' => $locked->rejected_at?->toIso8601String(),
                ],
                'occurred_at' => now(),
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     risk_level: string,
     *     requires_password_confirm: bool,
     *     requires_finding: bool,
     *     auto_verify: bool,
     *     execution_target?: string,
     *     entitlement: string
     * }  $definition
     */
    private function queueForAddon(SafeActionExecution $execution, array $definition): SafeActionExecution
    {
        $execution->forceFill([
            'status' => SafeActionExecution::STATUS_AWAITING_AGENT,
            'result' => [
                'summary' => 'Approved and waiting for the WHMCS addon to apply this allowlisted action.',
                'instructions' => [
                    'Open Addons → ResellerBytes AI Operations in WHMCS.',
                    'Click “Apply approved Safe Actions”.',
                    'After apply, the addon submits a verify scan. The finding clears only when runtime proof matches.',
                ],
                'lifecycle' => ['approve', 'apply', 'verify', 'cleared_or_still_present'],
                'addon_path' => 'addonmodules.php?module=resellerbytes_ai_ops',
                'execution_target' => 'addon',
            ],
        ])->save();

        if ($this->shouldQueueVerifyCollect($execution->action_key)) {
            $execution->loadMissing('integration');
            $execution->integration?->forceFill([
                'pending_diagnostic_collect_at' => now(),
                'pending_diagnostic_collect_execution_id' => $execution->id,
            ])->save();
        }

        $this->audit->append([
            'actor_type' => 'user',
            'actor_id' => (string) $execution->approved_by_user_id,
            'tenant_id' => $execution->tenant_id,
            'event_type' => 'safe_action.awaiting_agent',
            'subject_type' => 'safe_action_execution',
            'subject_id' => $execution->id,
            'metadata' => [
                'action_key' => $execution->action_key,
                'risk_level' => $definition['risk_level'],
            ],
            'occurred_at' => now(),
        ]);

        return $execution->fresh() ?? $execution;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function recordAddonResult(
        SafeActionExecution $execution,
        WhmcsIntegration $integration,
        string $status,
        string $summary,
        array $evidence = [],
        ?string $errorCode = null,
    ): SafeActionExecution {
        return DB::transaction(function () use ($execution, $integration, $status, $summary, $evidence, $errorCode): SafeActionExecution {
            /** @var SafeActionExecution $locked */
            $locked = SafeActionExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();

            if ($locked->integration_id !== $integration->id) {
                throw new RuntimeException('Safe action does not belong to this integration.');
            }

            if (in_array($locked->status, [
                SafeActionExecution::STATUS_CANCELLED,
                SafeActionExecution::STATUS_REJECTED,
            ], true)) {
                return $this->recordLateAddonResult(
                    $locked,
                    $integration,
                    $status,
                    $summary,
                    $evidence,
                    $errorCode,
                );
            }

            if ($locked->status !== SafeActionExecution::STATUS_AWAITING_AGENT) {
                throw new RuntimeException('Only awaiting-agent safe actions can receive addon results.');
            }

            if ($status === 'success') {
                $proven = $this->hasRuntimeProof($locked->action_key, $evidence);
                // Guided re-checks only verify when the next snapshot no longer detects the issue.
                if ($locked->mode === SafeActionExecution::MODE_GUIDED) {
                    $proven = false;
                }
                $itemsSucceeded = $this->countSucceededItems($evidence, $locked);
                $minutes = $proven || $locked->mode !== SafeActionExecution::MODE_GUIDED
                    ? $this->minutesSaved->calculate($locked->issue_key, $itemsSucceeded)
                    : null;
                $locked->forceFill([
                    'status' => $proven
                        ? SafeActionExecution::STATUS_VERIFIED
                        : SafeActionExecution::STATUS_COMPLETED,
                    'items_succeeded' => $itemsSucceeded,
                    'minutes_saved' => $minutes,
                    'result' => [
                        'summary' => $summary,
                        'evidence' => $evidence,
                        'applied_by' => 'whmcs_addon',
                        'runtime_proven' => $proven,
                        'items_succeeded' => $itemsSucceeded,
                        'minutes_saved' => $minutes,
                        'lifecycle' => $proven
                            ? 'verified'
                            : 'applied_awaiting_verify',
                    ],
                    'after_evidence' => $evidence,
                    'executed_at' => now(),
                    'verified_at' => $proven ? now() : null,
                ])->save();

                if ($proven) {
                    if ($integration->pending_diagnostic_collect_execution_id === $locked->id) {
                        $integration->forceFill([
                            'pending_diagnostic_collect_at' => null,
                            'pending_diagnostic_collect_execution_id' => null,
                        ])->save();
                    }
                } elseif ($this->shouldQueueVerifyCollect($locked->action_key)) {
                    $integration->forceFill([
                        'pending_diagnostic_collect_at' => now(),
                        'pending_diagnostic_collect_execution_id' => $locked->id,
                    ])->save();
                }

                $this->syncFindingAfterAddonResult($locked, $evidence, $proven);

                $this->audit->append([
                    'actor_type' => 'integration',
                    'actor_id' => $integration->id,
                    'tenant_id' => $locked->tenant_id,
                    'event_type' => $proven ? 'safe_action.verified' : 'safe_action.completed',
                    'subject_type' => 'safe_action_execution',
                    'subject_id' => $locked->id,
                    'metadata' => [
                        'action_key' => $locked->action_key,
                        'summary' => $summary,
                        'runtime_proven' => $proven,
                        'items_succeeded' => $itemsSucceeded,
                        'minutes_saved' => $minutes,
                        'mode' => $locked->mode,
                    ],
                    'occurred_at' => now(),
                ]);

                if ($locked->mode === SafeActionExecution::MODE_GUIDED && ! $proven) {
                    $latestRun = DiagnosticRun::query()
                        ->where('integration_id', $integration->id)
                        ->whereIn('status', ['analyzed', 'received', 'queued'])
                        ->latest('received_at')
                        ->first();
                    if ($latestRun !== null) {
                        $this->verifyCollectFromSnapshot($integration->fresh() ?? $integration, $latestRun);
                    }
                }
            } else {
                $locked->forceFill([
                    'status' => SafeActionExecution::STATUS_FAILED,
                    'result' => [
                        'summary' => $summary,
                        'error_code' => $errorCode ?? 'addon_apply_failed',
                        'evidence' => $evidence,
                        'applied_by' => 'whmcs_addon',
                    ],
                    'after_evidence' => $evidence,
                    'executed_at' => now(),
                ])->save();

                $this->audit->append([
                    'actor_type' => 'integration',
                    'actor_id' => $integration->id,
                    'tenant_id' => $locked->tenant_id,
                    'event_type' => 'safe_action.failed',
                    'subject_type' => 'safe_action_execution',
                    'subject_id' => $locked->id,
                    'metadata' => [
                        'action_key' => $locked->action_key,
                        'error_code' => $errorCode ?? 'addon_apply_failed',
                    ],
                    'occurred_at' => now(),
                ]);

                if (is_string($locked->tenant_id)) {
                    event(new FixActionFailed($locked->id, $locked->tenant_id));
                }
            }

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function recordLateAddonResult(
        SafeActionExecution $execution,
        WhmcsIntegration $integration,
        string $status,
        string $summary,
        array $evidence,
        ?string $errorCode,
    ): SafeActionExecution {
        $receivedAt = now();
        $preservedStatus = $execution->status;

        $execution->forceFill([
            'late_result_status' => $status,
            'late_result_at' => $receivedAt,
            'result' => [
                'summary' => $summary,
                'evidence' => $evidence,
                'error_code' => $errorCode,
                'applied_by' => 'whmcs_addon',
                'addon_status' => $status,
                'late' => true,
                'received_at' => $receivedAt->toIso8601String(),
                'preserved_status' => $preservedStatus,
            ],
        ])->save();

        $this->audit->append([
            'actor_type' => 'integration',
            'actor_id' => $integration->id,
            'tenant_id' => $execution->tenant_id,
            'event_type' => 'safe_action.late_result',
            'subject_type' => 'safe_action_execution',
            'subject_id' => $execution->id,
            'metadata' => [
                'action_key' => $execution->action_key,
                'addon_status' => $status,
                'summary' => $summary,
                'error_code' => $errorCode,
                'preserved_status' => $preservedStatus,
                'late' => true,
                'late_result_at' => $receivedAt->toIso8601String(),
            ],
            'occurred_at' => $receivedAt,
        ]);

        Log::warning('Late WHMCS addon result received for a cancelled or rejected safe action.', [
            'safe_action_execution_id' => $execution->id,
            'integration_id' => $integration->id,
            'tenant_id' => $execution->tenant_id,
            'preserved_status' => $preservedStatus,
            'addon_status' => $status,
            'late_result_at' => $receivedAt->toIso8601String(),
        ]);

        return $execution->fresh() ?? $execution;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function hasRuntimeProof(string $actionKey, array $evidence): bool
    {
        return match ($actionKey) {
            'disable_php_display_errors' => ($evidence['error_display_disabled'] ?? null) === true,
            'harden_configuration_php_mode' => ($evidence['configuration_file_writable'] ?? null) === false,
            'pull_and_run_diagnostic_collect', 'request_diagnostic_collect' => ($evidence['remediation_applied'] ?? false) === true
                || array_key_exists('duplicate', $evidence)
                || array_key_exists('http_status', $evidence),
            'whmcs_clear_template_cache' => ($evidence['templates_c_cleared'] ?? false) === true,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function countSucceededItems(array $evidence, SafeActionExecution $execution): int
    {
        $results = $evidence['item_results'] ?? null;
        if (is_array($results)) {
            $succeeded = 0;
            foreach ($results as $result) {
                if (is_array($result) && ($result['status'] ?? null) === 'success') {
                    $succeeded++;
                }
            }

            return $succeeded;
        }

        if (($evidence['remediation_applied'] ?? false) === true) {
            return max(1, (int) ($execution->items_total ?? 1));
        }

        return 0;
    }

    private function shouldQueueVerifyCollect(string $actionKey): bool
    {
        if (in_array($actionKey, ['pull_and_run_diagnostic_collect', 'request_diagnostic_collect'], true)) {
            return true;
        }

        if (! $this->registry->isAllowlisted($actionKey)) {
            return false;
        }

        $definition = $this->registry->get($actionKey);

        return ($definition['execution_target'] ?? null) === 'addon'
            && ($definition['entitlement'] ?? null) === 'fixes.one_click';
    }

    /**
     * @param  array{
     *     bulk_allowed: bool,
     *     max_items: int
     * }  $definition
     * @param  array<string, mixed>  $input
     */
    private function resolveMode(array $definition, array $input): string
    {
        $requested = isset($input['mode']) && is_string($input['mode'])
            ? $input['mode']
            : SafeActionExecution::MODE_ONE_CLICK;

        if (! in_array($requested, [
            SafeActionExecution::MODE_ONE_CLICK,
            SafeActionExecution::MODE_GUIDED,
            SafeActionExecution::MODE_AUTO,
            SafeActionExecution::MODE_BULK,
        ], true)) {
            $requested = SafeActionExecution::MODE_ONE_CLICK;
        }

        $selected = $this->selectedIdsFromInput($input);
        if ($requested === SafeActionExecution::MODE_ONE_CLICK && $selected !== null && count($selected) > 1) {
            $requested = SafeActionExecution::MODE_BULK;
        }

        if ($requested === SafeActionExecution::MODE_BULK && ! $definition['bulk_allowed']) {
            throw new InvalidArgumentException('This safe action does not allow bulk mode.');
        }

        if ($requested === SafeActionExecution::MODE_AUTO && ! ($definition['auto_allowed'] ?? false)) {
            throw new InvalidArgumentException('This safe action does not allow auto mode.');
        }

        return $requested;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>|null
     */
    private function selectedIdsFromInput(array $input): ?array
    {
        if (! array_key_exists('target_ids', $input)) {
            return null;
        }

        $raw = $input['target_ids'];
        if (! is_array($raw)) {
            throw new InvalidArgumentException('target_ids must be a list of integers.');
        }

        $ids = [];
        foreach ($raw as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                throw new InvalidArgumentException('target_ids must contain integers only.');
            }
            $id = (int) $value;
            if ($id < 1) {
                throw new InvalidArgumentException('target_ids must contain positive integers only.');
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    private function assertFindingBelongsToIntegration(
        DiagnosticFinding $finding,
        WhmcsIntegration $integration,
    ): void {
        $finding->loadMissing('diagnosticRun');

        if ($finding->diagnosticRun?->integration_id !== $integration->id) {
            throw new InvalidArgumentException('Finding does not belong to this integration.');
        }

        if ($finding->tenant_id !== null
            && $integration->tenant_id !== null
            && $finding->tenant_id !== $integration->tenant_id) {
            throw new InvalidArgumentException('Finding does not belong to this tenant.');
        }
    }

    /**
     * @param  array{
     *     entitlement: string,
     *     requires_finding: bool
     * }  $definition
     */
    private function assertActionBoundToFinding(
        string $actionKey,
        array $definition,
        DiagnosticFinding $finding,
    ): void {
        if (($definition['entitlement'] ?? '') !== 'fixes.one_click') {
            return;
        }

        if ($actionKey === 'whmcs_clear_template_cache') {
            return;
        }

        $issueKey = $finding->issue_key;
        if (! is_string($issueKey) || $issueKey === '') {
            throw new InvalidArgumentException('This safe action is not bound to the selected finding.');
        }

        $catalogKeys = WhmcsIssueCatalog::actionKeys($issueKey);
        if (! in_array($actionKey, $catalogKeys, true)) {
            throw new InvalidArgumentException('This safe action is not bound to the selected finding.');
        }
    }

    /**
     * Drop free-form command params from client input. Only mode, target_ids,
     * and (auto mode only) auto_fix_policy_id are retained.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function sanitizeClientInput(array $input, string $mode): array
    {
        $clean = [];

        if (isset($input['mode']) && is_string($input['mode'])) {
            $clean['mode'] = $input['mode'];
        }

        if (array_key_exists('target_ids', $input)) {
            $clean['target_ids'] = $input['target_ids'];
        }

        if ($mode === SafeActionExecution::MODE_AUTO
            && array_key_exists('auto_fix_policy_id', $input)) {
            $clean['auto_fix_policy_id'] = $input['auto_fix_policy_id'];
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function syncFindingAfterAddonResult(SafeActionExecution $execution, array $evidence, bool $proven): void
    {
        if ($execution->finding_id === null) {
            return;
        }

        $finding = DiagnosticFinding::query()->find($execution->finding_id);
        if ($finding === null || ! $finding->isActive()) {
            return;
        }

        $actorId = $execution->approved_by_user_id ?? $execution->requested_by_user_id;
        $actor = User::query()->find($actorId);
        if ($actor === null) {
            return;
        }

        if ($proven) {
            $this->continuity->resolveProvenSiblings($finding, $actor, $execution->action_key);

            return;
        }

        if (($evidence['remediation_applied'] ?? false) === true) {
            $this->continuity->markAppliedAwaitingVerify($finding, $actor, $execution->action_key);
        }
    }

    /**
     * @param  array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     risk_level: string,
     *     requires_password_confirm: bool,
     *     requires_finding: bool,
     *     auto_verify: bool,
     *     execution_target?: string,
     *     entitlement: string
     * }  $definition
     */
    private function execute(SafeActionExecution $execution, array $definition): SafeActionExecution
    {
        $execution->loadMissing(['integration', 'finding']);

        try {
            $result = match ($execution->action_key) {
                'health_version_ping' => $this->runHealthVersionPing($execution),
                'permissions_audit_report' => $this->runPermissionsAudit($execution),
                'whmcs_admin_deep_link' => $this->runDeepLink($execution),
                default => throw new InvalidArgumentException('Action is not on the Safe Action allowlist.'),
            };

            $execution->forceFill([
                'status' => $definition['auto_verify']
                    ? SafeActionExecution::STATUS_VERIFIED
                    : SafeActionExecution::STATUS_COMPLETED,
                'result' => $result,
                'after_evidence' => $result['after_evidence'] ?? $result,
                'executed_at' => now(),
                'verified_at' => $definition['auto_verify'] ? now() : null,
            ])->save();

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $execution->approved_by_user_id,
                'tenant_id' => $execution->tenant_id,
                'event_type' => $definition['auto_verify'] ? 'safe_action.verified' : 'safe_action.completed',
                'subject_type' => 'safe_action_execution',
                'subject_id' => $execution->id,
                'metadata' => [
                    'action_key' => $execution->action_key,
                    'summary' => $result['summary'] ?? null,
                ],
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $execution->forceFill([
                'status' => SafeActionExecution::STATUS_FAILED,
                'result' => ['error' => 'safe_action_failed'],
                'executed_at' => now(),
            ])->save();

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $execution->approved_by_user_id,
                'tenant_id' => $execution->tenant_id,
                'event_type' => 'safe_action.failed',
                'subject_type' => 'safe_action_execution',
                'subject_id' => $execution->id,
                'metadata' => [
                    'action_key' => $execution->action_key,
                    'exception_class' => $exception::class,
                ],
                'occurred_at' => now(),
            ]);

            if (is_string($execution->tenant_id)) {
                event(new FixActionFailed($execution->id, $execution->tenant_id));
            }

            throw $exception;
        }

        return $execution->fresh() ?? $execution;
    }

    public function verifyCollectFromSnapshot(WhmcsIntegration $integration, DiagnosticRun $run): void
    {
        if ($integration->pending_diagnostic_collect_at === null
            || $integration->pending_diagnostic_collect_execution_id === null) {
            return;
        }

        if ($run->received_at !== null
            && $run->received_at->lt($integration->pending_diagnostic_collect_at)) {
            return;
        }

        DB::transaction(function () use ($integration, $run): void {
            $execution = SafeActionExecution::query()
                ->whereKey($integration->pending_diagnostic_collect_execution_id)
                ->lockForUpdate()
                ->first();

            // Only executions the addon reported as applied (result endpoint success →
            // completed/succeeded with executed_at) may be verified by a snapshot.
            // pending_approval, approved, awaiting_agent, and other not-yet-applied states
            // must remain untouched so they stay in the addon pending queue.
            if ($execution === null
                || $execution->status !== SafeActionExecution::STATUS_COMPLETED
                || $execution->executed_at === null) {
                return;
            }

            // Guided completions must wait until the rule engine no longer detects the issue.
            if ($execution->mode === SafeActionExecution::MODE_GUIDED) {
                if (! $this->findingClearedOrReduced($execution, $run)) {
                    return;
                }
            } elseif (! $this->findingClearedOrReduced($execution, $run)
                && ! in_array($execution->action_key, [
                    'pull_and_run_diagnostic_collect',
                    'request_diagnostic_collect',
                    'whmcs_clear_template_cache',
                ], true)) {
                return;
            }

            $minutes = $execution->minutes_saved;
            if ($execution->mode === SafeActionExecution::MODE_GUIDED) {
                $minutes = $this->minutesSaved->calculate($execution->issue_key, 1);
            }

            $execution->forceFill([
                'status' => SafeActionExecution::STATUS_VERIFIED,
                'minutes_saved' => $minutes,
                'items_succeeded' => $execution->items_succeeded ?? 1,
                'verified_at' => now(),
                'after_evidence' => array_merge((array) $execution->after_evidence, [
                    'verified_run_id' => $run->id,
                    'verified_received_at' => $run->received_at?->toIso8601String(),
                ]),
            ])->save();

            $integration->forceFill([
                'pending_diagnostic_collect_at' => null,
                'pending_diagnostic_collect_execution_id' => null,
            ])->save();

            if ($execution->finding_id !== null) {
                $finding = DiagnosticFinding::query()->find($execution->finding_id);
                $actor = User::query()->find($execution->approved_by_user_id ?? $execution->requested_by_user_id);
                if ($finding !== null && $actor !== null && $finding->isActive()) {
                    $this->continuity->resolveProvenSiblings($finding, $actor, $execution->action_key);
                }
            }

            $this->audit->append([
                'actor_type' => 'integration',
                'actor_id' => $integration->id,
                'tenant_id' => $integration->tenant_id,
                'event_type' => 'safe_action.verified',
                'subject_type' => 'safe_action_execution',
                'subject_id' => $execution->id,
                'metadata' => [
                    'action_key' => $execution->action_key,
                    'diagnostic_run_id' => $run->id,
                ],
                'occurred_at' => now(),
            ]);
        });
    }

    private function findingClearedOrReduced(SafeActionExecution $execution, DiagnosticRun $run): bool
    {
        if ($execution->issue_key === null || $execution->issue_key === '') {
            return in_array($execution->action_key, [
                'pull_and_run_diagnostic_collect',
                'request_diagnostic_collect',
                'whmcs_clear_template_cache',
            ], true);
        }

        $beforeCount = null;
        if ($execution->finding_id !== null) {
            $beforeCount = DiagnosticFinding::query()->whereKey($execution->finding_id)->value('affected_count');
        }

        $current = DiagnosticFinding::query()
            ->where('diagnostic_run_id', $run->id)
            ->where('issue_key', $execution->issue_key)
            ->where('source', 'rule')
            ->first();

        if ($current === null) {
            return true;
        }

        if ($beforeCount === null) {
            return false;
        }

        return is_numeric($current->affected_count)
            && (int) $current->affected_count < (int) $beforeCount;
    }

    /**
     * @return array<string, mixed>
     */
    private function captureBeforeEvidence(WhmcsIntegration $integration): array
    {
        $latest = DiagnosticRun::query()
            ->where('integration_id', $integration->id)
            ->latest('received_at')
            ->first();

        return [
            'integration_id' => $integration->id,
            'addon_version' => $integration->addon_version,
            'last_seen_at' => $integration->last_seen_at?->toIso8601String(),
            'latest_run_id' => $latest?->id,
            'latest_run_status' => $latest?->status,
            'whmcs_version' => data_get($latest?->payload, 'system.whmcs_version'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runHealthVersionPing(SafeActionExecution $execution): array
    {
        $integration = $execution->integration;
        $latest = DiagnosticRun::query()
            ->where('integration_id', $integration->id)
            ->where('status', 'analyzed')
            ->latest('received_at')
            ->first()
            ?? DiagnosticRun::query()
                ->where('integration_id', $integration->id)
                ->latest('received_at')
                ->first();

        $whmcsVersion = data_get($latest?->payload, 'system.whmcs_version', 'unknown');
        $staleHours = $latest?->received_at
            ? $latest->received_at->diffInHours(now())
            : null;

        return [
            'summary' => 'Read-only health/version ping from signed snapshot evidence.',
            'whmcs_version' => $whmcsVersion,
            'addon_version' => $integration->addon_version ?? data_get($latest?->payload, 'collector.addon_version', $latest?->addon_version),
            'last_seen_at' => $integration->last_seen_at?->toIso8601String(),
            'latest_snapshot_received_at' => $latest?->received_at?->toIso8601String(),
            'latest_snapshot_status' => $latest?->status,
            'stale_hours' => $staleHours,
            'php_version' => data_get($latest?->payload, 'system.web_php_version'),
            'database_version' => data_get($latest?->payload, 'system.database_version'),
            'note' => 'This ping uses evidence already stored in AI Ops. It does not call WHMCS write APIs.',
            'after_evidence' => [
                'whmcs_version' => $whmcsVersion,
                'stale_hours' => $staleHours,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPermissionsAudit(SafeActionExecution $execution): array
    {
        $latest = DiagnosticRun::query()
            ->where('integration_id', $execution->integration_id)
            ->latest('received_at')
            ->first();

        if ($latest === null) {
            throw new RuntimeException('No diagnostic snapshot is available for a permissions audit.');
        }

        $checks = [
            [
                'id' => 'configuration_file_writable',
                'label' => 'configuration.php writable',
                'value' => data_get($latest->payload, 'system.configuration_file_writable'),
                'severity' => data_get($latest->payload, 'system.configuration_file_writable') === true ? 'warning' : 'info',
            ],
            [
                'id' => 'error_display_disabled',
                'label' => 'PHP display_errors disabled',
                'value' => data_get($latest->payload, 'system.error_display_disabled'),
                'severity' => data_get($latest->payload, 'system.error_display_disabled') === false ? 'warning' : 'info',
            ],
            [
                'id' => 'application_url_configured',
                'label' => 'System URL configured',
                'value' => data_get($latest->payload, 'system.application_url_configured'),
                'severity' => 'info',
            ],
        ];

        $extensions = data_get($latest->payload, 'php.extensions', []);
        if (is_array($extensions)) {
            foreach ($extensions as $name => $enabled) {
                if ($enabled !== true) {
                    $checks[] = [
                        'id' => 'extension.'.$name,
                        'label' => 'PHP extension '.$name,
                        'value' => $enabled,
                        'severity' => 'warning',
                    ];
                }
            }
        }

        $warnings = collect($checks)->where('severity', 'warning')->count();

        return [
            'summary' => sprintf('Permissions audit completed with %d warning signal(s).', $warnings),
            'diagnostic_run_id' => $latest->id,
            'checks' => $checks,
            'warning_count' => $warnings,
            'note' => 'Read-only report from snapshot evidence. No WHMCS permissions were changed.',
            'after_evidence' => [
                'warning_count' => $warnings,
                'diagnostic_run_id' => $latest->id,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runDeepLink(SafeActionExecution $execution): array
    {
        $paths = [
            [
                'label' => 'ResellerBytes AI Ops addon',
                'path' => 'addonmodules.php?module=resellerbytes_ai_ops',
            ],
            [
                'label' => 'System Health',
                'path' => 'Utilities > System > System Health',
            ],
        ];

        $context = [
            'execution_id' => $execution->id,
            'integration_id' => $execution->integration_id,
        ];

        if ($execution->finding_id !== null) {
            $finding = $execution->finding ?? DiagnosticFinding::query()->find($execution->finding_id);
            if ($finding !== null) {
                $playbook = $this->playbookBuilder->build($finding);
                $paths = array_merge(
                    collect($playbook['deep_links'])->map(fn (array $link): array => [
                        'label' => $link['label'],
                        'path' => $link['path'],
                    ])->all(),
                    $paths,
                );
                $context['finding_id'] = $finding->id;
                $context['finding_title'] = $finding->title;
                $context['category'] = $finding->category;
            }
        }

        return [
            'summary' => 'WHMCS admin deep-link hints generated without secrets.',
            'paths' => $paths,
            'context' => $context,
            'note' => 'Open these paths in your WHMCS admin session. URLs never include credentials or tokens.',
            'after_evidence' => [
                'path_count' => count($paths),
                'finding_id' => $execution->finding_id,
            ],
        ];
    }
}
