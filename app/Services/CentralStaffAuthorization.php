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

        $access = CentralStaffAccess::query()->where('user_id', $user->getKey())->first();

        return in_array($permission, $access?->permissions ?? [], true);
    }

    public function permissions(?User $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return self::PERMISSIONS;
        }
        if (! $user) {
            return [];
        }

        $access = CentralStaffAccess::query()->where('user_id', $user->getKey())->first();

        return array_values(array_intersect($access?->permissions ?? [], self::PERMISSIONS));
    }
}
