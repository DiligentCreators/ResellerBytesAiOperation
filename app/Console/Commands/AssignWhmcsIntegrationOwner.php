<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignWhmcsIntegrationOwner extends Command
{
    protected $signature = 'whmcs:integration:assign-owner {integration : Integration UUID} {email : Verified MFA-enabled owner account email}';

    protected $description = 'Assign an unowned legacy WHMCS integration to one verified owner account';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $owner = User::query()
            ->where('email', $email)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('two_factor_confirmed_at')
            ->whereNotNull('two_factor_secret')
            ->first();

        if ($owner === null) {
            $this->error('The owner must be an existing account with verified email and confirmed TOTP MFA.');

            return self::FAILURE;
        }

        $assigned = DB::transaction(function () use ($owner): bool {
            $integration = WhmcsIntegration::query()
                ->whereKey($this->argument('integration'))
                ->lockForUpdate()
                ->first();

            if ($integration === null || $integration->owner_user_id !== null) {
                return false;
            }

            $integration->forceFill(['owner_user_id' => $owner->getKey()])->save();

            app(AuditEventWriter::class)->append([
                'actor_type' => 'system_operator',
                'event_type' => 'whmcs_integration.owner_assigned',
                'subject_type' => 'whmcs_integration',
                'subject_id' => $integration->id,
                'metadata' => ['owner_user_id' => (string) $owner->getKey()],
                'occurred_at' => now(),
            ]);

            return true;
        });

        if (! $assigned) {
            $this->error('Integration was not found or already has an owner. Existing ownership cannot be reassigned by this command.');

            return self::FAILURE;
        }

        $this->info('Integration owner assigned and recorded in the audit stream.');

        return self::SUCCESS;
    }
}
