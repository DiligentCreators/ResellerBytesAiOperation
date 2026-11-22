<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $finding_id
 * @property int $step_index
 * @property int|null $completed_by_user_id
 * @property Carbon|null $completed_at
 * @property-read Tenant $tenant
 * @property-read DiagnosticFinding $finding
 * @property-read User|null $completedBy
 */
class GuidedFixStep extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'finding_id',
        'step_index',
        'completed_by_user_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'step_index' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<DiagnosticFinding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(DiagnosticFinding::class, 'finding_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
