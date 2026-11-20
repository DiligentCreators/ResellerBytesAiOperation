<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property array<string, mixed> $settings */
class Tenant extends Model
{
    use HasUuids;

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'country_code',
        'timezone',
        'require_two_factor',
        'onboarding_completed_at',
        'created_by_user_id',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'require_two_factor' => 'boolean',
            'onboarding_completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<TenantMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_memberships')
            ->withPivot(['id', 'role', 'status', 'invited_by_user_id', 'accepted_at'])
            ->withTimestamps();
    }

    /** @return HasMany<DiagnosticRun, $this> */
    public function diagnosticRuns(): HasMany
    {
        return $this->hasMany(DiagnosticRun::class);
    }

    /** @return HasMany<DiagnosticFinding, $this> */
    public function diagnosticFindings(): HasMany
    {
        return $this->hasMany(DiagnosticFinding::class);
    }

    /** @return HasMany<DiagnosticRecommendation, $this> */
    public function diagnosticRecommendations(): HasMany
    {
        return $this->hasMany(DiagnosticRecommendation::class);
    }

    /** @return HasMany<WhmcsIntegration, $this> */
    public function integrations(): HasMany
    {
        return $this->hasMany(WhmcsIntegration::class);
    }

    /** @return HasMany<TenantNotification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(TenantNotification::class);
    }

    /** @return HasMany<TenantSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    /** @return HasMany<TenantBillingInvoice, $this> */
    public function billingInvoices(): HasMany
    {
        return $this->hasMany(TenantBillingInvoice::class);
    }

    /** @return HasMany<TenantBillingPayment, $this> */
    public function billingPayments(): HasMany
    {
        return $this->hasMany(TenantBillingPayment::class);
    }

    /** @return HasMany<TenantBillingOutboxMessage, $this> */
    public function billingOutboxMessages(): HasMany
    {
        return $this->hasMany(TenantBillingOutboxMessage::class, 'tenant_id');
    }

    /** @return HasMany<TenantBillingWebhookEvent, $this> */
    public function billingWebhookEvents(): HasMany
    {
        return $this->hasMany(TenantBillingWebhookEvent::class, 'tenant_id');
    }

    /** @return HasMany<TenantBillingProviderAccount, $this> */
    public function billingProviderAccounts(): HasMany
    {
        return $this->hasMany(TenantBillingProviderAccount::class, 'tenant_id');
    }

    /** @return HasMany<TenantBillingAdjustment, $this> */
    public function billingAdjustments(): HasMany
    {
        return $this->hasMany(TenantBillingAdjustment::class, 'tenant_id');
    }

    /** @return HasMany<AiUsageAttempt, $this> */
    public function aiUsageAttempts(): HasMany
    {
        return $this->hasMany(AiUsageAttempt::class);
    }

    /** @return HasMany<AiTenantBudgetPeriod, $this> */
    public function aiBudgetPeriods(): HasMany
    {
        return $this->hasMany(AiTenantBudgetPeriod::class);
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }
}
