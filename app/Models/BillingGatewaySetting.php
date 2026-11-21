<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingGatewaySetting extends Model
{
    protected $table = 'billing_gateway_settings';

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'kelviq_sandbox_api_key',
        'kelviq_live_api_key',
        'kelviq_sandbox_webhook_secret',
        'kelviq_live_webhook_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kelviq_sandbox_api_key' => 'encrypted',
            'kelviq_live_api_key' => 'encrypted',
            'kelviq_sandbox_webhook_secret' => 'encrypted',
            'kelviq_live_webhook_secret' => 'encrypted',
            'trials_enabled' => 'boolean',
            'trial_card_required' => 'boolean',
            'trial_first_time_only' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
