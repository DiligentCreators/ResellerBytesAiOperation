<?php

namespace App\Console\Commands;

use App\Services\Whmcs\WhmcsIntegrationSecretRotator;
use Illuminate\Console\Command;
use RuntimeException;

class RotateWhmcsIntegrationSecret extends Command
{
    protected $signature = 'whmcs:integration:rotate {integration : The integration UUID}';

    protected $description = 'Rotate an active WHMCS integration secret and display the new secret once';

    public function handle(WhmcsIntegrationSecretRotator $rotator): int
    {
        try {
            $result = $rotator->rotate((string) $this->argument('integration'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $integration = $result['integration'];

        $this->info('WHMCS integration secret rotated.');
        $this->line('Integration ID: '.$integration->id);
        $this->line('Secret version: '.$integration->secret_version);
        $this->warn('New integration secret (shown once):');
        $this->line($result['plainTextSecret']);
        $this->comment('Update the WHMCS addon immediately. The previous secret is no longer valid.');

        return self::SUCCESS;
    }
}
