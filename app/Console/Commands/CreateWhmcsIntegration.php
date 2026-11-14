<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateWhmcsIntegration extends Command
{
    protected $signature = 'whmcs:integration:create {name : A recognizable name for this WHMCS installation} {--owner= : Verified MFA-enabled owner account email} {--tenant= : Optional active tenant UUID for tenant-scoped provisioning}';

    protected $description = 'Create an owned WHMCS integration and display its secret once';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $ownerEmail = trim((string) $this->option('owner'));

        if ($name === '' || mb_strlen($name) > 120) {
            $this->error('The integration name must contain between 1 and 120 characters.');

            return self::FAILURE;
        }

        if ($ownerEmail === '' || filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Provide --owner with the verified email of the integration owner.');

            return self::FAILURE;
        }

        $owner = User::query()
            ->where('email', $ownerEmail)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('two_factor_confirmed_at')
            ->whereNotNull('two_factor_secret')
            ->first();

        if ($owner === null) {
            $this->error('The owner must be an existing account with verified email and confirmed TOTP MFA.');

            return self::FAILURE;
        }

        $tenantId = trim((string) $this->option('tenant'));\n        if ($tenantId !== '' && ! \\Illuminate\\Support\\Str::isUuid($tenantId)) {\n            $this->error('The --tenant value must be a valid tenant UUID.');\n\n            return self::FAILURE;\n        }\n\n        $secret = bin2hex(random_bytes(32));

        $integration = DB::transaction(function () use ($name, $secret, $owner): WhmcsIntegration {
            $integration = WhmcsIntegration::create([
                'owner_user_id' => $owner->getKey(),
                'name' => $name,
                'status' => 'active',
                'secret' => $secret,
                'secret_version' => 1,
            ]);

            app(AuditEventWriter::class)->append([
                'actor_type' => 'system_operator',
                'event_type' => 'whmcs_integration.created',
                'subject_type' => 'whmcs_integration',
                'subject_id' => $integration->id,
                'metadata' => [
                    'owner_user_id' => (string) $owner->getKey(),
                    'secret_version' => 1,
                ],
                'occurred_at' => now(),
            ]);

            return $integration;
        });

        $this->info('WHMCS integration created.');
        $this->line('Integration ID: '.$integration->id);
        $this->line('Owner: '.$owner->email);\n        if ($tenantId !== '') {\n            $this->line('Tenant ID: '.$tenantId);\n        }
        $this->warn('Integration secret (shown once):');
        $this->line($secret);
        $this->comment('Copy the secret to a secure password manager. It cannot be displayed again.');

        return self::SUCCESS;
    }
}
