<?php

namespace App\Console\Commands;

use App\Models\CentralStaffAccess;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\CentralStaffAuthorization;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\RecoveryCode;
use RuntimeException;
use Throwable;

class ProvisionQaAccountsCommand extends Command
{
    protected $signature = 'qa:provision-accounts
                            {--output= : Absolute path to write the credentials JSON file (required)}';

    protected $description = 'Idempotently provision local QA staff and tier workspace owners (never prints secrets)';

    /**
     * @var list<array{email: string, role: string, tier: string|null, slug: string|null, plan_code: string|null, name: string}>
     */
    private const ACCOUNTS = [
        [
            'email' => 'qa-staff@resellerbytes.test',
            'role' => 'staff',
            'tier' => null,
            'slug' => null,
            'plan_code' => null,
            'name' => 'QA Platform Staff',
        ],
        [
            'email' => 'qa-starter@demo-tenant.test',
            'role' => 'owner',
            'tier' => 'starter',
            'slug' => 'qa-starter',
            'plan_code' => 'starter',
            'name' => 'QA Starter Owner',
        ],
        [
            'email' => 'qa-growth@demo-tenant.test',
            'role' => 'owner',
            'tier' => 'growth',
            'slug' => 'qa-growth',
            'plan_code' => 'growth',
            'name' => 'QA Growth Owner',
        ],
        [
            'email' => 'qa-pro@demo-tenant.test',
            'role' => 'owner',
            'tier' => 'pro',
            'slug' => 'qa-pro',
            'plan_code' => 'business',
            'name' => 'QA Pro Owner',
        ],
    ];

    public function handle(TwoFactorAuthenticationProvider $twoFactor): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('qa:provision-accounts refuses to run outside local/testing.');

