<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMembership extends Model
{
    use HasUuids;

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_BILLING = 'billing';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_OPERATOR,
        self::ROLE_VIEWER,
        self::ROLE_BILLING,
    ];

    public const STATUS_INVITED = 'invited';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_INVITED,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_REVOKED,
    ];

    protected $fillable = [
        'tenant_id', 'user_id', 'role', 'status', 'invited_by_user_id', 'accepted_at',
    ];

    protected function casts(): array
    {
        return ['accepted_at' => 'immutable_datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && in_array($this->role, self::ROLES, true);
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->isActive() && in_array($this->role, $roles, true);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
