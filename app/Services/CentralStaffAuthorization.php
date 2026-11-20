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

    public function isPlatformOperator(?User $user): bool
    {
        return $this->isSuperAdmin($user) || $this->permissions($user) !== [];
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

        $access = CentralStaffAccess::query()->where('user_id', $user->getKey())->first();

        $permissions = $access instanceof CentralStaffAccess ? $access->permissions : [];

        return in_array($permission, $permissions, true);
    }

    /** @return list<string> */
    public function permissions(?User $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return self::PERMISSIONS;
        }
        if (! $user) {
            return [];
        }

        $access = CentralStaffAccess::query()->where('user_id', $user->getKey())->first();

        $permissions = $access instanceof CentralStaffAccess ? $access->permissions : [];

        return array_values(array_intersect($permissions, self::PERMISSIONS));
    }
}
