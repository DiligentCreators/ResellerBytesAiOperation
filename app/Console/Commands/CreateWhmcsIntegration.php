<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\WhmcsIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateWhmcsIntegration extends Command
{
    protected $signature = 'whmcs:integration:create {name : A recognizable name for this WHMCS installation}';

    protected $description = 'Create a WHMCS integration and display its secret once';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '' || mb_strlen($name) > 120) {
            $this->error('The integration name must contain between 1 and 120 characters.');

            return self::FAILURE;
        }

        $secret = bin2hex(random_bytes(32));

        $integration = DB::transaction(function () use ($name, $secret): WhmcsIntegration {
            $integration = WhmcsIntegration::create([
                'name' => $name,
                'status' => 'active',
                'secret' => $secret,
                'secret_version' => 1,
            ]);

            AuditEvent::create([
                'actor_type' => 'system_operator',
                'event_type' => 'whmcs_integration.created',
                'subject_type' => 'whmcs_integration',
                'subject_id' => $integration->id,
                'metadata' => ['secret_version' => 1],
                'occurred_at' => now(),
            ]);

            return $integration;
        });

        $this->info('WHMCS integration created.');
        $this->line('Integration ID: '.$integration->id);
        $this->warn('Integration secret (shown once):');
        $this->line($secret);
        $this->comment('Copy the secret to a secure password manager. It cannot be displayed again.');

        return self::SUCCESS;
    }
}
