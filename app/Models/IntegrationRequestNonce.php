<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationRequestNonce extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'integration_id',
        'nonce',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WhmcsIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(WhmcsIntegration::class, 'integration_id');
    }
}
