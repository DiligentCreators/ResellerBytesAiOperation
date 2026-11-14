<?php

namespace App\\Services\\Tenancy;

use App\\Models\\Tenant;
use App\\Models\\TenantBillingWebhookEvent;
use App\\Services\\Audit\\AuditEventWriter;
use Carbon\\CarbonImmutable;
use DateTimeInterface;
use Illuminate\\Database\\QueryException;
use Illuminate\\Support\\Facades\\DB;
use InvalidArgumentException;
use RuntimeException;

class TenantBillingWebhookInbox
{
    /**
     * Persist a normalized webhook only after a provider adapter has verified
     * its signature and resolved the provider account to this tenant.
     *
     * This method is deliberately not an HTTP signature verifier. No public
     * webhook route is enabled until a provider and its verification contract
     * have been selected and independently reviewed.
     *
     * @param array<string, mixed> $normalizedPayload
     */
    public function acceptVerifiedEvent(
        string $tenantId,
        string $provider,
        string $providerEventId,
        string $eventType,
        array $normalizedPayload,
        mixed $receivedAt,
        string $providerAccountId,
        ?DateTimeInterface $providerOccurredAt = null,
    ): TenantBillingWebhookEvent {
        if (! preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $provider)
            || trim($providerEventId) === ''
            || strlen($providerEventId) > 180
            || ! preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $eventType)
            || ! is_string($providerAccountId) || trim($providerAccountId) === '' || strlen($providerAccountId) > 160) {
            throw new InvalidArgumentException('billing_webhook_input_invalid');
        }

        $allowedPayloadKeys = match ($eventType) {
            'invoice.payment_succeeded' => ['invoice_id', 'payment_id', 'amount_minor', 'currency'],
            'invoice.payment_failed' => ['invoice_id', 'failure_code'],
            'payment.refunded', 'payment.dispute_opened', 'payment.dispute_won', 'payment.dispute_lost' => ['payment_id', 'adjustment_id', 'amount_minor', 'currency'],
            'subscription.renewed' => ['subscription_id', 'settled_invoice_id', 'period_starts_at', 'period_ends_at'],
            'subscription.past_due' => ['subscription_id', 'grace_ends_at'],
            'subscription.canceled', 'subscription.expired' => ['subscription_id'],
            'subscription.reactivated' => ['subscription_id', 'settled_invoice_id'],
            default => throw new InvalidArgumentException('billing_webhook_event_type_unsupported'),
        };

        if (array_diff(array_keys($normalizedPayload), $allowedPayloadKeys) !== []
            || array_diff($allowedPayloadKeys, array_keys($normalizedPayload)) !== []) {
            throw new InvalidArgumentException('billing_webhook_payload_fields_invalid');
        }

        $this->validateNormalizedPayload($eventType, $normalizedPayload);

        if (! $receivedAt instanceof DateTimeInterface && ! is_string($receivedAt)) {
            throw new InvalidArgumentException('billing_webhook_received_at_invalid');
        }

        try {
            $receivedAt = CarbonImmutable::parse($receivedAt)->utc();
        } catch (\\Throwable) {
            throw new InvalidArgumentException('billing_webhook_received_at_invalid');
        }

        if ($receivedAt->greaterThan(now('UTC')->addMinutes(5))) {
            throw new InvalidArgumentException('billing_webhook_received_at_future');
        }

        $encoded = json_encode($normalizedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 65536) {
            throw new InvalidArgumentException('billing_webhook_payload_too_large');
        }

        $providerOccurredAt = $providerOccurredAt === null ? null : CarbonImmutable::parse($providerOccurredAt)->utc();
        if ($providerOccurredAt?->greaterThan(now('UTC')->addMinutes(5))) {
            throw new InvalidArgumentException('billing_webhook_provider_timestamp_invalid');
        }

        $hash = hash('sha256', $this->canonicalJson($normalizedPayload));

        try {
            return app(TenantDatabaseContext::class)->run($tenantId, null, fn (): TenantBillingWebhookEvent => DB::transaction(function () use ($tenantId, $provider, $providerEventId, $eventType, $normalizedPayload, $receivedAt, $providerAccountId, $providerOccurredAt, $hash): TenantBillingWebhookEvent {
                Tenant::query()->whereKey($tenantId)->firstOrFail();

                $existing = TenantBillingWebhookEvent::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', $provider)
                    ->where('provider_event_id', $providerEventId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (! hash_equals($existing->payload_sha256, $hash)
                        || $existing->event_type !== $eventType
                        || $existing->provider_account_id !== $providerAccountId
                        || $existing->provider_occurred_at?->toIso8601String() !== $providerOccurredAt?->toIso8601String()) {
                        throw new RuntimeException('billing_webhook_idempotency_conflict');
                    }

                    return $existing;
                }

                $event = TenantBillingWebhookEvent::query()->create([
                    'tenant_id' => $tenantId,
                    'provider' => $provider,
                    'provider_account_id' => $providerAccountId,
                    'provider_event_id' => $providerEventId,
                    'event_type' => $eventType,
                    'payload_sha256' => $hash,
                    'payload' => $normalizedPayload,
                    'status' => 'pending',
                    'attempts' => 0,
                    'received_at' => $receivedAt,
                    'provider_occurred_at' => $providerOccurredAt,
                    'available_at' => now('UTC'),
                ]);

                app(AuditEventWriter::class)->append([
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'tenant_id' => $tenantId,
                    'event_type' => 'tenant_billing.webhook_received',
                    'subject_type' => 'tenant_billing_webhook_event',
                    'subject_id' => (string) $event->id,
                    'metadata' => [
                        'provider' => $provider,
                        'event_type' => $eventType,
                        'payload_sha256' => $hash,
                    ],
                    'occurred_at' => $event->received_at,
                ]);

                return $event;
            }, 3));
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;
            if (! in_array($sqlState, ['23000', '23505'], true)) {
                throw $exception;
            }

            $existing = app(TenantDatabaseContext::class)->run($tenantId, null, fn (): ?TenantBillingWebhookEvent => TenantBillingWebhookEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', $provider)
                ->where('provider_event_id', $providerEventId)
                ->first());

            if ($existing !== null
                && hash_equals($existing->payload_sha256, $hash)
                && $existing->event_type === $eventType
                && $existing->provider_account_id === $providerAccountId
                && $existing->provider_occurred_at?->toIso8601String() === $providerOccurredAt?->toIso8601String()) {
                return $existing;
            }

            throw new RuntimeException('billing_webhook_idempotency_conflict', previous: $exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateNormalizedPayload(string $eventType, array $payload): void
    {
        if (in_array($eventType, ['payment.refunded', 'payment.dispute_opened', 'payment.dispute_won', 'payment.dispute_lost'], true)) {
            $amount = filter_var($payload['amount_minor'] ?? null, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 1
                || ! is_string($payload['payment_id'] ?? null) || trim($payload['payment_id']) === '' || strlen($payload['payment_id']) > 160
                || ! is_string($payload['adjustment_id'] ?? null) || trim($payload['adjustment_id']) === '' || strlen($payload['adjustment_id']) > 180
                || ! is_string($payload['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/', $payload['currency'])) {
                throw new InvalidArgumentException('billing_adjustment_payload_invalid');
            }

            return;
        }

        if ($eventType === 'invoice.payment_failed') {
            $invoiceId = filter_var($payload['invoice_id'] ?? null, FILTER_VALIDATE_INT);
            if ($invoiceId === false || $invoiceId < 1
                || ! is_string($payload['failure_code'] ?? null)
                || ! preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $payload['failure_code'])) {
                throw new InvalidArgumentException('billing_payment_failure_payload_invalid');
            }

            return;
        }

        if ($eventType === 'invoice.payment_succeeded') {
            $invoiceId = filter_var($payload['invoice_id'] ?? null, FILTER_VALIDATE_INT);
            $amount = filter_var($payload['amount_minor'] ?? null, FILTER_VALIDATE_INT);
            if ($invoiceId === false || $invoiceId < 1 || $amount === false || $amount < 1
                || ! is_string($payload['payment_id'] ?? null)
                || trim($payload['payment_id']) === '' || strlen($payload['payment_id']) > 160
                || ! is_string($payload['currency'] ?? null)
                || ! preg_match('/^[A-Z]{3}$/', $payload['currency'])) {
                throw new InvalidArgumentException('billing_webhook_payment_payload_invalid');
            }

            return;
        }

        $subscriptionId = filter_var($payload['subscription_id'] ?? null, FILTER_VALIDATE_INT);
        if ($subscriptionId === false || $subscriptionId < 1) {
            throw new InvalidArgumentException('billing_webhook_subscription_payload_invalid');
        }

        if ($eventType === 'subscription.renewed') {
            $invoiceId = filter_var($payload['settled_invoice_id'] ?? null, FILTER_VALIDATE_INT);
            if ($invoiceId === false || $invoiceId < 1
                || ! is_string($payload['period_starts_at'] ?? null)
                || ! is_string($payload['period_ends_at'] ?? null)) {
                throw new InvalidArgumentException('tenant_subscription_renewal_payload_invalid');
            }

            try {
                $periodStart = CarbonImmutable::parse($payload['period_starts_at']);
                $periodEnd = CarbonImmutable::parse($payload['period_ends_at']);
            } catch (\\Throwable) {
                throw new InvalidArgumentException('tenant_subscription_renewal_payload_invalid');
            }

            if (! $periodEnd->greaterThan($periodStart)
                || $periodStart->greaterThan(now('UTC')->addMinutes(5))
                || $periodEnd->diffInDays($periodStart) > 366) {
                throw new InvalidArgumentException('tenant_subscription_renewal_period_invalid');
            }
        }

        if ($eventType === 'subscription.past_due') {
            if (! is_string($payload['grace_ends_at'] ?? null)) {
                throw new InvalidArgumentException('billing_webhook_grace_period_invalid');
            }

            try {
                $graceEndsAt = CarbonImmutable::parse($payload['grace_ends_at']);
            } catch (\\Throwable) {
                throw new InvalidArgumentException('billing_webhook_grace_period_invalid');
            }

            if (! $graceEndsAt->isFuture()) {
                throw new InvalidArgumentException('billing_webhook_grace_period_invalid');
            }
        }

        if ($eventType === 'subscription.reactivated') {
            $invoiceId = filter_var($payload['settled_invoice_id'] ?? null, FILTER_VALIDATE_INT);
            if ($invoiceId === false || $invoiceId < 1) {
                throw new InvalidArgumentException('billing_webhook_settled_invoice_required');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function canonicalJson(array $payload): string
    {
        ksort($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
