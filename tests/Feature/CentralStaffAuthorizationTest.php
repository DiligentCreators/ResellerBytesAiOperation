<?php

use App\Models\CentralStaffAccess;
use App\Models\User;
use App\Services\CentralStaffAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['operations.owner_email' => 'owner@example.test']);
});

it('gives the configured super admin every central permission without a staff row', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);

    expect(app(CentralStaffAuthorization::class)->isSuperAdmin($owner))->toBeTrue()
        ->and(app(CentralStaffAuthorization::class)->permissions($owner))
        ->toBe(CentralStaffAuthorization::PERMISSIONS)
        ->and(CentralStaffAccess::query()->where('user_id', $owner->id)->exists())->toBeFalse();
});

it('authorizes only explicitly assigned central permissions for staff', function () {
    $staff = User::factory()->withTwoFactor()->create();
    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['tenants', 'audit'],
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    $authorization = app(CentralStaffAuthorization::class);
    expect($authorization->can($staff, 'tenants'))->toBeTrue()
        ->and($authorization->can($staff, 'audit'))->toBeTrue()
        ->and($authorization->can($staff, 'billing'))->toBeFalse()
        ->and($authorization->can($staff, 'unknown'))->toBeFalse();
});

it('allows only the configured super admin to create staff and change permissions with audit evidence', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $staff = User::factory()->withTwoFactor()->create();
    $staffAccess = CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['tenants'],
        'granted_by_user_id' => $owner->id,
    ]);
    $this->actingAs($staff)->put('/platform/staff/'.$staffAccess->id, ['permissions' => ['tenants', 'settings']])->assertForbidden();

    $this->actingAs($owner)->post('/platform/staff', [
        'name' => 'Billing Operator',
        'email' => 'billing-operator@example.test',
        'password' => 'Long-and-random-password-123!',
        'permissions' => ['billing', 'support'],
    ])->assertRedirect();

    $created = User::query()->where('email', 'billing-operator@example.test')->firstOrFail();
    $access = CentralStaffAccess::query()->where('user_id', $created->id)->firstOrFail();
    expect($access->permissions)->toBe(['billing', 'support'])
        ->and(app(CentralStaffAuthorization::class)->can($created, 'billing'))->toBeTrue()
        ->and(app(CentralStaffAuthorization::class)->can($created, 'support'))->toBeTrue();

    $this->actingAs($owner)->put('/platform/staff/'.$access->id, [
        'permissions' => ['tenants', 'audit'],
    ])->assertRedirect();

    $permissionEvent = DB::table('audit_events')->where('event_type', 'central_staff.permissions_changed')->first();
    expect($access->fresh()->permissions)->toBe(['tenants', 'audit'])
        ->and(DB::table('audit_events')->where('event_type', 'central_staff.created')->exists())->toBeTrue()
        ->and($permissionEvent)->not->toBeNull()
        ->and(json_decode($permissionEvent->metadata, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['before' => ['billing', 'support'], 'after' => ['tenants', 'audit']]);
});

it('prevents staff from escalating their own permissions and prevents staff deletion of the super admin', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $staff = User::factory()->withTwoFactor()->create();
    $access = CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['tenants'],
        'granted_by_user_id' => $owner->id,
    ]);

    $this->actingAs($staff)->get('/platform/mail-settings')->assertForbidden();

    $this->actingAs($staff)->put('/platform/staff/'.$access->id, [
        'permissions' => CentralStaffAuthorization::PERMISSIONS,
    ])->assertForbidden();

    $this->actingAs($staff)->delete('/platform/staff/'.$access->id)->assertForbidden();
    expect($access->fresh()->permissions)->toBe(['tenants'])
        ->and(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

it('protects the configured super admin from self-deletion and identity changes', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $this->actingAs($owner)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
    $this->actingAs($owner)->patch('/settings/profile', [
        'name' => $owner->name,
        'email' => 'replacement@example.test',
    ])->assertSessionHasErrors('email');

    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

it('allows staff to use only the central settings area explicitly granted to them', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $staff = User::factory()->withTwoFactor()->create();
    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['settings'],
        'granted_by_user_id' => $owner->id,
    ]);

    $this->actingAs($staff)->get('/platform/mail-settings')->assertOk();
    $this->actingAs($staff)->get('/platform/staff')->assertForbidden();
});

it('lets only the super admin remove a staff account and records the removal', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $staff = User::factory()->withTwoFactor()->create();
    $access = CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['support'],
        'granted_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)->delete('/platform/staff/'.$access->id)->assertRedirect();

    expect(User::query()->whereKey($staff->id)->exists())->toBeFalse()
        ->and(CentralStaffAccess::query()->whereKey($access->id)->exists())->toBeFalse()
        ->and(DB::table('audit_events')->where('event_type', 'central_staff.deleted')
            ->where('subject_id', (string) $staff->id)->exists())->toBeTrue();
});

it('rolls back a staff permission change when its audit event cannot be appended', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $staff = User::factory()->withTwoFactor()->create();
    $access = CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['tenants'],
        'granted_by_user_id' => $owner->id,
    ]);

    $writer = Mockery::mock(\App\Services\Audit\AuditEventWriter::class);
    $writer->shouldReceive('append')->once()->andThrow(new RuntimeException('audit_write_failed'));
    app()->instance(\App\Services\Audit\AuditEventWriter::class, $writer);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)->put('/platform/staff/'.$access->id, [
        'permissions' => ['billing', 'support'],
    ]))->toThrow(RuntimeException::class, 'audit_write_failed');

    expect($access->fresh()->permissions)->toBe(['tenants']);
});


it('never allows the configured super admin to be modified or deleted through staff administration', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $staleAccess = CentralStaffAccess::query()->create([
        'user_id' => $owner->id,
        'permissions' => ['tenants'],
        'granted_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)->put('/platform/staff/'.$staleAccess->id, [
        'permissions' => ['billing'],
    ])->assertUnprocessable();

    $this->actingAs($owner)->delete('/platform/staff/'.$staleAccess->id)->assertUnprocessable();

    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue()
        ->and($staleAccess->fresh()->permissions)->toBe(['tenants'])
        ->and(CentralStaffAccess::query()->whereKey($staleAccess->id)->exists())->toBeTrue();
});
