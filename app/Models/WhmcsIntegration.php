<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Collection;
use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;
use Illuminate\\Support\\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $name
 * @property string $status
 * @property string $secret
 * @property int $secret_version
 * @property string|null $addon_version
 * @property Carbon|null $last_seen_at
 * @property-read Collection<int, DiagnosticRun> $diagnosticRuns
 * @property-read int $diagnostic_runs_count
 */
class WhmcsIntegration extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'status',
        'secret',
        'secret_version',
        'addon_version',
        'last_seen_at',
    ];

    protected $hidden = [
        'secret',
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
            'secret' => 'encrypted',
            'secret_version' => 'integer',
            'last_seen_at' => 'immutable_datetime',
        ];
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
