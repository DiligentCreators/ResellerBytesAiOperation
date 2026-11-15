<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

function membershipLifecycleFixture(int $memberLimit = 10): array
{
    $tenant = Tenant::create([
        'name' => 'Membership Lifecycle Workspace',
        'slug' => 'membership-lifecycle-workspace',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $invitee = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    $tenant->memberships()->create([
        'user_id' => $admin->id,
        'role' => TenantMembership::ROLE_ADMIN,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'membership-test-plan',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => ['limits' => ['members' => $memberLimit]],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    return [$tenant, $owner, $admin, $invitee];
}

it('supports audited invitation acceptance role changes suspension and revocation', function () {
    [$tenant, $owner, $admin, $invitee] = membershipLifecycleFixture();
    $lifecycle = app(TenantMembershipLifecycle::class);

    $invitation = $lifecycle->invite($admin, $tenant->id, $invitee->email, TenantMembership::ROLE_OPERATOR, '192.0.2.10');
    expect($invitation->status)->toBe(TenantMembership::STATUS_INVITED)
        ->and($invitation->role)->toBe(TenantMembership::ROLE_OPERATOR);

    $accepted = $lifecycle->accept($invitee, $tenant->id, '192.0.2.11');
    expect($accepted->status)->toBe(TenantMembership::STATUS_ACTIVE)
        ->and($accepted->accepted_at)->not->toBeNull();

    $changed = $lifecycle->changeRole($admin, $tenant->id, $accepted->id, TenantMembership::ROLE_BILLING);
    expect($changed->role)->toBe(TenantMembership::ROLE_BILLING);

    $suspended = $lifecycle->suspend($admin, $tenant->id, $accepted->id);
    expect($suspended->status)->toBe(TenantMembership::STATUS_SUSPENDED);

    $revoked = $lifecycle->revoke($admin, $tenant->id, $accepted->id);
    expect($revoked->status)->toBe(TenantMembership::STATUS_REVOKED);

    expect(\Illuminate\Support\Facades\DB::table('audit_events')
        ->where('tenant_id', $tenant->id)
        ->whereIn('event_type', [
            'tenant.membership.invited',
            'tenant.membership.accepted',
            'tenant.membership.role_changed',
            'tenant.membership.suspended',
            'tenant.membership.revoked',
        ])->count())->toBe(5);
});

it('prevents invitation by users without member administration capability', function () {
    [$tenant, $owner, $admin, $invitee] = membershipLifecycleFixture();
    $viewer = User::factory()->create();
    $tenant->memberships()->create([
        'user_id' => $viewer->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    expect(fn () => app(TenantMembershipLifecycle::class)->invite(
        $viewer,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_OPERATOR,
    ))->toThrow(AuthorizationException::class);
});

it('does not permit member administration to demote suspend or revoke an owner', function () {
    [$tenant, $owner, $admin, $invitee] = membershipLifecycleFixture();
    $ownerMembership = $tenant->memberships()->where('user_id', $owner->id)->firstOrFail();
    $lifecycle = app(TenantMembershipLifecycle::class);

    expect(fn () => $lifecycle->changeRole($admin, $tenant->id, $ownerMembership->id, TenantMembership::ROLE_VIEWER))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $lifecycle->suspend($admin, $tenant->id, $ownerMembership->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $lifecycle->revoke($admin, $tenant->id, $ownerMembership->id))
        ->toThrow(AuthorizationException::class);

    expect($ownerMembership->fresh()->status)->toBe(TenantMembership::STATUS_ACTIVE)
        ->and($ownerMembership->fresh()->role)->toBe(TenantMembership::ROLE_OWNER);
});

it('requires a verified existing account and a non-owner invitation role', function () {
    [$tenant, $owner, $admin, $invitee] = membershipLifecycleFixture();
    $unverified = User::factory()->create(['email_verified_at' => null]);

    expect(fn () => app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $unverified->email,
        TenantMembership::ROLE_OPERATOR,
    ))->toThrow(ValidationException::class);

    expect(fn () => app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_OWNER,
    ))->toThrow(ValidationException::class);
});


it('reissues a revoked membership using its existing unique tenant-user row', function () {
    [$tenant, $owner, $admin, $invitee] = membershipLifecycleFixture();
    $lifecycle = app(TenantMembershipLifecycle::class);
    $first = $lifecycle->invite($admin, $tenant->id, $invitee->email, TenantMembership::ROLE_VIEWER);
    $lifecycle->accept($invitee, $tenant->id);
    $lifecycle->revoke($admin, $tenant->id, $first->id);

    $second = $lifecycle->invite($admin, $tenant->id, $invitee->email, TenantMembership::ROLE_OPERATOR);

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe(TenantMembership::STATUS_INVITED)
        ->and($second->role)->toBe(TenantMembership::ROLE_OPERATOR)
        ->and($tenant->memberships()->where('user_id', $invitee->id)->count())->toBe(1);
});

it('reissues a pending invitation without creating a duplicate membership', function () {
    [$tenant, $owner, $admin, $invitee] = membershipLifecycleFixture();
    $lifecycle = app(TenantMembershipLifecycle::class);
    $first = $lifecycle->invite($admin, $tenant->id, $invitee->email, TenantMembership::ROLE_VIEWER);
    $resent = $lifecycle->invite($owner, $tenant->id, $invitee->email, TenantMembership::ROLE_OPERATOR);

    expect($resent->id)->toBe($first->id)
        ->and($resent->status)->toBe(TenantMembership::STATUS_INVITED)
        ->and($resent->role)->toBe(TenantMembership::ROLE_OPERATOR)
        ->and($tenant->memberships()->where('user_id', $invitee->id)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('tenant_id', $tenant->id)->where('event_type', 'tenant.membership.invited')->count())->toBe(2);
});

it('enforces tenant member seats while allowing pending invitation resend in the same seat', function () {
    [$tenant, $owner, $admin, $invitee] = membershipLifecycleFixture(3);
    $lifecycle = app(TenantMembershipLifecycle::class);
    $first = $lifecycle->invite($admin, $tenant->id, $invitee->email, TenantMembership::ROLE_VIEWER);
    $resent = $lifecycle->invite($owner, $tenant->id, $invitee->email, TenantMembership::ROLE_OPERATOR);

    expect($resent->id)->toBe($first->id)
        ->and($resent->role)->toBe(TenantMembership::ROLE_OPERATOR);

    $another = User::factory()->create(['email_verified_at' => now()]);
    expect(fn () => $lifecycle->invite($admin, $tenant->id, $another->email, TenantMembership::ROLE_VIEWER))
        ->toThrow(AuthorizationException::class, 'member limit has been reached');
});
