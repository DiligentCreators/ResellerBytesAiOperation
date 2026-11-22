<?php

use App\Models\CentralStaffAccess;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\CentralStaffAuthorization;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

function prepareQaPlanPrices(): void
{
    (new PlanSeeder)->run();

    foreach (['starter', 'growth', 'business'] as $index => $code) {
        Plan::query()->where('code', $code)->firstOrFail()->forceFill([
            // Runtime-only test amounts — never committed as catalog defaults.
            'price_monthly_minor' => ($index + 1) * 1000,
            'price_yearly_minor' => ($index + 1) * 10000,
        ])->save();
    }
}

it('refuses to run outside local and testing environments', function () {
    $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qa-accounts-'.uniqid('', true).'.json';

    $previous = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $this->artisan('qa:provision-accounts', ['--output' => $output])
            ->expectsOutputToContain('refuses to run outside local/testing')
            ->assertFailed();
    } finally {
        app()->detectEnvironment(fn (): string => $previous);
    }

    expect(file_exists($output))->toBeFalse();
});

it('requires an output path', function () {
    $this->artisan('qa:provision-accounts')
        ->expectsOutputToContain('--output option is required')
        ->assertFailed();
});

it('provisioning writes credentials json without printing secrets', function () {
    prepareQaPlanPrices();

    $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qa-accounts-'.uniqid('', true).'.json';

    $exitCode = Artisan::call('qa:provision-accounts', ['--output' => $output]);
    $console = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($console)->toContain('Provisioned QA accounts.')
        ->and($console)->toContain('qa-staff@resellerbytes.test')
        ->and($console)->toContain('qa-starter@demo-tenant.test')
        ->and($console)->toContain('qa-growth@demo-tenant.test')
        ->and($console)->toContain('qa-pro@demo-tenant.test')
        ->and($console)->toContain('Output: '.$output)
        ->and(file_exists($output))->toBeTrue();

    /** @var list<array<string, mixed>> $rows */
    $rows = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
    expect($rows)->toHaveCount(4);

    foreach ($rows as $row) {
        expect($row)->toHaveKeys(['email', 'role', 'tier', 'tenant_id', 'password', 'totp_secret', 'recovery_codes'])
            ->and(strlen((string) $row['password']))->toBeGreaterThanOrEqual(32)
            ->and($row['totp_secret'])->not->toBeEmpty()
            ->and($row['recovery_codes'])->toBeArray()
            ->and($row['recovery_codes'])->not->toBeEmpty()
            ->and($console)->not->toContain((string) $row['password'])
            ->and($console)->not->toContain((string) $row['totp_secret']);

        foreach ($row['recovery_codes'] as $code) {
            expect($console)->not->toContain((string) $code);
        }
    }

    $staff = User::query()->where('email', 'qa-staff@resellerbytes.test')->sole();
    $staffAccess = CentralStaffAccess::query()->where('user_id', $staff->id)->sole();
    expect($staffAccess->permissions)->toBe(CentralStaffAuthorization::PERMISSIONS)
        ->and(app(CentralStaffAuthorization::class)->can($staff, 'billing.settings.manage'))->toBeTrue();

    $starter = collect($rows)->firstWhere('email', 'qa-starter@demo-tenant.test');
    $growth = collect($rows)->firstWhere('email', 'qa-growth@demo-tenant.test');
    $pro = collect($rows)->firstWhere('email', 'qa-pro@demo-tenant.test');

    expect($starter['role'])->toBe('owner')
        ->and($starter['tier'])->toBe('starter')
        ->and($growth['tier'])->toBe('growth')
        ->and($pro['tier'])->toBe('pro')
        ->and(TenantSubscription::query()->where('tenant_id', $starter['tenant_id'])->where('plan_code', 'starter')->where('status', 'active')->exists())->toBeTrue()
        ->and(TenantSubscription::query()->where('tenant_id', $growth['tenant_id'])->where('plan_code', 'growth')->where('status', 'active')->exists())->toBeTrue()
        ->and(TenantSubscription::query()->where('tenant_id', $pro['tenant_id'])->where('plan_code', 'business')->where('status', 'active')->exists())->toBeTrue();

    $starterUser = User::query()->where('email', 'qa-starter@demo-tenant.test')->sole();
    expect(Hash::check((string) $starter['password'], $starterUser->password))->toBeTrue()
        ->and($starterUser->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and(TenantMembership::query()->where([
            'tenant_id' => $starter['tenant_id'],
            'user_id' => $starterUser->id,
            'role' => TenantMembership::ROLE_OWNER,
            'status' => TenantMembership::STATUS_ACTIVE,
        ])->exists())->toBeTrue();

    $starterPlan = Plan::query()->where('code', 'starter')->sole();
    $starterSub = TenantSubscription::query()->where('tenant_id', $starter['tenant_id'])->where('status', 'active')->sole();
    expect($starterSub->entitlements)->toBe($starterPlan->entitlements)
        ->and($starterSub->unit_amount_minor)->toBe($starterPlan->price_monthly_minor);

    @unlink($output);
});

it('rotates passwords and secrets on re-run without creating duplicate tenants', function () {
    prepareQaPlanPrices();

    $outputA = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qa-accounts-a-'.uniqid('', true).'.json';
    $outputB = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qa-accounts-b-'.uniqid('', true).'.json';

    $this->artisan('qa:provision-accounts', ['--output' => $outputA])->assertSuccessful();
    $first = json_decode((string) file_get_contents($outputA), true, 512, JSON_THROW_ON_ERROR);

    $this->artisan('qa:provision-accounts', ['--output' => $outputB])->assertSuccessful();
    $second = json_decode((string) file_get_contents($outputB), true, 512, JSON_THROW_ON_ERROR);

    expect(User::query()->whereIn('email', [
        'qa-staff@resellerbytes.test',
        'qa-starter@demo-tenant.test',
        'qa-growth@demo-tenant.test',
        'qa-pro@demo-tenant.test',
    ])->count())->toBe(4)
        ->and(Tenant::query()->whereIn('slug', ['qa-starter', 'qa-growth', 'qa-pro'])->count())->toBe(3);

    $firstStarter = collect($first)->firstWhere('email', 'qa-starter@demo-tenant.test');
    $secondStarter = collect($second)->firstWhere('email', 'qa-starter@demo-tenant.test');

    expect($firstStarter['password'])->not->toBe($secondStarter['password'])
        ->and($firstStarter['totp_secret'])->not->toBe($secondStarter['totp_secret'])
        ->and($firstStarter['tenant_id'])->toBe($secondStarter['tenant_id']);

    $user = User::query()->where('email', 'qa-starter@demo-tenant.test')->sole();
    expect(Hash::check((string) $secondStarter['password'], $user->password))->toBeTrue()
        ->and(decrypt($user->two_factor_secret))->toBe($secondStarter['totp_secret']);

    @unlink($outputA);
    @unlink($outputB);
});
