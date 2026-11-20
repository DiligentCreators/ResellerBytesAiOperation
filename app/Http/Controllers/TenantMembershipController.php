<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipLifecycle;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TenantMembershipController extends Controller
{
    public function index(Request $request, Tenant $tenant, TenantRoleAuthorization $authorization): InertiaResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'members.manage');

        $memberships = $tenant->memberships()->with('user:id,name,email')->orderBy('created_at')->paginate(50);

        $payload = [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'memberships' => $memberships,
            'roles' => [
                TenantMembership::ROLE_ADMIN,
                TenantMembership::ROLE_OPERATOR,
                TenantMembership::ROLE_VIEWER,
                TenantMembership::ROLE_BILLING,
            ],
            'status' => $request->session()->get('status'),
        ];

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['memberships' => $memberships]);
        }

        return Inertia::render('tenants/memberships', $payload);
    }

    public function invite(Request $request, Tenant $tenant, TenantMembershipLifecycle $lifecycle): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in([TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_OPERATOR, TenantMembership::ROLE_VIEWER, TenantMembership::ROLE_BILLING])],
        ]);
        /** @var User $user */
        $user = $request->user();
        $membership = $lifecycle->invite($user, $tenant->id, $data['email'], $data['role'], $request->ip());

        if (! $request->header('X-Inertia')) {
            return response()->json(['membership' => $membership->load('user:id,name,email'), 'email_delivery' => 'queued'], 201);
        }

        return back()->with('status', 'Invitation sent.');
    }

    public function showInvitation(Request $request, string $tenantId, string $membershipId, TenantMembershipLifecycle $lifecycle): Response
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $lifecycle->invitationFor($user, $tenantId, $membershipId);

        return response()->view('tenants.membership-invitation', ['membership' => $membership->load('user')]);
    }

    public function accept(Request $request, string $tenantId, TenantMembershipLifecycle $lifecycle): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $lifecycle->accept($user, $tenantId, $request->ip());

        if ($request->expectsJson()) {
            return response()->json(['membership' => $membership]);
        }

        return redirect()->route('dashboard')->with('status', 'Workspace invitation accepted.');
    }

    public function updateRole(Request $request, Tenant $tenant, TenantMembership $membership, TenantMembershipLifecycle $lifecycle): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in([TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_OPERATOR, TenantMembership::ROLE_VIEWER, TenantMembership::ROLE_BILLING])],
        ]);
        /** @var User $user */
        $user = $request->user();
        $updated = $lifecycle->changeRole($user, $tenant->id, $membership->id, $data['role'], $request->ip());

        if (! $request->header('X-Inertia')) {
            return response()->json(['membership' => $updated]);
        }

        return back()->with('status', 'Member role updated.');
    }

    public function suspend(Request $request, Tenant $tenant, TenantMembership $membership, TenantMembershipLifecycle $lifecycle): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $lifecycle->suspend($user, $tenant->id, $membership->id, $request->ip());

        if (! $request->header('X-Inertia')) {
            return response()->json(['membership' => $updated]);
        }

        return back()->with('status', 'Member suspended.');
    }

    public function revoke(Request $request, Tenant $tenant, TenantMembership $membership, TenantMembershipLifecycle $lifecycle): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $lifecycle->revoke($user, $tenant->id, $membership->id, $request->ip());

        if (! $request->header('X-Inertia')) {
            return response()->json(['membership' => $updated]);
        }

        return back()->with('status', 'Member revoked.');
    }
}
