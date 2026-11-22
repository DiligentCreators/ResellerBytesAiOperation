<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property int|null $owner_user_id
 * @property string|null $tenant_id
 * @property-read Tenant|null $tenant
 * @property string $name
 * @property string $status
 * @property string $secret
 * @property int $secret_version
 * @property string|null $addon_version
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $last_heartbeat_at
 * @property Carbon|null $last_cron_at
 * @property Carbon|null $last_daily_cron_at
 * @property string|null $cron_php_version
 * @property string|null $collector_schema_version
 * @property bool $auto_collect_enabled
 * @property Carbon|null $last_auto_collect_at
 * @property string $heartbeat_state
 * @property Carbon|null $heartbeat_state_changed_at
 * @property Carbon|null $pending_diagnostic_collect_at
 * @property string|null $pending_diagnostic_collect_execution_id
 * @property array<string, mixed>|null $host_connection
 * @property-read User|null $owner
 * @property-read Collection<int, DiagnosticRun> $diagnosticRuns
 * @property-read int $diagnostic_runs_count
 */
class WhmcsIntegration extends Model
{
    use HasUuids;

    public const HEARTBEAT_STATE_UNKNOWN = 'unknown';

    public const HEARTBEAT_STATE_OK = 'ok';

    public const HEARTBEAT_STATE_STALE = 'stale';

    public const HEARTBEAT_STATE_DOWN = 'down';

    protected $fillable = [
        'owner_user_id',
        'tenant_id',
        'name',
        'status',
        'secret',
        'secret_version',
        'addon_version',
        'last_seen_at',
        'last_heartbeat_at',
        'last_cron_at',
        'last_daily_cron_at',
        'cron_php_version',
        'collector_schema_version',
        'auto_collect_enabled',
        'last_auto_collect_at',
        'heartbeat_state',
        'heartbeat_state_changed_at',
        'pending_diagnostic_collect_at',
        'pending_diagnostic_collect_execution_id',
        'host_connection',
    ];

    protected $hidden = [
        'secret',
        'host_connection',
    ];

    protected $attributes = [
        'auto_collect_enabled' => true,
        'heartbeat_state' => self::HEARTBEAT_STATE_UNKNOWN,
    ];

    protected static function booted(): void
    {
        static::deleting(function (WhmcsIntegration $integration): void {
            throw new LogicException(
                'WHMCS integrations are retained for audit history; revoke the integration instead of deleting it.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'owner_user_id' => 'integer',
            'secret' => 'encrypted',
            'secret_version' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'last_cron_at' => 'immutable_datetime',
            'last_daily_cron_at' => 'immutable_datetime',
            'auto_collect_enabled' => 'boolean',
            'last_auto_collect_at' => 'immutable_datetime',
            'heartbeat_state_changed_at' => 'immutable_datetime',
            'pending_diagnostic_collect_at' => 'immutable_datetime',
            'host_connection' => 'encrypted:array',
        ];
    }

    public function hasHostConnection(): bool
    {
        $connection = $this->host_connection;

        return is_array($connection)
            && is_string($connection['host'] ?? null)
            && $connection['host'] !== ''
            && is_string($connection['username'] ?? null)
            && $connection['username'] !== ''
            && is_string($connection['password'] ?? null)
            && $connection['password'] !== ''
            && is_string($connection['remote_whmcs_root'] ?? null)
            && $connection['remote_whmcs_root'] !== '';
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<DiagnosticRun, $this>
     */
    public function diagnosticRuns(): HasMany
    {
        return $this->hasMany(DiagnosticRun::class, 'integration_id');
    }

    /**
     * @return HasMany<IntegrationRequestNonce, $this>
     */
    public function requestNonces(): HasMany
    {
        return $this->hasMany(IntegrationRequestNonce::class, 'integration_id');
    }
}
