<?php

namespace App\Services;

use App\Models\CentralStaffAccess;
use App\Models\User;

class CentralStaffAuthorization
{
    public const PERMISSIONS = ['tenants', 'billing', 'support', 'settings', 'audit'];

    public function isSuperAdmin(?User $user): bool
    {
        $owner = mb_strtolower(trim((string) config('operations.owner_email')));
        return $user !== null && $owner !== '' && hash_equals($owner, mb_strtolower((string) $user->email));
    }

    public function can(?User $user, string $permission): bool
    {
        if (! in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $user) {
            return false;
        }

        return in_array($permission, CentralStaffAccess::query()
            ->where('user_id', $user->getKey())
            ->value('permissions') ?? [], true);
    }

    public function permissions(?User $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return self::PERMISSIONS;
        }

        return array_values(array_intersect(
            CentralStaffAccess::query()->where('user_id', $user?->getKey())->value('permissions') ?? [],
            self::PERMISSIONS,
        ));
    }
}
