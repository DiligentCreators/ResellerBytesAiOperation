<?php

namespace App\Models;

use Database\Factories\WhmcsHealthSnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_id
 * @property string|null $diagnostic_run_id
 * @property Carbon $captured_at
 * @property Carbon $captured_on
 * @property int $score
 * @property int $critical_count
 * @property int $warning_count
 * @property int $info_count
 * @property array<string, mixed> $metrics
 * @property-read Tenant $tenant
 * @property-read WhmcsIntegration $integration
 * @property-read DiagnosticRun|null $diagnosticRun
 */
class WhmcsHealthSnapshot extends Model
{
    /** @use HasFactory<WhmcsHealthSnapshotFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'integration_id',
        'diagnostic_run_id',
        'captured_at',
        'captured_on',
        'score',
        'critical_count',
        'warning_count',
        'info_count',
        'metrics',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'captured_on' => 'date',
            'score' => 'integer',
            'critical_count' => 'integer',
            'warning_count' => 'integer',
            'info_count' => 'integer',
            'metrics' => 'array',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<WhmcsIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(WhmcsIntegration::class, 'integration_id');
    }

    /** @return BelongsTo<DiagnosticRun, $this> */
    public function diagnosticRun(): BelongsTo
    {
        return $this->belongsTo(DiagnosticRun::class, 'diagnostic_run_id');
    }
}
