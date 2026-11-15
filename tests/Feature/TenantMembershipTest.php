<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Models\DiagnosticRun;
use App\Models\DiagnosticFinding;
use App\Services\Tenancy\TenantRecordQuery;
use App\Services\Tenancy\TenantAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('creates an isolated tenant and explicit role-based memberships without implicit access', function () {
    $creator = User::factory()->withTwoFactor()->create();
    $member = User::factory()->withTwoFactor()->create();
    $outsider = User::factory()->withTwoFactor()->create();

    $tenant = Tenant::create([
        'name' => 'Approved Workspace',
        'slug' => 'approved-workspace',
        'status' => Tenant::STATUS_PROVISIONING,
        'created_by_user_id' => $creator->id,
    ]);

    $membership = $tenant->memberships()->create([
        'user_id' => $member->id,
        'role' => TenantMembership::ROLE_OPERATOR,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    expect($membership->isActive())->toBeTrue()
        ->and($membership->hasAnyRole([TenantMembership::ROLE_OPERATOR, TenantMembership::ROLE_ADMIN]))->toBeTrue()
        ->and($membership->hasAnyRole([TenantMembership::ROLE_BILLING]))->toBeFalse()
        ->and($tenant->memberships()->where('user_id', $outsider->id)->exists())->toBeFalse()
        ->and($member->tenants()->whereKey($tenant->id)->exists())->toBeTrue()
        ->and($outsider->tenants()->whereKey($tenant->id)->exists())->toBeFalse();
});

it('treats suspended and revoked memberships as non-authorizing', function () {
    $user = User::factory()->withTwoFactor()->create();
    $tenant = Tenant::create([
        'name' => 'Membership State Workspace',
        'slug' => 'membership-state-workspace',
        'status' => Tenant::STATUS_ACTIVE,
    ]);

    $membership = $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_SUSPENDED,
    ]);

    expect($membership->isActive())->toBeFalse()
        ->and($membership->hasAnyRole([TenantMembership::ROLE_OWNER]))->toBeFalse();

    $membership->forceFill(['status' => TenantMembership::STATUS_REVOKED])->save();

    expect($membership->fresh()->isActive())->toBeFalse();
});

it('supports nullable tenant assignment for legacy integrations without guessing a tenant', function () {
    $integration = WhmcsIntegration::create([
        'name' => 'Quarantined legacy integration',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    expect($integration->tenant_id)->toBeNull()
        ->and($integration->tenant()->exists())->toBeFalse();
});


it('requires an active tenant and active membership before granting tenant access', function () {
    $user = User::factory()->withTwoFactor()->create();
    $tenant = Tenant::create([
        'name' => 'Access Policy Workspace',
        'slug' => 'access-policy-workspace',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $access = app(TenantAccess::class);

    expect(fn () => $access->requireMembership($user, $tenant->id))
        ->toThrow(ModelNotFoundException::class);

    $membership = $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
    ]);

    expect($access->requireMembership($user, $tenant->id)->id)->toBe($membership->id);
    expect(fn () => $access->requireMembership($user, $tenant->id, [TenantMembership::ROLE_ADMIN]))
        ->toThrow(AuthorizationException::class);

    $tenant->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
    expect(fn () => $access->requireMembership($user, $tenant->id))
        ->toThrow(ModelNotFoundException::class);
});


it('uses active tenant membership instead of historical integration ownership for tenant records', function () {
    $historicalOwner = User::factory()->withTwoFactor()->create();
    $member = User::factory()->withTwoFactor()->create();
    $outsider = User::factory()->withTwoFactor()->create();

    $tenant = Tenant::create([
        'name' => 'Shared Workspace',
        'slug' => 'shared-workspace',
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $historicalOwner->id,
    ]);
    $membership = $tenant->memberships()->create([
        'user_id' => $member->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    \App\Models\TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'shared-workspace-test',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => ['limits' => ['diagnostics_per_period' => 10]],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $historicalOwner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Shared tenant integration',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);
    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Illuminate\Support\Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('b', 64),
    ]);
    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'fingerprint' => hash('sha256', 'shared-tenant-finding'),
        'category' => 'security',
        'severity' => 'high',
        'title' => 'Shared tenant finding',
        'summary' => 'Visible only to active tenant members.',
        'evidence' => ['source' => 'tenant'],
        'status' => 'open',
    ]);

    $records = app(TenantRecordQuery::class);

    expect($records->integrations($member)->whereKey($integration->id)->exists())->toBeTrue()
        ->and($records->runs($member)->whereKey($run->id)->exists())->toBeTrue()
        ->and($records->findings($member)->whereKey($finding->id)->exists())->toBeTrue()
        ->and($records->integrations($historicalOwner)->whereKey($integration->id)->exists())->toBeFalse()
        ->and($records->runs($outsider)->whereKey($run->id)->exists())->toBeFalse();

    $membership->forceFill(['status' => TenantMembership::STATUS_REVOKED])->save();

    expect($records->integrations($member)->whereKey($integration->id)->exists())->toBeFalse()
        ->and($records->findings($member)->whereKey($finding->id)->exists())->toBeFalse();
});
