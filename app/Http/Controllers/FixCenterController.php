<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteGuidedFixRequest;
use App\Http\Requests\IgnoreFindingRequest;
use App\Http\Requests\MarkGuidedFixStepRequest;
use App\Models\DiagnosticFinding;
use App\Models\User;
use App\Services\Diagnostics\FindingIgnoreService;
use App\Services\Diagnostics\FixCenterIssueBuilder;
use App\Services\Diagnostics\FixedIssueCounter;
use App\Services\Diagnostics\GuidedFixService;
use App\Services\SafeActions\SafeActionRegistry;
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

class FixCenterController extends Controller
{
    public function index(
        Request $request,
        FixCenterIssueBuilder $builder,
    ): Response {
        AssertsTenantDiagnosticsAccess::forIndex(Auth::user());

        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['open', 'fixing', 'fixed', 'ignored'], true)) {
            $tab = 'open';
        }

        $user = Auth::user();
        $fixedIssues = app(FixedIssueCounter::class);

        if ($tab === 'fixed') {
            $query = $fixedIssues->queryForUser($user)
                ->with([
                    'diagnosticRun.integration:id,name,tenant_id,owner_user_id',
                    'guidedFixSteps',
                ])
                ->latest();
        } else {
            $query = app(TenantRecordQuery::class)->findings($user)
                ->with([
                    'diagnosticRun.integration:id,name,tenant_id,owner_user_id',
                    'guidedFixSteps',
                ])
                ->currentIssues()
                ->latest();

            if ($tab === 'open') {
                $query->notIgnored()->where('status', DiagnosticFinding::STATUS_OPEN);
            } elseif ($tab === 'fixing') {
                $query->notIgnored()->where('status', DiagnosticFinding::STATUS_IN_PROGRESS);
            } else {
                // Ignored tab: snoozed issues and accepted risk (never counted as fixed).
                $query->where(function ($ignored): void {
                    $ignored->where(function ($snoozed): void {
                        $snoozed->ignored();
                    })->orWhere('status', DiagnosticFinding::STATUS_ACCEPTED_RISK);
                });
            }
        }

        $entitlements = $this->workspaceEntitlements($user);
        $registry = app(SafeActionRegistry::class);
        $fixesEnabled = $registry->fixesEnabled();

        $issues = $query
            ->paginate(20)
            ->withQueryString()
            ->through(function (DiagnosticFinding $finding) use ($builder, $user, $entitlements, $fixesEnabled): array {
                return $builder->serialize(
                    $finding,
                    $entitlements['one_click'],
                    $entitlements['bulk'],
                    $entitlements['guided'],
                    $this->canManageFindings($user, $finding),
                    $fixesEnabled,
                );
            });

        $counts = $this->tabCounts($user);
        $tenantRecords = app(TenantRecordQuery::class);
        $lastScanClean = $tenantRecords->runs($user)->where('status', 'analyzed')->exists()
            && ! $tenantRecords->findings($user)->currentIssues()->active()->exists();

        return Inertia::render('fix-center/index', [
            'issues' => $issues,
            'filters' => [
                'tab' => $tab,
            ],
            'counts' => $counts,
            'last_scan_clean' => $lastScanClean,
            'entitlements' => [
                'fixes_guided' => $entitlements['guided'],
                'fixes_one_click' => $entitlements['one_click'] && $fixesEnabled,
                'fixes_bulk' => $entitlements['bulk'] && $fixesEnabled,
                'bulk_max_items' => $entitlements['bulk_max_items'],
            ],
            'fixes_enabled' => $fixesEnabled,
            'fixes_unavailable_message' => $fixesEnabled
                ? null
                : SafeActionRegistry::FIXES_DISABLED_MESSAGE,
            'billing_plans_url' => route('billing.plans'),
        ]);
    }

    public function markStepDone(
        MarkGuidedFixStepRequest $request,
        DiagnosticFinding $finding,
        GuidedFixService $guided,
    ): RedirectResponse {
        $this->authorizeFinding($finding);
        $this->authorizeManage($finding);

        try {
            $guided->markStepDone(
                $finding,
                $request->user(),
                $request->integer('step_index'),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'step_index' => $exception->getMessage(),
            ]);
        }

        return back()->with('status', 'Step marked done.');
    }

    public function completeGuide(
        CompleteGuidedFixRequest $request,
        DiagnosticFinding $finding,
        GuidedFixService $guided,
    ): RedirectResponse {
        $this->authorizeFinding($finding);
        $this->authorizeManage($finding);

        try {
            $guided->requestRecheck($finding, $request->user());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'guide' => $exception->getMessage(),
            ]);
        }

        return back()->with('status', 'Re-check requested. This issue moves to Fixing until the next scan confirms it is gone.');
    }

    public function ignore(
        IgnoreFindingRequest $request,
        DiagnosticFinding $finding,
        FindingIgnoreService $ignoreService,
    ): RedirectResponse {
        $this->authorizeFinding($finding);
        $this->authorizeManage($finding);

        try {
            $ignoreService->ignore(
                $finding,
                $request->user(),
                $request->string('snooze')->toString(),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'snooze' => $exception->getMessage(),
            ]);
        }

        return back()->with('status', 'Issue ignored.');
    }

    public function unignore(
        DiagnosticFinding $finding,
        FindingIgnoreService $ignoreService,
    ): RedirectResponse {
        $this->authorizeFinding($finding);
        $this->authorizeManage($finding);

        $ignoreService->unignore($finding, Auth::user());

        return back()->with('status', 'Issue restored to Fix Center.');
    }

    /**
     * @return array{open: int, fixing: int, fixed: int, ignored: int}
     */
    private function tabCounts(?User $user): array
    {
        $base = app(TenantRecordQuery::class)->findings($user)->currentIssues();

        return [
            'open' => (clone $base)->notIgnored()->where('status', DiagnosticFinding::STATUS_OPEN)->count(),
            'fixing' => (clone $base)->notIgnored()->where('status', DiagnosticFinding::STATUS_IN_PROGRESS)->count(),
            'fixed' => app(FixedIssueCounter::class)->countForUser($user),
            'ignored' => (clone $base)->where(function ($ignored): void {
                $ignored->where(function ($snoozed): void {
                    $snoozed->ignored();
                })->orWhere('status', DiagnosticFinding::STATUS_ACCEPTED_RISK);
            })->count(),
        ];
    }

    /**
     * @return array{guided: bool, one_click: bool, bulk: bool, bulk_max_items: int}
     */
    private function workspaceEntitlements(?User $user): array
    {
        $guided = false;
        $oneClick = false;
        $bulk = false;
        $bulkMax = app(SafeActionRegistry::class)->bulkMaxItems();

        $tenantId = $user?->tenants()->first()?->id;
        if ($tenantId === null && $user !== null) {
            $integration = app(TenantRecordQuery::class)->integrations($user)->whereNotNull('tenant_id')->first();
            $tenantId = $integration?->tenant_id;
        }

        if ($tenantId !== null) {
            $entitlements = app(TenantEntitlementService::class);
            try {
                $entitlements->requireFeature($tenantId, 'fixes.guided');
                $guided = true;
            } catch (AuthorizationException) {
                $guided = false;
            }
            try {
                $entitlements->requireFeature($tenantId, 'fixes.one_click');
                $oneClick = true;
            } catch (AuthorizationException) {
                $oneClick = false;
            }
            try {
                $entitlements->requireFeature($tenantId, 'fixes.bulk');
                $bulk = true;
            } catch (AuthorizationException) {
                $bulk = false;
            }
        }

        return [
            'guided' => $guided,
            'one_click' => $oneClick,
            'bulk' => $bulk,
            'bulk_max_items' => $bulkMax,
        ];
    }

    private function authorizeFinding(DiagnosticFinding $finding): void
    {
        if (! app(TenantRecordQuery::class)->findings(Auth::user())->whereKey($finding->id)->exists()) {
            throw new NotFoundHttpException;
        }
    }

    private function authorizeManage(DiagnosticFinding $finding): void
    {
        if (! $this->canManageFindings(Auth::user(), $finding)) {
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
}
