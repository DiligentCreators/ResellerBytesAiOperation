<?php

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

uses(RefreshDatabase::class);

it('seeds the platform super admin, demo tenant owner, workspace, and subscription', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(DemoAccountsSeeder::class);

    $platform = User::query()->where('email', 'admin@resellerbytes.test')->sole();
    $customer = User::query()->where('email', 'customer@demo-tenant.test')->sole();
    $tenant = Tenant::query()->where('slug', 'demo-customer')->sole();
    $password = (string) config('operations.demo_accounts.password');

    expect($platform->name)->toBe('Platform Super Admin')
        ->and($platform->email_verified_at)->not->toBeNull()
        ->and($platform->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and(Hash::check($password, $platform->password))->toBeTrue()
        ->and($customer->name)->toBe('Demo Tenant Owner')
        ->and($customer->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and($tenant->name)->toBe('Demo Customer Workspace')
        ->and($tenant->status)->toBe(Tenant::STATUS_ACTIVE)
        ->and(TenantMembership::query()->where([
            'tenant_id' => $tenant->id,
            'user_id' => $customer->id,
            'role' => TenantMembership::ROLE_OWNER,
            'status' => TenantMembership::STATUS_ACTIVE,
        ])->exists())->toBeTrue()
        ->and(TenantSubscription::query()->where([
            'tenant_id' => $tenant->id,
            'plan_code' => 'growth',
            'status' => 'active',
        ])->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'operations_user.created')->count())->toBe(1);
});

it('is idempotent when demo accounts already exist', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(DemoAccountsSeeder::class);
    $this->seed(DemoAccountsSeeder::class);

    expect(User::query()->count())->toBe(2)
        ->and(Tenant::query()->count())->toBe(1)
        ->and(TenantMembership::query()->count())->toBe(1)
        ->and(TenantSubscription::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'operations_user.created')->count())->toBe(1);
});

it('fails closed when the demo accounts password is missing outside production', function () {
    config(['operations.demo_accounts.password' => '']);

    expect(fn () => (new DemoAccountsSeeder)->run())
        ->toThrow(RuntimeException::class, 'DEMO_ACCOUNTS_PASSWORD / operations.demo_accounts.password is required outside production.');
});
