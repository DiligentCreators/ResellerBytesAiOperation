<?php

namespace App\Services\Whmcs;

use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WhmcsIntegrationSecretRotator
{
    /**
     * @return array{integration: WhmcsIntegration, plainTextSecret: string}
     */
    public function rotate(
        string $integrationId,
        string $actorType = 'system_operator',
        ?string $actorId = null,
    ): array {
        $secret = bin2hex(random_bytes(32));

        $integration = DB::transaction(function () use ($integrationId, $secret, $actorType, $actorId): ?WhmcsIntegration {
            $integration = WhmcsIntegration::query()
                ->whereKey($integrationId)
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
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'event_type' => 'whmcs_integration.secret_rotated',
                'subject_type' => 'whmcs_integration',
                'subject_id' => $integration->id,
                'tenant_id' => $integration->tenant_id,
                'metadata' => ['secret_version' => $version],
                'occurred_at' => now(),
            ]);

            return $integration;
        });

        if ($integration === null) {
            throw new RuntimeException('No active integration was found for the supplied UUID.');
        }

        return [
            'integration' => $integration,
            'plainTextSecret' => $secret,
        ];
    }
}
