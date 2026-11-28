<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\SafeActionExecution;
use App\Services\SafeActions\FindingSafeActionMapper;
use App\Services\SafeActions\SafeActionRegistry;
use Illuminate\Support\Facades\URL;

class FixCenterIssueBuilder
{
    public function __construct(
        private readonly GuidedFixService $guided,
        private readonly FindingSafeActionMapper $safeActionMapper,
        private readonly SafeActionRegistry $registry,
        private readonly FindingContinuityService $continuity,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     why_it_matters: string,
     *     affected_count: int|null,
     *     installation: string,
     *     installation_id: string|null,
     *     severity: string,
     *     status: string,
     *     tab: string,
     *     issue_key: string|null,
     *     fix_mode: string,
     *     primary_action: string,
     *     can_one_click: bool,
     *     can_bulk: bool,
     *     can_guide: bool,
     *     upgrade_to_one_click: bool,
     *     bulk_max_items: int,
     *     ignored_until: string|null,
     *     whmcs_admin_path: string|null,
     *     guided_steps: list<array{step_index: int, action: string, completed: bool, completed_at: string|null, completed_by_user_id: int|null}>,
     *     suggested_actions: list<array<string, mixed>>,
     *     blocked_action_keys: list<string>,
     *     fix_state: 'pending_approval'|'awaiting_agent'|'awaiting_verification'|null,
     *     pending_safe_action: array{id: string, status: string, url: string, can_reject: bool, reject_blocked_reason: string|null}|null,
     *     awaiting_verification_safe_action: array{id: string, url: string}|null,
     *     affected_ids: list<int>,
     *     fixes_unavailable: bool
     * }
     */
    public function serialize(
        DiagnosticFinding $finding,
        bool $oneClickEnabled,
        bool $bulkEnabled,
        bool $guidedEnabled,
        bool $canManage,
        bool $platformFixesEnabled = true,
    ): array {
        $capabilities = $this->capabilities(
            $finding,
            $oneClickEnabled,
            $guidedEnabled,
            $canManage,
            $platformFixesEnabled,
        );
        $fixMode = $capabilities['fix_mode'];
        $canOneClick = $capabilities['can_one_click'];
        $canGuide = $capabilities['can_guide'];
        $upgradeToOneClick = $capabilities['upgrade_to_one_click'];
        $primary = $capabilities['primary_action'];
        $fixState = $capabilities['fix_state'];
        $blocked = $capabilities['blocked_action_keys'];
        $pendingExecution = $capabilities['pending_execution'];
        $awaitingVerificationExecution = $capabilities['awaiting_verification_execution'];
        $meta = $this->guided->catalogMeta($finding);

        $suggested = [];
        if ($canManage && ($canOneClick || $upgradeToOneClick)) {
            foreach ($this->safeActionMapper->suggestedActions($finding) as $action) {
                if (in_array($action['key'], $blocked, true)
                    || $action['key'] === 'pull_and_run_diagnostic_collect'
                    || ($action['entitlement'] ?? '') !== 'fixes.one_click') {
                    continue;
                }

                $suggested[] = [
                    'key' => $action['key'],
                    'label' => $action['label'],
                    'description' => $action['description'],
                    'risk_level' => $action['risk_level'],
                    'requires_password_confirm' => $action['requires_password_confirm'],
                    'bulk_allowed' => $action['bulk_allowed'],
                    'max_items' => $action['max_items'],
                    'can_one_click' => $canOneClick,
                    'can_bulk' => $canOneClick && $bulkEnabled && ($action['bulk_allowed'] ?? false),
                ];
            }
        }

        $title = $meta['title'] ?? $finding->title;
        $why = $meta['why_it_matters'] ?? $finding->summary;
        $finding->loadMissing('diagnosticRun.integration');
        $integration = $finding->diagnosticRun?->integration;

        return [
            'id' => $finding->id,
            'title' => $title,
            'why_it_matters' => $why,
            'affected_count' => $finding->affected_count,
            'installation' => $integration !== null ? $integration->name : 'Installation',
            'installation_id' => $finding->diagnosticRun?->integration_id,
            'severity' => $finding->severity,
            'status' => $finding->status,
            'tab' => $this->tabFor($finding),
            'issue_key' => $finding->issue_key,
            'fix_mode' => $fixMode,
            'primary_action' => $primary,
            'can_one_click' => $canOneClick,
            'can_bulk' => $canOneClick && $bulkEnabled,
            'can_guide' => $canGuide,
            'upgrade_to_one_click' => $upgradeToOneClick,
            'bulk_max_items' => $this->registry->bulkMaxItems(),
            'ignored_until' => $finding->ignored_until?->toIso8601String(),
            'whmcs_admin_path' => $meta['whmcs_admin_path'],
            'guided_steps' => $canGuide || $fixMode === 'guided' || $fixMode === 'info'
                ? $this->guided->stepsForFinding($finding)
                : [],
            'suggested_actions' => $suggested,
            'blocked_action_keys' => $blocked,
            'fix_state' => $fixState,
            'pending_safe_action' => $this->serializePendingSafeAction($pendingExecution),
            'awaiting_verification_safe_action' => $this->serializeAwaitingVerificationSafeAction(
                $awaitingVerificationExecution,
            ),
            'affected_ids' => $this->affectedIds(is_array($finding->evidence) ? $finding->evidence : []),
            'fixes_unavailable' => ! $platformFixesEnabled,
        ];
    }

