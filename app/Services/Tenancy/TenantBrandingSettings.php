<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Facades\DB;

class TenantBrandingSettings
{
    /**
     * @param array{display_name?: string, support_email?: string, primary_color?: string, logo_url?: string|null} $branding
     */
    public function update(User $actor, string $tenantId, array $branding, ?string $ip = null): Tenant
    {
        return app(TenantDatabaseContext::class)->run($tenantId, (int) $actor->getKey(), function () use ($actor, $tenantId, $branding, $ip): Tenant {
            return DB::transaction(function () use ($actor, $tenantId, $branding, $ip): Tenant {
                app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'tenant.settings.manage');
                $tenant = Tenant::query()->whereKey($tenantId)->where('status', Tenant::STATUS_ACTIVE)->lockForUpdate()->firstOrFail();

                $settings = $tenant->settings ?? [];
                $oldBranding = $settings['branding'] ?? [];
                $settings['branding'] = array_merge($oldBranding, $branding);
                $tenant->forceFill(['settings' => $settings])->save();

                app(AuditEventWriter::class)->append([
                    'actor_type' => 'user',
                    'actor_id' => (string) $actor->getKey(),
                    'tenant_id' => $tenantId,
                    'event_type' => 'tenant.branding.updated',
                    'subject_type' => 'tenant',
                    'subject_id' => $tenantId,
                    'metadata' => [
                        'changed_fields' => array_values(array_keys($branding)),
                    ],
                    'ip_address' => $ip,
                    'occurred_at' => now(),
                ]);

                return $tenant->refresh();
            }, 3);
        });
    }
}
