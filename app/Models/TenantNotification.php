<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantNotification extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'event_type', 'payload', 'read_at', 'created_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'read_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
