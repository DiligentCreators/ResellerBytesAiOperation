<?php

namespace App\Console\Commands;

use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RotateWhmcsIntegrationSecret extends Command
{
    protected $signature = 'whmcs:integration:rotate {integration : The integration UUID}';

    protected $description = 'Rotate an active WHMCS integration secret and display the new secret once';

    public function handle(): int
    {
        $secret = bin2hex(random_bytes(32));

        $integration = DB::transaction(function () use ($secret): ?WhmcsIntegration {
            $integration = WhmcsIntegration::query()
                ->whereKey((string) $this->argument('integration'))
                ->lockForUpdate()
                ->first();

            if (! $integration || $integration->status !== 'active') {
                return null;
            }

            $version = $integration->secret_version + 1;

            $integration->forceFill([
                'secret' => $secret,
                'secret_version' => $version,
            ])->save();

            app(AuditEventWriter::class)->append([
                'actor_type' => 'system_operator',
                'event_type' => 'whmcs_integration.secret_rotated',
                'subject_type' => 'whmcs_integration',
                'subject_id' => $integration->id,
                'metadata' => ['secret_version' => $version],
                'occurred_at' => now(),
            ]);

            return $integration;
        });

        if (! $integration) {
            $this->error('No active integration was found for the supplied UUID.');

            return self::FAILURE;
        }

        $this->info('WHMCS integration secret rotated.');
        $this->line('Integration ID: '.$integration->id);
        $this->line('Secret version: '.$integration->secret_version);
        $this->warn('New integration secret (shown once):');
        $this->line($secret);
        $this->comment('Update the WHMCS addon immediately. The previous secret is no longer valid.');

        return self::SUCCESS;
    }
}
