<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Whmcs\WhmcsIntegrationProvisioner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateWhmcsIntegration extends Command
{
    protected $signature = 'whmcs:integration:create {name : A recognizable name for this WHMCS installation} {--owner= : Verified owner account email} {--tenant= : Optional active tenant UUID for tenant-scoped provisioning}';

    protected $description = 'Create an owned WHMCS integration and display its secret once';

    public function handle(WhmcsIntegrationProvisioner $provisioner): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '' || mb_strlen($name) > 120) {
            $this->error('The integration name must contain between 1 and 120 characters.');

            return self::FAILURE;
        }

        $ownerEmail = trim((string) $this->option('owner'));

        if ($ownerEmail === '' || filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Provide --owner with the verified email of the integration owner.');

            return self::FAILURE;
        }

        $owner = User::query()
            ->where('email', $ownerEmail)
            ->whereNotNull('email_verified_at')
            ->first();

        if ($owner === null) {
            $this->error('The owner must be an existing account with a verified email.');

            return self::FAILURE;
        }

        $tenantId = trim((string) $this->option('tenant'));
        if ($tenantId !== '' && ! Str::isUuid($tenantId)) {
            $this->error('The --tenant value must be a valid tenant UUID.');

            return self::FAILURE;
        }

        try {
            $result = $provisioner->create(
                $owner,
                $name,
                $tenantId !== '' ? $tenantId : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (AuthorizationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ModelNotFoundException) {
            $this->error('The requested tenant does not exist or is not active.');

            return self::FAILURE;
        }

        $integration = $result['integration'];

        $this->info('WHMCS integration created.');
        $this->line('Integration ID: '.$integration->id);
        $this->line('Owner: '.$owner->email);
        if ($tenantId !== '') {
            $this->line('Tenant ID: '.$tenantId);
        }
        $this->warn('Integration secret (shown once):');
        $this->line($result['plainTextSecret']);
        $this->comment('Copy the secret to a secure password manager. It cannot be displayed again.');

        return self::SUCCESS;
    }
}
