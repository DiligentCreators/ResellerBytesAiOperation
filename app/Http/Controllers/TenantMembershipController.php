<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipLifecycle;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json(['membership' => $membership->load('user:id,name,email')], 201);
    }

    public function accept(Request $request, Tenant $tenant, TenantMembershipLifecycle $lifecycle): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $lifecycle->accept($user, $tenant->id, $request->ip());

        return response()->json(['membership' => $membership]);
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
