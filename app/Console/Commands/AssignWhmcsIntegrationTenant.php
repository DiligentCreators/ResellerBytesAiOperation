<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssignWhmcsIntegrationTenant extends Command
{
    protected $signature = 'whmcs:integration:assign-tenant {integration : Unassigned integration UUID} {tenant : Approved tenant UUID}';

    protected $description = 'Assign an unassigned integration and its diagnostic descendants to one tenant with an immutable audit record';

    public function handle(AuditEventWriter $audit): int
    {
        $integrationId = (string) $this->argument('integration');
        $tenantId = (string) $this->argument('tenant');

        if (! Str::isUuid($integrationId) || ! Str::isUuid($tenantId)) {
            $this->error('Both integration and tenant arguments must be UUIDs.');

            return self::FAILURE;
        }

        $assigned = DB::transaction(function () use ($integrationId, $tenantId, $audit): bool {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->first();
            if ($tenant === null || ! in_array($tenant->status, [Tenant::STATUS_PROVISIONING, Tenant::STATUS_ACTIVE], true)) {
                return false;
            }

            $integration = WhmcsIntegration::query()->whereKey($integrationId)->lockForUpdate()->first();
            if ($integration === null || $integration->tenant_id !== null) {
                return false;
            }

            $runIds = DB::table('diagnostic_runs')
                ->where('integration_id', $integrationId)
                ->lockForUpdate()
                ->pluck('id');

            $findingIds = $runIds->isEmpty()
                ? collect()
                : DB::table('diagnostic_findings')
                    ->whereIn('diagnostic_run_id', $runIds)
                    ->lockForUpdate()
                    ->pluck('id');

            $recommendationIds = $findingIds->isEmpty()
                ? collect()
                : DB::table('diagnostic_recommendations')
                    ->whereIn('diagnostic_finding_id', $findingIds)
                    ->lockForUpdate()
                    ->pluck('id');

            $integration->forceFill(['tenant_id' => $tenantId])->save();

            if ($runIds->isNotEmpty()) {
                DB::table('diagnostic_runs')->whereIn('id', $runIds)->update([
                    'tenant_id' => $tenantId,
                    'updated_at' => now(),
                ]);
            }

            if ($findingIds->isNotEmpty()) {
                DB::table('diagnostic_findings')->whereIn('id', $findingIds)->update([
                    'tenant_id' => $tenantId,
                    'updated_at' => now(),
                ]);
            }

            if ($recommendationIds->isNotEmpty()) {
                DB::table('diagnostic_recommendations')->whereIn('id', $recommendationIds)->update([
                    'tenant_id' => $tenantId,
                    'updated_at' => now(),
                ]);
            }

            if ($runIds->isNotEmpty()) {
                DB::table('ai_usage_attempts')->whereIn('diagnostic_run_id', $runIds)->whereNull('tenant_id')->update([
                    'tenant_id' => $tenantId,
                    'updated_at' => now(),
                ]);
            }

            $audit->append([
                'actor_type' => 'system_operator',
                'event_type' => 'whmcs_integration.tenant_assigned',
                'subject_type' => 'whmcs_integration',
                'subject_id' => $integrationId,
                'tenant_id' => $tenantId,
                'metadata' => [
                    'diagnostic_runs_backfilled' => $runIds->count(),
                    'diagnostic_findings_backfilled' => $findingIds->count(),
                    'diagnostic_recommendations_backfilled' => $recommendationIds->count(),
                ],
                'occurred_at' => now(),
            ]);

            return true;
        }, 3);

        if (! $assigned) {
            $this->error('Assignment refused: tenant must be provisioning/active and the integration must exist and remain unassigned. No changes were committed.');

            return self::FAILURE;
        }

        $this->info('Integration and its diagnostic descendants were assigned atomically; the immutable audit event was appended.');
        $this->warn('Existing historical audit events remain unchanged to preserve the global append-only hash chain.');
        $this->comment('Tenant membership and role assignment are separate explicit approvals; this command does not create memberships.');

        return self::SUCCESS;
    }
}
