<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property array<int, string> $permissions
 * @property int $granted_by_user_id
 * @property-read User $user
 * @property-read User $grantedBy
 */
class CentralStaffAccess extends Model
{
    protected $table = 'central_staff_access';

    protected $fillable = ['user_id', 'permissions', 'granted_by_user_id'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
