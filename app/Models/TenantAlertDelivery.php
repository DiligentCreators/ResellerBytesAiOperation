<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $channel_id
 * @property string $alert_key
 * @property string $dedupe_key
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $sent_at
 * @property-read Tenant $tenant
 * @property-read TenantAlertChannel $channel
 */
class TenantAlertDelivery extends Model
{
    use HasUuids;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUSES = [
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_SUPPRESSED,
    ];

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'alert_key',
        'dedupe_key',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<TenantAlertChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(TenantAlertChannel::class, 'channel_id');
    }
}
