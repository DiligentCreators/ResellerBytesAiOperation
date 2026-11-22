<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveSafeActionRequest;
use App\Http\Requests\StoreSafeActionRequest;
use App\Models\DiagnosticFinding;
use App\Models\SafeActionExecution;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\SafeActions\SafeActionExecutor;
use App\Services\SafeActions\SafeActionRegistry;
use App\Services\Tenancy\TenantEntitlementService;
use App\Services\Tenancy\TenantRecordQuery;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Support\AssertsTenantDiagnosticsAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SafeActionController extends Controller
{
    public function index(SafeActionRegistry $registry): Response
    {
        AssertsTenantDiagnosticsAccess::forIndex(Auth::user());
        $user = Auth::user();

        $executions = SafeActionExecution::query()
            ->whereIn('integration_id', app(TenantRecordQuery::class)->integrations($user)->pluck('id'))
            ->with(['integration:id,name', 'requester:id,name', 'approver:id,name'])
            ->latest('requested_at')
            ->paginate(20)
            ->through(fn (SafeActionExecution $execution): array => $this->serializeExecution($execution));

        $integrations = app(TenantRecordQuery::class)->integrations($user)
            ->orderBy('name')
            ->get(['id', 'name', 'tenant_id', 'status'])
            ->map(fn (WhmcsIntegration $integration): array => [
                'id' => $integration->id,
                'name' => $integration->name,
                'status' => $integration->status,
                'can_execute' => $this->canExecute($user, $integration),
            ]);

        return Inertia::render('safe-actions/index', [
            'executions' => $executions,
            'actions' => array_values($registry->all()),
            'integrations' => $integrations,
        ]);
    }

    public function show(SafeActionExecution $safeAction): Response
    {
        $this->authorizeExecution($safeAction);
        $safeAction->load([
            'integration:id,name,tenant_id',
            'finding:id,title,severity,status',
            'requester:id,name',
            'approver:id,name',
        ]);

        $definition = app(SafeActionRegistry::class)->get($safeAction->action_key);

        return Inertia::render('safe-actions/show', [
            'execution' => $this->serializeExecution($safeAction, detailed: true),
            'definition' => $definition,
            'can_approve' => $safeAction->status === SafeActionExecution::STATUS_PENDING_APPROVAL
                && $this->canExecute(Auth::user(), $safeAction->integration),
        ]);
    }

    public function store(
        StoreSafeActionRequest $request,
        SafeActionExecutor $executor,
        SafeActionRegistry $registry,
    ): RedirectResponse {
        $integration = WhmcsIntegration::query()->findOrFail($request->string('integration_id')->toString());
        $this->authorizeIntegration($integration);
        $this->authorizeExecute($integration);

        $definition = $registry->get($request->string('action_key')->toString());

        try {
            $this->requireEntitlement($integration, $definition['entitlement']);
        } catch (AuthorizationException $exception) {
            throw ValidationException::withMessages([
                'action_key' => $exception->getMessage(),
            ]);
        }

        $input = is_array($request->input('input')) ? $request->input('input') : [];
        $mode = is_string($input['mode'] ?? null) ? $input['mode'] : 'one_click';
        $targetCount = is_array($input['target_ids'] ?? null) ? count($input['target_ids']) : 0;
        if ($mode === 'bulk' || $targetCount > 1) {
            try {
                $this->requireEntitlement($integration, 'fixes.bulk');
            } catch (AuthorizationException $exception) {
                throw ValidationException::withMessages([
                    'input.target_ids' => $exception->getMessage(),
                ]);
            }
        }

        $finding = null;
        if ($request->filled('finding_id')) {
            $finding = DiagnosticFinding::query()->findOrFail($request->string('finding_id')->toString());
            if (! app(TenantRecordQuery::class)->findings(Auth::user())->whereKey($finding->id)->exists()) {
                throw new NotFoundHttpException;
            }
        }

        try {
            $execution = $executor->request(
                $integration,
                $request->user(),
                $definition['key'],
                $input,
                $finding,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['action_key' => $exception->getMessage()]);
        }

        return redirect()
            ->route('safe-actions.show', $execution)
            ->with('status', 'Safe action requested. Human approval is required before execution.');
    }

    public function approve(
        ApproveSafeActionRequest $request,
        SafeActionExecution $safeAction,
        SafeActionExecutor $executor,
        SafeActionRegistry $registry,
    ): RedirectResponse {
        $this->authorizeExecution($safeAction);
        $safeAction->loadMissing('integration');
        $this->authorizeExecute($safeAction->integration);

        $definition = $registry->get($safeAction->action_key);
        $this->requireEntitlement($safeAction->integration, $definition['entitlement']);

        if ($definition['requires_password_confirm']) {
            $confirmedAt = $request->session()->get('auth.password_confirmed_at');
            if (! is_numeric($confirmedAt) || (time() - (int) $confirmedAt) > 10800) {
                return redirect()
                    ->route('password.confirm')
                    ->with('status', 'Confirm your password before approving this safe action.');
            }

            if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
                throw ValidationException::withMessages([
                    'approval_reason' => 'Two-factor authentication is required to approve medium-risk safe actions.',
                ]);
            }
        }

        try {
            $execution = $executor->approve($safeAction, $request->user(), $request->string('approval_reason')->toString());
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'approval_reason' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('safe-actions.show', $execution)
            ->with('status', $this->approvalStatusMessage($execution));
    }

    private function approvalStatusMessage(SafeActionExecution $execution): string
    {
        if (in_array($execution->status, [
            SafeActionExecution::STATUS_COMPLETED,
            SafeActionExecution::STATUS_VERIFIED,
        ], true)) {
            return 'Safe action approved, executed, and audited.';
        }

        return 'Safe action approved and queued. The WHMCS addon will run it on its next check-in; the result appears here.';
    }

    private function authorizeExecution(SafeActionExecution $execution): void
    {
        $allowed = app(TenantRecordQuery::class)
            ->integrations(Auth::user())
            ->whereKey($execution->integration_id)
            ->exists();

        if (! $allowed) {
            throw new NotFoundHttpException;
        }
    }

    private function authorizeIntegration(WhmcsIntegration $integration): void
    {
        if (! app(TenantRecordQuery::class)->integrations(Auth::user())->whereKey($integration->id)->exists()) {
            throw new NotFoundHttpException;
        }
    }

    private function authorizeExecute(WhmcsIntegration $integration): void
    {
        if (! $this->canExecute(Auth::user(), $integration)) {
            abort(403);
        }
    }

    private function canExecute(?User $user, WhmcsIntegration $integration): bool
    {
        if ($user === null) {
            return false;
        }

        if ($integration->tenant_id === null) {
            return (int) $integration->owner_user_id === (int) $user->id;
        }

        try {
            app(TenantRoleAuthorization::class)->require($user, $integration->tenant_id, 'safe_actions.execute');

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function requireEntitlement(WhmcsIntegration $integration, string $feature): void
    {
        if ($integration->tenant_id === null) {
            return;
        }

        app(TenantEntitlementService::class)->requireFeature($integration->tenant_id, $feature);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeExecution(SafeActionExecution $execution, bool $detailed = false): array
    {
        $payload = [
            'id' => $execution->id,
            'action_key' => $execution->action_key,
            'mode' => $execution->mode,
            'batch_id' => $execution->batch_id,
            'issue_key' => $execution->issue_key,
            'items_total' => $execution->items_total,
            'items_succeeded' => $execution->items_succeeded,
            'minutes_saved' => $execution->minutes_saved,
            'status' => $execution->status,
            'risk_level' => $execution->risk_level,
            'requires_password_confirm' => $execution->requires_password_confirm,
            'requested_at' => $execution->requested_at?->toIso8601String(),
            'approved_at' => $execution->approved_at?->toIso8601String(),
            'executed_at' => $execution->executed_at?->toIso8601String(),
            'verified_at' => $execution->verified_at?->toIso8601String(),
            'integration_name' => $execution->integration?->name,
            'integration_id' => $execution->integration_id,
            'requester_name' => $execution->requester?->name,
            'approver_name' => $execution->approver?->name,
            'finding_id' => $execution->finding_id,
        ];

        if ($detailed) {
            $payload['approval_reason'] = $execution->approval_reason;
            $payload['input'] = $execution->input;
            $payload['preview'] = $execution->preview;
            $payload['target_ids'] = $execution->target_ids;
            $payload['result'] = $execution->result;
            $payload['before_evidence'] = $execution->before_evidence;
            $payload['after_evidence'] = $execution->after_evidence;
            $payload['finding_title'] = $execution->finding?->title;
        }

        return $payload;
    }
}
