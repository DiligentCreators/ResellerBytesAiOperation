<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Whmcs\WhmcsIntegrationProvisioner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('whmcs:sample-integration {tenant? : Tenant id (UUID) or first active tenant}')]
#[Description('Create or reuse a local demo WHMCS integration for smoke testing (never prints secrets)')]
class SampleWhmcsIntegrationCommand extends Command
{
    public const DEMO_INTEGRATION_NAME = 'Local demo WHMCS';

    public function handle(WhmcsIntegrationProvisioner $provisioner): int
    {
        if (app()->environment('production')) {
            $this->error('whmcs:sample-integration refuses to run in production.');

            return self::FAILURE;
        }

        $tenant = $this->resolveTenant();
        if ($tenant === null) {
            return self::FAILURE;
        }

        $existing = WhmcsIntegration::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', self::DEMO_INTEGRATION_NAME)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->first();

        if ($existing !== null) {
            $this->info('Reused local demo WHMCS integration.');
            $this->line('Integration ID: '.$existing->id);
            $this->line('Tenant ID: '.$tenant->id);
            $this->comment('Credentials stay encrypted in storage and are not displayed by this command.');

            return self::SUCCESS;
        }

        $owner = $this->resolveOwner($tenant);
        if ($owner === null) {
            return self::FAILURE;
        }

        try {
            $result = $provisioner->create(
                $owner,
                self::DEMO_INTEGRATION_NAME,
                $tenant->id,
                'system_operator',
            );
        } catch (InvalidArgumentException|AuthorizationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ModelNotFoundException) {
            $this->error('The requested tenant does not exist or is not active.');

            return self::FAILURE;
        }

        // Discard plaintext secret immediately — never print or log it.
        unset($result['plainTextSecret']);

        $this->info('Created local demo WHMCS integration.');
        $this->line('Integration ID: '.$result['integration']->id);
        $this->line('Tenant ID: '.$tenant->id);
        $this->line('Owner: '.$owner->email);
        $this->comment('Credentials stay encrypted in storage and are not displayed by this command.');

        return self::SUCCESS;
    }

    private function resolveTenant(): ?Tenant
    {
        $tenantArgument = $this->argument('tenant');
        $tenantId = is_string($tenantArgument) ? trim($tenantArgument) : '';

        if ($tenantId !== '') {
            if (! Str::isUuid($tenantId)) {
                $this->error('The tenant argument must be a valid tenant UUID.');

                return null;
            }

            $tenant = Tenant::query()
                ->whereKey($tenantId)
                ->where('status', Tenant::STATUS_ACTIVE)
                ->first();

            if ($tenant === null) {
                $this->error('No active tenant found for the given id.');

                return null;
            }

            return $tenant;
        }

        $tenant = Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->first();

        if ($tenant === null) {
            $this->error('No active tenant found. Seed demo accounts first (php artisan db:seed --class=DemoAccountsSeeder).');

            return null;
        }

        return $tenant;
    }

    private function resolveOwner(Tenant $tenant): ?User
    {
        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', TenantMembership::ROLE_OWNER)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->first();

        if ($membership === null) {
            $this->error('The tenant has no active owner membership.');

            return null;
        }

        $owner = User::query()
            ->whereKey($membership->user_id)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('two_factor_confirmed_at')
            ->whereNotNull('two_factor_secret')
            ->first();

        if ($owner === null) {
            $this->error('The tenant owner must have a verified email and confirmed TOTP MFA.');

            return null;
        }

        return $owner;
    }
}
