<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property string $name
 * @property string $target
 * @property string|null $signing_secret
 * @property list<string> $events
 * @property bool $enabled
 * @property Carbon|null $verified_at
 * @property string|null $last_error
 * @property Carbon|null $last_sent_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, TenantAlertDelivery> $deliveries
 */
class TenantAlertChannel extends Model
{
    use HasUuids;

    public const TYPE_EMAIL = 'email';

    public const TYPE_SLACK = 'slack';

    public const TYPE_DISCORD = 'discord';

    public const TYPE_WEBHOOK = 'webhook';

    public const TYPES = [
        self::TYPE_EMAIL,
        self::TYPE_SLACK,
        self::TYPE_DISCORD,
        self::TYPE_WEBHOOK,
    ];

    protected $fillable = [
        'tenant_id',
        'type',
        'name',
        'target',
        'signing_secret',
        'events',
        'enabled',
        'verified_at',
        'last_error',
        'last_sent_at',
    ];

    protected $hidden = [
        'target',
        'signing_secret',
    ];

    protected $attributes = [
        'enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'target' => 'encrypted',
            'signing_secret' => 'encrypted',
            'events' => 'array',
            'enabled' => 'boolean',
            'verified_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<TenantAlertDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(TenantAlertDelivery::class, 'channel_id');
    }

    public function listensFor(string $alertKey): bool
    {
        $events = is_array($this->events) ? $this->events : [];

        if (in_array('all_critical', $events, true) && $alertKey !== 'alert.test') {
            return in_array($alertKey, [
                'installation.heartbeat.down',
                'installation.heartbeat.recovery',
                'finding.critical',
                'fix.failed',
            ], true);
        }

        return in_array($alertKey, $events, true);
    }
}
