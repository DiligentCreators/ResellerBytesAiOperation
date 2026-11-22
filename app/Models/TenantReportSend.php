<?php

namespace App\Models;

use Database\Factories\TenantReportSendFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon|null $sent_at
 * @property array<string, mixed> $summary
 * @property-read Tenant $tenant
 */
class TenantReportSend extends Model
{
    /** @use HasFactory<TenantReportSendFactory> */
    use HasFactory;

    use HasUuids;

    public const TYPE_WEEKLY = 'weekly';

    public const TYPE_MONTHLY_VALUE = 'monthly_value';

    public const TYPES = [
        self::TYPE_WEEKLY,
        self::TYPE_MONTHLY_VALUE,
    ];

    protected $fillable = [
        'tenant_id',
        'type',
        'period_start',
        'period_end',
        'sent_at',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'sent_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
