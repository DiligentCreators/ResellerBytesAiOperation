<?php

namespace App\Http\Controllers;

use App\Models\CentralStaffAccess;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use App\Services\CentralStaffAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CentralStaffController extends Controller
{
    private function requireSuperAdmin(Request $request, CentralStaffAuthorization $authorization): void
    {
        abort_unless($authorization->isSuperAdmin($request->user()), 403);
    }

    public function index(Request $request, CentralStaffAuthorization $authorization): Response
    {
        $this->requireSuperAdmin($request, $authorization);

        return Inertia::render('platform/staff/index', [
            'permissions' => CentralStaffAuthorization::PERMISSIONS,
            'staff' => CentralStaffAccess::query()->with('user:id,name,email')->orderBy('id')->get()
                ->map(fn (CentralStaffAccess $access): array => [
                    'id' => $access->id,
                    'user_id' => $access->user_id,
                    'name' => $access->user->name,
                    'email' => $access->user->email,
                    'permissions' => $access->permissions,
                ]),
        ]);
    }

    public function store(Request $request, CentralStaffAuthorization $authorization, AuditEventWriter $audit): RedirectResponse
    {
        $this->requireSuperAdmin($request, $authorization);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:14', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', Rule::in(CentralStaffAuthorization::PERMISSIONS)],
        ]);

        DB::transaction(function () use ($data, $request, $audit): void {
            $staff = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'email_verified_at' => now(),
            ]);
            $access = CentralStaffAccess::query()->create([
                'user_id' => $staff->id,
                'permissions' => array_values(array_unique($data['permissions'] ?? [])),
                'granted_by_user_id' => $request->user()->id,
            ]);
            $audit->append([
                'actor_type' => 'user', 'actor_id' => (string) $request->user()->id,
                'event_type' => 'central_staff.created', 'subject_type' => User::class,
                'subject_id' => (string) $staff->id,
                'metadata' => ['permissions' => $access->permissions],
                'ip_address' => $request->ip(), 'occurred_at' => now(),
            ]);
        });

        return back()->with('status', 'Central staff account created.');
    }

    public function update(Request $request, CentralStaffAccess $staff, CentralStaffAuthorization $authorization, AuditEventWriter $audit): RedirectResponse
    {
        $this->requireSuperAdmin($request, $authorization);
        abort_if($authorization->isSuperAdmin($staff->user()->firstOrFail()), 422, 'The super admin cannot be modified as staff.');
        $data = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', Rule::in(CentralStaffAuthorization::PERMISSIONS)],
        ]);

        DB::transaction(function () use ($data, $request, $staff, $audit): void {
            $before = $staff->permissions;
            $staff->forceFill([
                'permissions' => array_values(array_unique($data['permissions'] ?? [])),
                'granted_by_user_id' => $request->user()->id,
            ])->save();
            $audit->append([
                'actor_type' => 'user', 'actor_id' => (string) $request->user()->id,
                'event_type' => 'central_staff.permissions_changed', 'subject_type' => User::class,
                'subject_id' => (string) $staff->user_id,
                'metadata' => ['before' => $before, 'after' => $staff->permissions],
                'ip_address' => $request->ip(), 'occurred_at' => now(),
            ]);
        });

        return back()->with('status', 'Central staff permissions updated.');
    }

    public function destroy(Request $request, CentralStaffAccess $staff, CentralStaffAuthorization $authorization, AuditEventWriter $audit): RedirectResponse
    {
        $this->requireSuperAdmin($request, $authorization);
        abort_if($authorization->isSuperAdmin($staff->user()->firstOrFail()), 422, 'The super admin cannot be deleted.');
        DB::transaction(function () use ($request, $staff, $audit): void {
            $user = $staff->user()->firstOrFail();
            $audit->append([
                'actor_type' => 'user', 'actor_id' => (string) $request->user()->id,
                'event_type' => 'central_staff.deleted', 'subject_type' => User::class,
                'subject_id' => (string) $user->id, 'metadata' => ['email' => $user->email],
                'ip_address' => $request->ip(), 'occurred_at' => now(),
            ]);
            $staff->delete();
            $user->delete();
        });

        return back()->with('status', 'Central staff account deleted.');
    }
}
