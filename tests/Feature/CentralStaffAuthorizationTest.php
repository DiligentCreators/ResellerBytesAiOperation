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
        'password' => 'long-and-random-password-123',
        'permissions' => ['billing', 'support'],
    ])->assertRedirect();

    $created = User::query()->where('email', 'billing-operator@example.test')->firstOrFail();
    $access = CentralStaffAccess::query()->where('user_id', $created->id)->firstOrFail();
    expect($access->permissions)->toBe(['billing', 'support']);

    $this->actingAs($owner)->put('/platform/staff/'.$access->id, [
        'permissions' => ['tenants', 'audit'],
    ])->assertRedirect();

    expect($access->fresh()->permissions)->toBe(['tenants', 'audit'])
        ->and(DB::table('audit_events')->where('event_type', 'central_staff.created')->exists())->toBeTrue()
        ->and(DB::table('audit_events')->where('event_type', 'central_staff.permissions_changed')->exists())->toBeTrue();
});

it('prevents staff from escalating their own permissions and prevents staff deletion of the super admin', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $staff = User::factory()->withTwoFactor()->create();
    $access = CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['tenants'],
        'granted_by_user_id' => $owner->id,
    ]);

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
