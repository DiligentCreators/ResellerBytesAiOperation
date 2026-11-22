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
 * @property string|null $tenant_id
 * @property string $integration_id
 * @property string|null $finding_id
 * @property string|null $issue_key
 * @property string $action_key
 * @property string $mode
 * @property string|null $batch_id
 * @property int|null $items_total
 * @property int|null $items_succeeded
 * @property int|null $minutes_saved
 * @property string $status
 * @property string $risk_level
 * @property int $requested_by_user_id
 * @property int|null $approved_by_user_id
 * @property int|null $rejected_by_user_id
 * @property string|null $approval_reason
 * @property string|null $rejection_reason
 * @property bool $requires_password_confirm
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $preview
 * @property array<int, int>|null $target_ids
 * @property array<string, mixed>|null $resolved_params
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $before_evidence
 * @property array<string, mixed>|null $after_evidence
 * @property Carbon $requested_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $agent_picked_up_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $executed_at
 * @property Carbon|null $verified_at
 * @property string|null $late_result_status
 * @property Carbon|null $late_result_at
 * @property-read Collection<int, SafeActionLateResult> $lateResults
 * @property-read int|null $late_results_count
 */
class SafeActionExecution extends Model
{
    use HasUuids;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_AWAITING_AGENT = 'awaiting_agent';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    public const REJECT_AFTER_PICKUP_MESSAGE = 'This fix was already sent to your WHMCS addon and can no longer be cancelled. Its result will appear here.';

    public const MODE_ONE_CLICK = 'one_click';

    public const MODE_GUIDED = 'guided';

    public const MODE_AUTO = 'auto';

    public const MODE_BULK = 'bulk';

    public const STATUSES = [
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_AWAITING_AGENT,
        self::STATUS_COMPLETED,
        self::STATUS_VERIFIED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'tenant_id',
        'integration_id',
        'finding_id',
        'issue_key',
        'action_key',
        'mode',
        'batch_id',
        'items_total',
        'items_succeeded',
        'minutes_saved',
        'status',
        'risk_level',
        'requested_by_user_id',
        'approved_by_user_id',
        'rejected_by_user_id',
        'approval_reason',
        'rejection_reason',
        'requires_password_confirm',
        'input',
        'preview',
        'target_ids',
        'resolved_params',
        'result',
        'before_evidence',
        'after_evidence',
        'requested_at',
        'approved_at',
        'agent_picked_up_at',
        'rejected_at',
        'executed_at',
        'verified_at',
        'late_result_status',
        'late_result_at',
    ];

    protected $attributes = [
        'mode' => self::MODE_ONE_CLICK,
    ];

    protected function casts(): array
    {
        return [
            'requires_password_confirm' => 'boolean',
            'items_total' => 'integer',
            'items_succeeded' => 'integer',
            'minutes_saved' => 'integer',
            'input' => 'array',
            'preview' => 'array',
            'target_ids' => 'array',
            'resolved_params' => 'array',
            'result' => 'array',
            'before_evidence' => 'array',
            'after_evidence' => 'array',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'agent_picked_up_at' => 'datetime',
            'rejected_at' => 'datetime',
            'executed_at' => 'datetime',
            'verified_at' => 'datetime',
            'late_result_at' => 'datetime',
        ];
    }

    public function wasPickedUpByAgent(): bool
    {
        return $this->agent_picked_up_at !== null;
    }

    public function isRejectable(): bool
    {
        if ($this->status === self::STATUS_PENDING_APPROVAL) {
            return true;
        }

        return $this->status === self::STATUS_AWAITING_AGENT
            && ! $this->wasPickedUpByAgent();
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

    /** @return BelongsTo<DiagnosticFinding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(DiagnosticFinding::class, 'finding_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    /** @return HasMany<SafeActionLateResult, $this> */
    public function lateResults(): HasMany
    {
        return $this->hasMany(SafeActionLateResult::class, 'safe_action_execution_id')
            ->orderBy('received_at')
            ->orderBy('id');
    }
}
