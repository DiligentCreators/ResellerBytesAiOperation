<?php

namespace App\Console\Commands;

use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RevokeWhmcsIntegration extends Command
{
    protected $signature = 'whmcs:integration:revoke {integration : The integration UUID}';

    protected $description = 'Revoke a WHMCS integration so it can no longer submit diagnostics';

    public function handle(): int
    {
        $revoked = DB::transaction(function (): bool {
            $integration = WhmcsIntegration::query()
                ->whereKey((string) $this->argument('integration'))
                ->lockForUpdate()
                ->first();

            if (! $integration) {
                return false;
            }

            if ($integration->status === 'revoked') {
                return true;
            }

            $version = $integration->secret_version + 1;

            $integration->forceFill([
                'status' => 'revoked',
                'secret' => bin2hex(random_bytes(32)),
                'secret_version' => $version,
            ])->save();

            app(AuditEventWriter::class)->append([
                'actor_type' => 'system_operator',
                'event_type' => 'whmcs_integration.revoked',
                'subject_type' => 'whmcs_integration',
                'subject_id' => $integration->id,
                'metadata' => ['secret_version' => $version],
                'occurred_at' => now(),
            ]);

            return true;
        });

        if (! $revoked) {
            $this->error('No integration was found for the supplied UUID.');

            return self::FAILURE;
        }

        $this->info('WHMCS integration is revoked. All new signed requests will be rejected.');

        return self::SUCCESS;
    }
}
