<?php

namespace App\\Services\\Tenancy;

use App\\Models\\Tenant;
use App\\Models\\TenantBillingWebhookEvent;
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
        ?string $providerAccountId = null,
    ): TenantBillingWebhookEvent {
        if (! preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $provider)
            || trim($providerEventId) === ''
            || strlen($providerEventId) > 180
            || ! preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $eventType)
            || ($providerAccountId !== null && strlen($providerAccountId) > 160)) {
            throw new InvalidArgumentException('billing_webhook_input_invalid');
        }

        $encoded = json_encode($normalizedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 65536) {
            throw new InvalidArgumentException('billing_webhook_payload_too_large');
        }

        $hash = hash('sha256', $encoded);

        return app(TenantDatabaseContext::class)->run($tenantId, null, fn (): TenantBillingWebhookEvent => DB::transaction(function () use ($tenantId, $provider, $providerEventId, $eventType, $normalizedPayload, $receivedAt, $providerAccountId, $hash): TenantBillingWebhookEvent {
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
                    || $existing->provider_account_id !== $providerAccountId) {
                    throw new RuntimeException('billing_webhook_idempotency_conflict');
                }

                return $existing;
            }

            return TenantBillingWebhookEvent::query()->create([
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
                'available_at' => now('UTC'),
            ]);
        }, 3));
    }
}
