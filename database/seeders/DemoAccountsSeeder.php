<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $password = 'password';
        $recoveryCodes = [
            'LOCAL-TEST-RECOVERY-01',
            'LOCAL-TEST-RECOVERY-02',
            'LOCAL-TEST-RECOVERY-03',
        ];

        $twoFactorProvider = app(TwoFactorAuthenticationProvider::class);

        DB::transaction(function () use ($password, $recoveryCodes, $twoFactorProvider): void {
            $platform = User::query()->where('email', 'admin@resellerbytes.test')->first();

            if ($platform === null) {
                $platform = new User;
                $platform->forceFill([
                    'name' => 'Platform Super Admin',
                    'email' => 'admin@resellerbytes.test',
                    'password' => $password,
                    'email_verified_at' => now(),
                ])->save();

                app(AuditEventWriter::class)->append([
                    'actor_type' => 'system_operator',
                    'event_type' => 'operations_user.created',
                    'subject_type' => 'user',
                    'subject_id' => (string) $platform->id,
                    'metadata' => ['source' => 'demo_accounts_seeder'],
                    'occurred_at' => now(),
                ]);
            }

            $customer = User::query()->where('email', 'customer@demo-tenant.test')->first();

            if ($customer === null) {
                $customer = new User;
                $customer->forceFill([
                    'name' => 'Demo Tenant Owner',
                    'email' => 'customer@demo-tenant.test',
                    'password' => $password,
                    'email_verified_at' => now(),
                ])->save();
            }

            foreach ([$platform, $customer] as $user) {
                if (! $user->hasEnabledTwoFactorAuthentication()) {
                    $user->forceFill([
                        'two_factor_secret' => encrypt($twoFactorProvider->generateSecretKey()),
                        'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
                        'two_factor_confirmed_at' => now(),
                    ])->save();
                }
            }

            $tenant = Tenant::query()->where('slug', 'demo-customer')->first();

            if ($tenant === null) {
                $tenant = Tenant::create([
                    'name' => 'Demo Customer Workspace',
                    'slug' => 'demo-customer',
                    'status' => Tenant::STATUS_ACTIVE,
                    'created_by_user_id' => $platform->id,
                    'settings' => [
                        'branding' => [
                            'display_name' => 'Demo Customer',
                        ],
                    ],
                ]);
            }

            if (! $tenant->memberships()->where('user_id', $customer->id)->exists()) {
                $tenant->memberships()->create([
                    'user_id' => $customer->id,
                    'role' => TenantMembership::ROLE_OWNER,
                    'status' => TenantMembership::STATUS_ACTIVE,
                    'invited_by_user_id' => $platform->id,
                    'accepted_at' => now(),
                ]);
            }

            if (! TenantSubscription::query()->where('tenant_id', $tenant->id)->exists()) {
                TenantSubscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_code' => 'local-demo-plan',
                    'billing_mode' => 'manual',
                    'status' => 'active',
                    'currency' => 'USD',
                    'unit_amount_minor' => 0,
                    'entitlements' => [
                        'limits' => [
                            'members' => 10,
                            'integrations' => 5,
                        ],
                    ],
                    'current_period_starts_at' => now()->subDay(),
                    'current_period_ends_at' => now()->addYear(),
                    'created_by_user_id' => $platform->id,
                ]);
            }
        });
    }
}
