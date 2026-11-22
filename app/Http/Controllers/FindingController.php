<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFindingWorkflowRequest;
use App\Models\DiagnosticFinding;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Diagnostics\FindingContinuityService;
use App\Services\Diagnostics\FindingPlaybookBuilder;
use App\Services\Diagnostics\FindingRecheckComparer;
use App\Services\Diagnostics\FindingWorkflowService;
use App\Services\Diagnostics\GuidedFixService;
use App\Services\SafeActions\FindingSafeActionMapper;
use App\Services\Tenancy\TenantEntitlementService;
use App\Services\Tenancy\TenantRecordQuery;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\AssertsTenantDiagnosticsAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FindingController extends Controller
{
    public function index(Request $request): Response
    {
        AssertsTenantDiagnosticsAccess::forIndex(Auth::user());

        $statusFilter = $request->string('status')->toString();
        $query = app(TenantRecordQuery::class)->findings(Auth::user())
            ->with([
                'diagnosticRun.integration:id,name',
                'assignee:id,name',
            ])
            ->latest();

        $allowedFilters = ['active', 'history', ...DiagnosticFinding::STATUSES];
        if ($statusFilter !== 'history') {
            $query->currentIssues();
        }

        if ($statusFilter === 'active') {
            $query->active();
        } elseif (in_array($statusFilter, DiagnosticFinding::STATUSES, true)) {
            $query->where('status', $statusFilter);
        }

        $findings = $query
            ->paginate(20)
            ->withQueryString()
            ->through(fn (DiagnosticFinding $finding): array => [
                'id' => $finding->id,
                'title' => $finding->title,
                'category' => $finding->category,
                'severity' => $finding->severity,
                'status' => $finding->status,
                'created_at' => $finding->created_at?->toIso8601String(),
                'integration_name' => $finding->diagnosticRun->integration->name,
                'diagnostic_run_id' => $finding->diagnostic_run_id,
                'assignee_name' => $finding->assignee?->name,
            ]);

        $records = app(TenantRecordQuery::class);
        $continuity = app(FindingContinuityService::class);
        $activeCount = $continuity->countCurrentActiveIssues($records->findings(Auth::user()));
        $analyzedRuns = $records->runs(Auth::user())->where('status', 'analyzed')->count();

        return Inertia::render('findings/index', [
            'findings' => $findings,
            'filters' => [
                'status' => in_array($statusFilter, $allowedFilters, true)
                    ? $statusFilter
                    : 'all',
            ],
            'health' => [
                'last_scan_clean' => $analyzedRuns > 0 && $activeCount === 0,
                'active_count' => $activeCount,
                'analyzed_runs' => $analyzedRuns,
            ],
        ]);
    }

    public function show(
        DiagnosticFinding $finding,
        FindingPlaybookBuilder $playbookBuilder,
        FindingRecheckComparer $recheckComparer,
        FindingSafeActionMapper $safeActionMapper,
        FindingContinuityService $continuity,
        GuidedFixService $guidedFix,
    ): Response {
        $this->authorizeFinding($finding);

        $finding->load([
            'diagnosticRun.integration:id,name,owner_user_id,tenant_id',
            'recommendations',
            'assignee:id,name,email',
            'guidedFixSteps',
        ]);

        $user = Auth::user();
        $canManage = $this->canManageFindings($user, $finding);
        $integration = $finding->diagnosticRun->integration;
        $safeActionsEnabled = false;
        $oneClickEnabled = false;
        $bulkEnabled = false;
        $guidedEnabled = false;

        if ($integration?->tenant_id !== null) {
            $entitlements = app(TenantEntitlementService::class);
            try {
                $entitlements->requireFeature($integration->tenant_id, 'safe_actions.enabled');
                $safeActionsEnabled = true;
            } catch (AuthorizationException) {
                $safeActionsEnabled = false;
            }

            try {
                $entitlements->requireFeature($integration->tenant_id, 'fixes.one_click');
                $oneClickEnabled = true;
            } catch (AuthorizationException) {
                $oneClickEnabled = false;
            }

            try {
                $entitlements->requireFeature($integration->tenant_id, 'fixes.bulk');
                $bulkEnabled = true;
            } catch (AuthorizationException) {
                $bulkEnabled = false;
            }

            try {
                $entitlements->requireFeature($integration->tenant_id, 'fixes.guided');
                $guidedEnabled = true;
            } catch (AuthorizationException) {
                $guidedEnabled = false;
            }
        }

        $catalogMeta = $guidedFix->catalogMeta($finding);
        $guidedSteps = is_string($finding->issue_key) && $finding->issue_key !== ''
            ? $guidedFix->stepsForFinding($finding)
            : [];

        $blockedKeys = $canManage
            ? $continuity->blockedSafeActionKeys($finding)
            : [];
        $affectedIds = $this->affectedIdsFromEvidence(is_array($finding->evidence) ? $finding->evidence : []);
        $suggested = $canManage
            ? collect($safeActionMapper->suggestedActions($finding))
                ->reject(fn (array $action): bool => in_array($action['key'], $blockedKeys, true))
                ->map(function (array $action) use ($safeActionsEnabled, $oneClickEnabled, $bulkEnabled): array {
                    $entitlement = $action['entitlement'] ?? 'safe_actions.enabled';
                    $canExecute = match ($entitlement) {
                        'fixes.one_click' => $oneClickEnabled,
                        default => $safeActionsEnabled,
                    };

                    return [
                        ...$action,
                        'can_one_click' => $canExecute,
                        'can_bulk' => $canExecute && $bulkEnabled && ($action['bulk_allowed'] ?? false),
                        'upgrade_hint' => $canExecute
                            ? null
                            : 'Upgrade to Growth to fix in one click. Guided steps remain available below.',
                    ];
                })
                ->values()
                ->all()
            : [];

        return Inertia::render('findings/show', [
            'finding' => [
                'id' => $finding->id,
                'title' => $finding->title,
                'category' => $finding->category,
                'severity' => $finding->severity,
                'status' => $finding->status,
                'summary' => $finding->summary,
                'evidence' => $finding->evidence,
                'confidence' => $finding->confidence,
                'created_at' => $finding->created_at?->toIso8601String(),
                'status_updated_at' => $finding->status_updated_at?->toIso8601String(),
                'resolved_at' => $finding->resolved_at?->toIso8601String(),
                'status_note' => $finding->status_note,
                'issue_key' => $finding->issue_key,
                'affected_count' => $finding->affected_count,
                'affected_ids' => $affectedIds,
                'integration_id' => $integration?->id,
                'integration_name' => $integration?->name,
                'diagnostic_run_id' => $finding->diagnostic_run_id,
                'assignee' => $finding->assignee === null ? null : [
                    'id' => $finding->assignee->id,
                    'name' => $finding->assignee->name,
                    'email' => $finding->assignee->email,
                ],
                'recommendations' => $finding->recommendations->map(fn ($recommendation): array => [
                    'id' => $recommendation->id,
                    'recommendation' => $recommendation->recommendation,
                    'rationale' => $recommendation->rationale,
                    'risk_level' => $recommendation->risk_level,
                    'requires_manual_action' => $recommendation->requires_manual_action,
                ]),
            ],
            'playbook' => $playbookBuilder->build($finding),
            'recheck' => $recheckComparer->compare($finding),
            'assignees' => $this->assignableMembers($finding),
            'can_manage' => $canManage,
            'statuses' => DiagnosticFinding::STATUSES,
            'suggested_safe_actions' => $suggested,
            'blocked_safe_action_keys' => $blockedKeys,
            'safe_actions_enabled' => $safeActionsEnabled || $oneClickEnabled,
            'fixes_one_click_enabled' => $oneClickEnabled,
            'fixes_bulk_enabled' => $bulkEnabled,
            'fixes_guided_enabled' => $guidedEnabled,
            'guided_steps' => $guidedSteps,
            'whmcs_admin_path' => $catalogMeta['whmcs_admin_path'],
            'fix_center_url' => route('fix-center.index'),
        ]);
    }

    public function update(
        UpdateFindingWorkflowRequest $request,
        DiagnosticFinding $finding,
        FindingWorkflowService $workflow,
    ): RedirectResponse {
        $this->authorizeFinding($finding);
        $this->authorizeManage($finding);

        try {
            $workflow->update($finding, $request->user(), $request->workflowInput());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'workflow' => $exception->getMessage(),
            ]);
        }

        return back()->with('status', 'Finding workflow updated.');
    }

    public function recheck(
        DiagnosticFinding $finding,
        FindingRecheckComparer $recheckComparer,
    ): RedirectResponse {
        $this->authorizeFinding($finding);
        $result = $recheckComparer->compare($finding);

        return back()->with('status', $result['message']);
    }

    private function authorizeFinding(DiagnosticFinding $finding): void
    {
        if (! app(TenantRecordQuery::class)->findings(Auth::user())->whereKey($finding->id)->exists()) {
            throw new NotFoundHttpException;
        }
    }

    private function authorizeManage(DiagnosticFinding $finding): void
    {
        $user = Auth::user();
        if (! $this->canManageFindings($user, $finding)) {
            abort(403);
        }
    }

    private function canManageFindings(?User $user, DiagnosticFinding $finding): bool
    {
        if ($user === null) {
            return false;
        }

        $finding->loadMissing('diagnosticRun.integration');
        $integration = $finding->diagnosticRun?->integration;

        if ($integration === null) {
            return false;
        }

        if ($integration->tenant_id === null) {
            return (int) $integration->owner_user_id === (int) $user->id;
        }

        try {
            app(TenantRoleAuthorization::class)->require($user, $integration->tenant_id, 'diagnostics.manage_findings');

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<int>
     */
    private function affectedIdsFromEvidence(array $evidence): array
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

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    private function assignableMembers(DiagnosticFinding $finding): array
    {
        $finding->loadMissing('diagnosticRun.integration');
        $integration = $finding->diagnosticRun?->integration;

        if ($integration === null) {
            return [];
        }

        if ($integration->tenant_id === null) {
            $owner = User::query()->find($integration->owner_user_id, ['id', 'name', 'email']);

            return $owner === null ? [] : [[
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ]];
        }

        $members = [];
        $memberships = TenantMembership::query()
            ->where('tenant_id', $integration->tenant_id)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereIn('role', [
                TenantMembership::ROLE_OWNER,
                TenantMembership::ROLE_ADMIN,
                TenantMembership::ROLE_OPERATOR,
                TenantMembership::ROLE_VIEWER,
            ])
            ->with('user:id,name,email')
            ->get();

        foreach ($memberships as $membership) {
            if ($membership->user === null) {
                continue;
            }

            $members[] = [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
            ];
        }

        return $members;
    }
}