    /**
     * Resolve the same primary CTA used by Fix Center (QA-RB-28).
     *
     * @return 'fix_now'|'start_guide'|'learn_more'
     */
    public function resolvePrimaryAction(
        DiagnosticFinding $finding,
        bool $oneClickEnabled,
        bool $guidedEnabled,
        bool $platformFixesEnabled = true,
        bool $canManage = true,
    ): string {
        return $this->capabilities(
            $finding,
            $oneClickEnabled,
            $guidedEnabled,
            $canManage,
            $platformFixesEnabled,
        )['primary_action'];
    }

    /**
     * @return array{
     *     fix_mode: string,
     *     can_one_click: bool,
     *     can_guide: bool,
     *     upgrade_to_one_click: bool,
     *     primary_action: 'fix_now'|'start_guide'|'learn_more',
     *     fix_state: 'pending_approval'|'awaiting_agent'|'awaiting_verification'|null,
     *     blocked_action_keys: list<string>,
     *     pending_execution: SafeActionExecution|null,
     *     awaiting_verification_execution: SafeActionExecution|null
     * }
     */
    private function capabilities(
        DiagnosticFinding $finding,
        bool $oneClickEnabled,
        bool $guidedEnabled,
        bool $canManage,
        bool $platformFixesEnabled,
    ): array {
        $meta = $this->guided->catalogMeta($finding);
        $allowlistedOneClickKeys = $this->allowlistedOneClickActionKeys($finding);
        $hasAllowlistedOneClick = $allowlistedOneClickKeys !== [];
        $fixMode = $this->resolveFixMode($finding, $meta, $hasAllowlistedOneClick);
        $neverOneClick = $meta['never_one_click'] || $fixMode === 'guided' || $fixMode === 'info';
        $canOneClick = $canManage
            && $oneClickEnabled
            && $platformFixesEnabled
            && ! $neverOneClick
            && $fixMode === 'one_click'
            && $hasAllowlistedOneClick;
        $upgradeToOneClick = $canManage
            && $platformFixesEnabled
            && ! $oneClickEnabled
            && $fixMode === 'one_click'
            && $hasAllowlistedOneClick
            && ! $meta['never_one_click'];
        $canGuide = $canManage
            && $guidedEnabled
            && is_string($finding->issue_key)
            && $finding->issue_key !== ''
            && $fixMode !== 'info';

        $blocked = [];
        $pendingExecution = null;
        $awaitingVerificationExecution = null;
        $fixState = null;

        if ($canManage) {
            $blocked = $this->continuity->blockedSafeActionKeys($finding);
            $pendingExecution = $this->continuity->pendingSafeActionExecution($finding);

            if ($pendingExecution !== null) {
                $fixState = match ($pendingExecution->status) {
                    SafeActionExecution::STATUS_PENDING_APPROVAL => 'pending_approval',
                    default => 'awaiting_agent',
                };
            } elseif ($this->allKeysBlocked($allowlistedOneClickKeys, $blocked)) {
                $awaitingVerificationExecution = $this->continuity->awaitingVerificationSafeActionExecution($finding);
                if ($awaitingVerificationExecution !== null) {
                    $fixState = 'awaiting_verification';
                    $canOneClick = false;
                    $upgradeToOneClick = false;
                }
            }
        }

        $primary = $fixState === 'awaiting_verification'
            ? 'learn_more'
            : $this->primaryAction($fixMode, $canOneClick, $canGuide, $upgradeToOneClick);

        return [
            'fix_mode' => $fixMode,
            'can_one_click' => $canOneClick,
            'can_guide' => $canGuide,
            'upgrade_to_one_click' => $upgradeToOneClick,
            'primary_action' => $primary,
            'fix_state' => $fixState,
            'blocked_action_keys' => $blocked,
            'pending_execution' => $pendingExecution,
            'awaiting_verification_execution' => $awaitingVerificationExecution,
        ];
    }

