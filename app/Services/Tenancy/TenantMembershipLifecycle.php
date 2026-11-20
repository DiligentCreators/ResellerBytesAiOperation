<?php

namespace App\Services\Tenancy;

use App\Jobs\SendTenantMembershipInvitation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantMembershipLifecycle
{
    public function invite(User $actor, string $tenantId, string $email, string $role, ?string $ip = null): TenantMembership
    {
        if (! in_array($role, [TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_OPERATOR, TenantMembership::ROLE_VIEWER, TenantMembership::ROLE_BILLING], true)) {
            throw ValidationException::withMessages(['role' => 'The requested invitation role is not assignable.']);
        }

        $target = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->whereNotNull('email_verified_at')->first();
        if (! $target) {
            throw ValidationException::withMessages(['email' => 'An existing account with a verified email address is required.']);
        }

        return $this->inTenant($tenantId, (int) $actor->getKey(), function () use ($actor, $tenantId, $target, $role, $ip): TenantMembership {
            app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'members.manage');
            $tenant = Tenant::query()->whereKey($tenantId)->where('status', Tenant::STATUS_ACTIVE)->lockForUpdate()->firstOrFail();

            $existing = TenantMembership::query()->where('tenant_id', $tenantId)->where('user_id', $target->getKey())->lockForUpdate()->first();
            if ($existing && in_array($existing->status, [TenantMembership::STATUS_ACTIVE, TenantMembership::STATUS_SUSPENDED], true)) {
                throw ValidationException::withMessages(['email' => 'This account already has an active, pending, or suspended membership.']);
            }

            if ($existing === null || $existing->status === TenantMembership::STATUS_REVOKED) {
                app(TenantResourceLimitService::class)->requireMemberCapacity($tenantId);
            }

            if ($existing) {
                $existing->forceFill([
                    'role' => $role,
                    'status' => TenantMembership::STATUS_INVITED,
                    'invited_by_user_id' => $actor->getKey(),
                    'accepted_at' => null,
                ])->save();
                $membership = $existing->refresh();
            } else {
                $membership = TenantMembership::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $target->getKey(),
                    'role' => $role,
                    'status' => TenantMembership::STATUS_INVITED,
                    'invited_by_user_id' => $actor->getKey(),
                    'accepted_at' => null,
                ]);
            }

            $this->audit($actor, $tenantId, 'tenant.membership.invited', $membership, ['target_user_id' => $target->getKey(), 'role' => $role], $ip);
            SendTenantMembershipInvitation::dispatch($tenantId, $membership->id, (int) $target->getKey())->afterCommit();

            return $membership;
        });
    }

    public function invitationFor(User $user, string $tenantId, string $membershipId): TenantMembership
    {
        return $this->inTenant($tenantId, (int) $user->getKey(), function () use ($user, $tenantId, $membershipId): TenantMembership {
            return TenantMembership::query()
                ->with(['tenant', 'user'])
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->getKey())
                ->whereKey($membershipId)
                ->where('status', TenantMembership::STATUS_INVITED)
                ->firstOrFail();
        });
    }

    public function accept(User $user, string $tenantId, ?string $ip = null): TenantMembership
    {
        return $this->inTenant($tenantId, (int) $user->getKey(), function () use ($user, $tenantId, $ip): TenantMembership {
            $membership = TenantMembership::query()->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
            if ($membership->status !== TenantMembership::STATUS_INVITED) {
                throw ValidationException::withMessages(['membership' => 'Only a pending invitation can be accepted.']);
            }
            $membership->forceFill(['status' => TenantMembership::STATUS_ACTIVE, 'accepted_at' => now()])->save();
            $this->audit($user, $tenantId, 'tenant.membership.accepted', $membership, ['role' => $membership->role], $ip);

            return $membership->refresh();
        });
    }

    public function changeRole(User $actor, string $tenantId, string $membershipId, string $role, ?string $ip = null): TenantMembership
    {
        if (! in_array($role, [TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_OPERATOR, TenantMembership::ROLE_VIEWER, TenantMembership::ROLE_BILLING], true)) {
            throw ValidationException::withMessages(['role' => 'The requested role is not assignable.']);
        }

        return $this->inTenant($tenantId, (int) $actor->getKey(), function () use ($actor, $tenantId, $membershipId, $role, $ip): TenantMembership {
            app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'members.manage');
            $membership = TenantMembership::query()->where('tenant_id', $tenantId)->whereKey($membershipId)->lockForUpdate()->firstOrFail();
            if ($membership->role === TenantMembership::ROLE_OWNER) {
                throw new AuthorizationException('Owner membership cannot be demoted through member administration.');
            }
            $oldRole = $membership->role;
            $membership->forceFill(['role' => $role])->save();
            $this->audit($actor, $tenantId, 'tenant.membership.role_changed', $membership, ['previous_role' => $oldRole, 'new_role' => $role], $ip);

            return $membership->refresh();
        });
    }

    public function revoke(User $actor, string $tenantId, string $membershipId, ?string $ip = null): TenantMembership
    {
        return $this->setStatus($actor, $tenantId, $membershipId, TenantMembership::STATUS_REVOKED, $ip);
    }

    public function suspend(User $actor, string $tenantId, string $membershipId, ?string $ip = null): TenantMembership
    {
        return $this->setStatus($actor, $tenantId, $membershipId, TenantMembership::STATUS_SUSPENDED, $ip);
    }

    private function setStatus(User $actor, string $tenantId, string $membershipId, string $status, ?string $ip): TenantMembership
    {
        return $this->inTenant($tenantId, (int) $actor->getKey(), function () use ($actor, $tenantId, $membershipId, $status, $ip): TenantMembership {
            app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'members.manage');
            $membership = TenantMembership::query()->where('tenant_id', $tenantId)->whereKey($membershipId)->lockForUpdate()->firstOrFail();
            if ($membership->role === TenantMembership::ROLE_OWNER) {
                throw new AuthorizationException('Owner membership cannot be suspended or revoked through member administration.');
            }
            if ($membership->status === $status) {
                return $membership;
            }
            $oldStatus = $membership->status;
            $membership->forceFill(['status' => $status])->save();
            $this->audit($actor, $tenantId, 'tenant.membership.'.$status, $membership, ['previous_status' => $oldStatus, 'new_status' => $status], $ip);

            return $membership->refresh();
        });
    }

    private function inTenant(string $tenantId, int $userId, callable $callback): mixed
    {
        return app(TenantDatabaseContext::class)->run($tenantId, $userId, fn () => DB::transaction(function () use ($tenantId, $callback) {
            Tenant::query()->whereKey($tenantId)->where('status', Tenant::STATUS_ACTIVE)->lockForUpdate()->firstOrFail();

            return $callback();
        }, 3));
    }

    /** @param array<string, mixed> $metadata */
    /** @param array<string, mixed> $metadata */
    private function audit(User $actor, string $tenantId, string $event, TenantMembership $membership, array $metadata, ?string $ip): void
    {
        app(AuditEventWriter::class)->append([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->getKey(),
            'tenant_id' => $tenantId,
            'event_type' => $event,
            'subject_type' => 'tenant_membership',
            'subject_id' => $membership->id,
            'metadata' => $metadata,
            'ip_address' => $ip,
            'occurred_at' => now(),
        ]);
    }
}
