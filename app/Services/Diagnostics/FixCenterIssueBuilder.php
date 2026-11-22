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
     *     pending_safe_action: array{id: string, status: string, url: string}|null,
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
        $meta = $this->guided->catalogMeta($finding);
        $hasAllowlistedOneClick = $this->hasAllowlistedOneClickActions($finding);
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
        $primary = $this->primaryAction($fixMode, $canOneClick, $canGuide, $upgradeToOneClick);

        $blocked = $canManage ? $this->continuity->blockedSafeActionKeys($finding) : [];
        $pendingExecution = $canManage ? $this->continuity->pendingSafeActionExecution($finding) : null;
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
            'pending_safe_action' => $this->serializePendingSafeAction($pendingExecution),
            'affected_ids' => $this->affectedIds(is_array($finding->evidence) ? $finding->evidence : []),
            'fixes_unavailable' => ! $platformFixesEnabled,
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

    private function hasAllowlistedOneClickActions(DiagnosticFinding $finding): bool
    {
        $issueKey = $finding->issue_key;
        if (! is_string($issueKey) || $issueKey === '') {
            return false;
        }

        foreach (WhmcsIssueCatalog::actionKeys($issueKey) as $key) {
            if (! $this->registry->isAllowlisted($key)) {
                continue;
            }

            $definition = $this->registry->get($key);
            if (($definition['entitlement'] ?? '') === 'fixes.one_click') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: string, status: string, url: string}|null
     */
    private function serializePendingSafeAction(?SafeActionExecution $execution): ?array
    {
        if ($execution === null) {
            return null;
        }

        return [
            'id' => $execution->id,
            'status' => $execution->status,
            'url' => URL::route('safe-actions.show', $execution),
        ];
    }

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
            DiagnosticFinding::STATUS_RESOLVED, DiagnosticFinding::STATUS_ACCEPTED_RISK => 'fixed',
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