    /**
     * @param  array{never_one_click: bool, fix_mode: string|null}  $meta
     */
    private function resolveFixMode(DiagnosticFinding $finding, array $meta, bool $hasAllowlistedOneClick): string
    {
        if (($meta['never_one_click'] ?? false) === true) {
            return 'guided';
        }

        $mode = $meta['fix_mode'] ?? $finding->fix_mode;
        if ($mode === 'one_click' && ! $hasAllowlistedOneClick) {
            return 'guided';
        }

        if (in_array($mode, ['one_click', 'guided', 'info'], true)) {
            return $mode;
        }

        return 'guided';
    }

    /**
     * @return list<string>
     */
    private function allowlistedOneClickActionKeys(DiagnosticFinding $finding): array
    {
        $issueKey = $finding->issue_key;
        if (! is_string($issueKey) || $issueKey === '') {
            return [];
        }

        $keys = [];
        foreach (WhmcsIssueCatalog::actionKeys($issueKey) as $key) {
            if (! $this->registry->isAllowlisted($key)) {
                continue;
            }

            $definition = $this->registry->get($key);
            if (($definition['entitlement'] ?? '') === 'fixes.one_click') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $blocked
     */
    private function allKeysBlocked(array $keys, array $blocked): bool
    {
        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (! in_array($key, $blocked, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{id: string, status: string, url: string, can_reject: bool, reject_blocked_reason: string|null}|null
     */
    private function serializePendingSafeAction(?SafeActionExecution $execution): ?array
    {
        if ($execution === null) {
            return null;
        }

        $canReject = $execution->isRejectable();

        return [
            'id' => $execution->id,
            'status' => $execution->status,
            'url' => URL::route('safe-actions.show', $execution),
            'can_reject' => $canReject,
            'reject_blocked_reason' => ! $canReject
                && $execution->status === SafeActionExecution::STATUS_AWAITING_AGENT
                && $execution->wasPickedUpByAgent()
                ? SafeActionExecution::REJECT_AFTER_PICKUP_MESSAGE
                : null,
        ];
    }

    /**
     * @return array{id: string, url: string}|null
     */
    private function serializeAwaitingVerificationSafeAction(?SafeActionExecution $execution): ?array
    {
        if ($execution === null) {
            return null;
        }

        return [
            'id' => $execution->id,
            'url' => URL::route('safe-actions.show', $execution),
        ];
    }

    /**
     * @return 'fix_now'|'start_guide'|'learn_more'
     */
    private function primaryAction(string $fixMode, bool $canOneClick, bool $canGuide, bool $upgradeToOneClick): string
    {
        if ($fixMode === 'info') {
            return 'learn_more';
        }

        if ($canOneClick) {
            return 'fix_now';
        }

        if ($canGuide || $upgradeToOneClick || $fixMode === 'guided' || $fixMode === 'one_click') {
            return 'start_guide';
        }

        return 'learn_more';
    }

    private function tabFor(DiagnosticFinding $finding): string
    {
        if ($finding->isIgnored()) {
            return 'ignored';
        }

        return match ($finding->status) {
            DiagnosticFinding::STATUS_IN_PROGRESS => 'fixing',
            DiagnosticFinding::STATUS_RESOLVED => 'fixed',
            DiagnosticFinding::STATUS_ACCEPTED_RISK => 'ignored',
            default => 'open',
        };
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<int>
     */
    private function affectedIds(array $evidence): array
    {
        foreach ($evidence as $value) {
            if (! is_array($value)) {
                continue;
            }

            $ids = [];
            foreach ($value as $item) {
                if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                    $id = (int) $item;
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }

            if ($ids !== []) {
                return array_values(array_slice(array_unique($ids), 0, 50));
            }
        }

        return [];
    }
}
