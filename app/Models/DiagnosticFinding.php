<?php

namespace App\Models;

use App\Services\Diagnostics\FindingContinuityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $diagnostic_run_id
 * @property string|null $tenant_id
 * @property string $fingerprint
 * @property string|null $issue_key
 * @property string $source
 * @property int|null $affected_count
 * @property string|null $fix_mode
 * @property Carbon|null $ignored_until
 * @property string $category
 * @property string $severity
 * @property string $title
 * @property string $summary
 * @property array<string, mixed> $evidence
 * @property string|null $confidence
 * @property string $status
 * @property int|null $assignee_user_id
 * @property Carbon|null $status_updated_at
 * @property Carbon|null $resolved_at
 * @property string|null $status_note
 * @property-read DiagnosticRun $diagnosticRun
 * @property-read User|null $assignee
 * @property-read Collection<int, DiagnosticRecommendation> $recommendations
 */
class DiagnosticFinding extends Model
{
    use HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ACCEPTED_RISK = 'accepted_risk';

    public const SOURCE_AI = 'ai';

    public const SOURCE_RULE = 'rule';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
        self::STATUS_ACCEPTED_RISK,
    ];

    public const ACTIVE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
    ];

    public const CLOSED_STATUSES = [
        self::STATUS_RESOLVED,
        self::STATUS_ACCEPTED_RISK,
    ];

    protected $fillable = [
        'diagnostic_run_id',
        'tenant_id',
        'fingerprint',
        'issue_key',
        'source',
        'affected_count',
        'fix_mode',
        'ignored_until',
        'category',
        'severity',
        'title',
        'summary',
        'evidence',
        'confidence',
        'status',
        'assignee_user_id',
        'status_updated_at',
        'resolved_at',
        'status_note',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'source' => self::SOURCE_AI,
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'confidence' => 'decimal:4',
            'affected_count' => 'integer',
            'ignored_until' => 'datetime',
            'status_updated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<DiagnosticRun, $this>
     */
    public function diagnosticRun(): BelongsTo
    {
        return $this->belongsTo(DiagnosticRun::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /**
     * @return HasMany<DiagnosticRecommendation, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(DiagnosticRecommendation::class);
    }

    /**
     * @return HasMany<GuidedFixStep, $this>
     */
    public function guidedFixSteps(): HasMany
    {
        return $this->hasMany(GuidedFixStep::class, 'finding_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotIgnored(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->whereNull('ignored_until')
                ->orWhere('ignored_until', '<=', now());
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIgnored(Builder $query): Builder
    {
        return $query->whereNotNull('ignored_until')
            ->where('ignored_until', '>', now());
    }

    public function isIgnored(): bool
    {
        return $this->ignored_until !== null && $this->ignored_until->isFuture();
    }

    /**
     * One row per installation + fingerprint (newest sighting).
     *
     * @param  Builder<DiagnosticFinding>  $query
     * @return Builder<DiagnosticFinding>
     */
    public function scopeCurrentIssues(Builder $query): Builder
    {
        return app(FindingContinuityService::class)
            ->scopeCurrentIssues($query);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
