<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhmcsIntegration extends Model
{
    use HasFactory, HasUuids;

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

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'secret_version' => 'integer',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public function diagnosticRuns(): HasMany
    {
        return $this->hasMany(DiagnosticRun::class, 'integration_id');
    }

    public function requestNonces(): HasMany
    {
        return $this->hasMany(IntegrationRequestNonce::class, 'integration_id');
    }
}
