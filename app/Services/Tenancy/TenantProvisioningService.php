<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantProvisioningService
{
    public function __construct(private readonly AuditEventWriter $audit) {}

    /**
     * Create an active workspace and owner membership for a newly registered customer.
     *
     * Does not grant central/platform staff access.
     *
     * @param  array{name: string, country_code: string, timezone?: ?string}  $workspace
     */
    public function provisionOwnerWorkspace(User $owner, array $workspace, ?string $ipAddress = null): Tenant
    {
        return DB::transaction(function () use ($owner, $workspace, $ipAddress): Tenant {
            $name = trim($workspace['name']);
            $countryCode = strtoupper(trim($workspace['country_code']));
            $timezone = isset($workspace['timezone']) && is_string($workspace['timezone']) && $workspace['timezone'] !== ''
                ? $workspace['timezone']
                : 'UTC';

            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'status' => Tenant::STATUS_ACTIVE,
                'created_by_user_id' => $owner->id,
                'country_code' => $countryCode,
                'timezone' => $timezone,
                'require_two_factor' => false,
                'onboarding_completed_at' => null,
                'settings' => [
                    'branding' => [
                        'display_name' => $name,
                    ],
                    'alerts' => [
                        'email' => $owner->email,
                    ],
                    'onboarding' => [
                        'workspace_completed_at' => null,
                        'plan_completed_at' => null,
                        'whmcs_completed_at' => null,
                        'first_scan_completed_at' => null,
                    ],
                ],
            ]);

            $membership = TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'role' => TenantMembership::ROLE_OWNER,
                'status' => TenantMembership::STATUS_ACTIVE,
                'invited_by_user_id' => null,
                'accepted_at' => now(),
            ]);

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $owner->id,
                'tenant_id' => (string) $tenant->id,
                'event_type' => 'tenant.created',
                'subject_type' => Tenant::class,
                'subject_id' => (string) $tenant->id,
                'metadata' => [
                    'source' => 'public_registration',
                    'country_code' => $countryCode,
                    'slug' => $tenant->slug,
                ],
                'ip_address' => $ipAddress,
                'occurred_at' => now(),
            ]);

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $owner->id,
                'tenant_id' => (string) $tenant->id,
                'event_type' => 'tenant.membership.owner_provisioned',
                'subject_type' => TenantMembership::class,
                'subject_id' => (string) $membership->id,
                'metadata' => [
                    'role' => TenantMembership::ROLE_OWNER,
                    'source' => 'public_registration',
                ],
                'ip_address' => $ipAddress,
                'occurred_at' => now(),
            ]);

            return $tenant->refresh();
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'workspace';
        }

        $base = Str::limit($base, 64, '');
        $slug = $base;
        $attempt = 0;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $attempt++;
            $suffix = '-'.Str::lower(Str::random(4));
            $slug = Str::limit($base, 80 - strlen($suffix), '').$suffix;

            if ($attempt >= 25) {
                $slug = 'workspace-'.Str::lower(Str::random(12));
                break;
            }
        }

        return $slug;
    }
}
