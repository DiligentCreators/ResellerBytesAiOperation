<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralStaffAccess extends Model
{
    protected $table = 'central_staff_access';

    protected $fillable = ['user_id', 'permissions', 'granted_by_user_id'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
