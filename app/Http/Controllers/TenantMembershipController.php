<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Jobs\SendTenantMembershipInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipLifecycle;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TenantMembershipController extends Controller
{
    public function index(Request $request, Tenant $tenant, TenantRoleAuthorization $authorization): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'members.manage');

        return response()->json([
            'memberships' => $tenant->memberships()->with('user:id,name,email')->orderBy('created_at')->paginate(50),
        ]);
    }

    public function invite(Request $request, Tenant $tenant, TenantMembershipLifecycle $lifecycle): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in([TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_OPERATOR, TenantMembership::ROLE_VIEWER, TenantMembership::ROLE_BILLING])],
        ]);
        /** @var User $user */
        $user = $request->user();
        $membership = $lifecycle->invite($user, $tenant->id, $data['email'], $data['role'], $request->ip());
        SendTenantMembershipInvitation::dispatch($tenant->id, $membership->id, (int) $membership->user_id)->afterCommit();

        return response()->json(['membership' => $membership->load('user:id,name,email'), 'email_delivery' => 'queued'], 201);
    }

    public function showInvitation(Request $request, string $tenantId, string $membershipId, TenantMembershipLifecycle $lifecycle): Response
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $lifecycle->invitationFor($user, $tenantId, $membershipId);

        return response()->view('tenants.membership-invitation', ['membership' => $membership->load('user')]);
    }

    public function accept(Request $request, string $tenantId, TenantMembershipLifecycle $lifecycle): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $lifecycle->accept($user, $tenantId, $request->ip());

        if ($request->expectsJson()) {
            return response()->json(['membership' => $membership]);
        }

        return redirect()->route('dashboard')->with('status', 'Workspace invitation accepted.');
    }

    public function updateRole(Request $request, Tenant $tenant, TenantMembership $membership, TenantMembershipLifecycle $lifecycle): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in([TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_OPERATOR, TenantMembership::ROLE_VIEWER, TenantMembership::ROLE_BILLING])],
        ]);
        /** @var User $user */
        $user = $request->user();
        $updated = $lifecycle->changeRole($user, $tenant->id, $membership->id, $data['role'], $request->ip());

        return response()->json(['membership' => $updated]);
    }

    public function suspend(Request $request, Tenant $tenant, TenantMembership $membership, TenantMembershipLifecycle $lifecycle): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        return response()->json(['membership' => $lifecycle->suspend($user, $tenant->id, $membership->id, $request->ip())]);
    }

    public function revoke(Request $request, Tenant $tenant, TenantMembership $membership, TenantMembershipLifecycle $lifecycle): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        return response()->json(['membership' => $lifecycle->revoke($user, $tenant->id, $membership->id, $request->ip())]);
    }
}