            return self::FAILURE;
        }

        $outputPath = trim((string) $this->option('output'));
        if ($outputPath === '') {
            $this->error('The --output option is required.');

            return self::FAILURE;
        }

        if (! Str::endsWith(mb_strtolower($outputPath), '.json')) {
            $this->error('The --output path must end with .json.');

            return self::FAILURE;
        }

        $directory = dirname($outputPath);
        if ($directory === '' || $directory === '.' || ! File::isDirectory($directory)) {
            $this->error('The --output directory does not exist.');

            return self::FAILURE;
        }

        try {
            $this->callSilent('db:seed', ['--class' => PlanSeeder::class]);
        } catch (Throwable $exception) {
            $this->error('Plan catalog could not be seeded: '.$exception->getMessage());

            return self::FAILURE;
        }

        /** @var list<array{email: string, role: string, tier: string|null, tenant_id: string|null, password: string, totp_secret: string, recovery_codes: list<string>}> $rows */
        $rows = [];

        try {
            DB::transaction(function () use ($twoFactor, &$rows): void {
                foreach (self::ACCOUNTS as $account) {
                    $rows[] = $this->provisionAccount($account, $twoFactor);
                }
            });
        } catch (Throwable $exception) {
            $this->error('QA account provisioning failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $written = File::put($outputPath, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        if ($written === false) {
            $this->error('Could not write the credentials JSON file.');

            return self::FAILURE;
        }

        $this->info('Provisioned QA accounts.');
        foreach ($rows as $row) {
            $this->line('Email: '.$row['email']);
        }
        $this->line('Output: '.$outputPath);

        return self::SUCCESS;
    }

    /**
     * @param  array{email: string, role: string, tier: string|null, slug: string|null, plan_code: string|null, name: string}  $account
     * @return array{email: string, role: string, tier: string|null, tenant_id: string|null, password: string, totp_secret: string, recovery_codes: list<string>}
     */
    private function provisionAccount(array $account, TwoFactorAuthenticationProvider $twoFactor): array
    {
        $password = bin2hex(random_bytes(32));
        $totpSecret = $twoFactor->generateSecretKey();
        $recoveryCodes = collect(range(1, 8))
            ->map(fn (): string => RecoveryCode::generate())
            ->values()
            ->all();

        $user = User::query()->where('email', $account['email'])->first();
        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => $password,
                'email_verified_at' => now(),
            ])->save();
        } else {
            $user->forceFill([
                'name' => $account['name'],
                'password' => $password,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        $user->forceFill([
            'two_factor_secret' => encrypt($totpSecret),
            'two_factor_recovery_codes' => encrypt(json_encode(array_values($recoveryCodes))),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $tenantId = null;

        if ($account['role'] === 'staff') {
            $this->ensureStaffPermissions($user);
        } else {
            $tenantId = $this->ensureOwnerWorkspace($user, $account);
        }

        return [
            'email' => $account['email'],
            'role' => $account['role'],
            'tier' => $account['tier'],
            'tenant_id' => $tenantId,
            'password' => $password,
            'totp_secret' => $totpSecret,
            'recovery_codes' => array_values($recoveryCodes),
        ];
    }

    private function ensureStaffPermissions(User $user): void
    {
        $access = CentralStaffAccess::query()->where('user_id', $user->id)->first();

        if ($access === null) {
            CentralStaffAccess::query()->create([
                'user_id' => $user->id,
                'permissions' => CentralStaffAuthorization::PERMISSIONS,
                'granted_by_user_id' => $user->id,
            ]);

            return;
        }

        $access->forceFill([
            'permissions' => CentralStaffAuthorization::PERMISSIONS,
        ])->save();
    }

    /**
     * @param  array{email: string, role: string, tier: string|null, slug: string|null, plan_code: string|null, name: string}  $account
     */
    private function ensureOwnerWorkspace(User $user, array $account): string
    {
        $slug = (string) $account['slug'];
        $planCode = (string) $account['plan_code'];
        $plan = Plan::query()->where('code', $planCode)->firstOrFail();

        $tenant = Tenant::query()->where('slug', $slug)->first();
        if ($tenant === null) {
            $tenant = Tenant::query()->create([
                'name' => 'QA '.Str::title((string) $account['tier']).' Workspace',
                'slug' => $slug,
                'status' => Tenant::STATUS_ACTIVE,
                'country_code' => 'US',
                'timezone' => 'UTC',
                'require_two_factor' => false,
                'onboarding_completed_at' => now(),
                'created_by_user_id' => $user->id,
                'settings' => [
                    'branding' => [
                        'display_name' => 'QA '.Str::title((string) $account['tier']),
                    ],
                    'alerts' => [
                        'email' => $user->email,
                    ],
                    'onboarding' => [
                        'workspace_completed_at' => now()->toIso8601String(),
                        'plan_completed_at' => now()->toIso8601String(),
                        'whmcs_completed_at' => now()->toIso8601String(),
                        'first_scan_completed_at' => now()->toIso8601String(),
                    ],
                ],
            ]);
        } else {
            $tenant->forceFill([
                'status' => Tenant::STATUS_ACTIVE,
                'onboarding_completed_at' => $tenant->onboarding_completed_at ?? now(),
            ])->save();
        }

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => TenantMembership::ROLE_OWNER,
                'status' => TenantMembership::STATUS_ACTIVE,
                'invited_by_user_id' => null,
                'accepted_at' => now(),
            ]);
        } else {
            $membership->forceFill([
                'role' => TenantMembership::ROLE_OWNER,
                'status' => TenantMembership::STATUS_ACTIVE,
                'accepted_at' => $membership->accepted_at ?? now(),
            ])->save();
        }

        $this->ensureActiveSubscription($tenant, $plan, $user);

        return (string) $tenant->id;
    }

    private function ensureActiveSubscription(Tenant $tenant, Plan $plan, User $owner): void
    {
        // Snapshot entitlements from the plan catalog — never invent commercial prices.
        $amount = $plan->price_monthly_minor;
        if ($amount === null) {
            throw new RuntimeException(
                "Plan [{$plan->code}] has no monthly price. Set prices in the platform area before provisioning QA subscriptions."
            );
        }

        $subscription = TenantSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('id')
            ->first();

        $attributes = [
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'billing_mode' => 'manual',
            'status' => 'active',
            'currency' => $plan->currency ?: 'USD',
            'unit_amount_minor' => $amount,
            'charge_period' => 'monthly',
            'entitlements' => $plan->entitlements,
            'cancel_at_period_end' => false,
            'current_period_starts_at' => now()->subDay(),
            'current_period_ends_at' => now()->addYear(),
            'created_by_user_id' => $owner->id,
        ];

        if ($subscription === null) {
            TenantSubscription::query()->create([
                'tenant_id' => $tenant->id,
                ...$attributes,
            ]);

            return;
        }

        $subscription->forceFill($attributes)->save();
    }
}
