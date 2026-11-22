<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingPayment;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use App\Services\Billing\InvoiceNumberGenerator;
use App\Support\Operations\DemoAccountsPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        // Local demo password: env/config, or a stable encrypted per-install value (DemoAccountsPassword).
        $password = app(DemoAccountsPassword::class)->resolve();

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
                    'country_code' => 'US',
                    'timezone' => 'UTC',
                    'require_two_factor' => false,
                    'onboarding_completed_at' => now(),
                    'created_by_user_id' => $platform->id,
                    'settings' => [
                        'branding' => [
                            'display_name' => 'Demo Customer',
                        ],
                        'onboarding' => [
                            'workspace_completed_at' => now()->toIso8601String(),
                            'plan_completed_at' => now()->toIso8601String(),
                            'whmcs_completed_at' => now()->toIso8601String(),
                        ],
                        'billing' => [
                            'company_name' => 'Demo Customer LLC',
                            'billing_email' => 'billing@demo-tenant.test',
                            'country' => 'US',
                            'address_line1' => '100 Demo Street',
                            'city' => 'Austin',
                            'state' => 'TX',
                            'postal_code' => '78701',
                            'tax_id' => null,
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

            // PlanSeeder only creates missing codes and never overwrites live prices.
            $this->call(PlanSeeder::class);

            $growth = Plan::query()->where('code', 'growth')->firstOrFail();
            if (! TenantSubscription::query()->where('tenant_id', $tenant->id)->exists()) {
                // Snapshot amount for demo invoices only — never hardcode catalog prices.
                $amount = $growth->price_monthly_minor ?? random_int(1_000, 99_999);
                $entitlements = $growth->entitlements;

                $subscription = TenantSubscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $growth->id,
                    'plan_code' => $growth->code,
                    'billing_mode' => 'manual',
                    'status' => 'active',
                    'currency' => 'USD',
                    'unit_amount_minor' => $amount,
                    'charge_period' => 'monthly',
                    'entitlements' => $entitlements,
                    'cancel_at_period_end' => false,
                    'current_period_starts_at' => now()->subDay(),
                    'current_period_ends_at' => now()->addYear(),
                    'created_by_user_id' => $platform->id,
                ]);

                foreach ([1, 2] as $index) {
                    $invoiceNumber = app(InvoiceNumberGenerator::class)->next();
                    $invoice = TenantBillingInvoice::create([
                        'tenant_id' => $tenant->id,
                        'tenant_subscription_id' => $subscription->id,
                        'invoice_number' => $invoiceNumber,
                        'status' => 'paid',
                        'currency' => 'USD',
                        'subtotal_minor' => $amount,
                        'tax_minor' => 0,
                        'amount_due_minor' => $amount,
                        'amount_paid_minor' => $amount,
                        'line_items' => [[
                            'description' => $growth->name.' monthly',
                            'quantity' => 1,
                            'unit_amount_minor' => $amount,
                            'amount_minor' => $amount,
                        ]],
                        'billing_details' => $tenant->settings['billing'] ?? [
                            'company_name' => 'Demo Customer LLC',
                            'billing_email' => 'billing@demo-tenant.test',
                            'country' => 'US',
                            'address_line1' => '100 Demo Street',
                        ],
                        'issued_at' => now()->subMonths(3 - $index),
                        'paid_at' => now()->subMonths(3 - $index)->addHour(),
                    ]);

                    TenantBillingPayment::create([
                        'tenant_id' => $tenant->id,
                        'tenant_billing_invoice_id' => $invoice->id,
                        'collection_mode' => 'manual',
                        'idempotency_key' => 'demo-seed-payment-'.$index,
                        'status' => 'succeeded',
                        'currency' => 'USD',
                        'amount_minor' => $amount,
                        'reference' => 'demo-seed-settlement-'.$index,
                        'recorded_by_user_id' => $platform->id,
                        'received_at' => $invoice->paid_at,
                    ]);
                }
            }
        });
    }
}
