<?php

namespace App\Services\Tenancy;

use App\Contracts\Billing\BillingProviderAdapter;
use App\Models\TenantBillingWebhookEvent;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

class TenantBillingWebhookGateway
{
    /**
     * Resolve only an explicitly configured provider adapter.
     *
     * @param  array<string, string|string[]>  $headers
     */
    public function ingestConfigured(string $provider, string $rawBody, array $headers): TenantBillingWebhookEvent
    {
        $adapter = app(BillingProviderAdapterRegistry::class)->resolve($provider);

        return $this->ingest($provider, $adapter, $rawBody, $headers);
    }

    /**
     * Verify, tenant-map and ingest a provider webhook. This is an internal
     * application boundary; no public HTTP route is registered by this class.
     *
     * @param  array<string, string|string[]>  $headers
     */
    public function ingest(
        string $provider,
        BillingProviderAdapter $adapter,
        string $rawBody,
        array $headers,
    ): TenantBillingWebhookEvent {
        if (! preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $provider)
            || ! hash_equals($provider, $adapter->provider())) {
            throw new InvalidArgumentException('billing_webhook_provider_adapter_mismatch');
        }

        if (strlen($rawBody) < 1 || strlen($rawBody) > 262144) {
            throw new InvalidArgumentException('billing_webhook_request_body_invalid');
        }

        $verified = $adapter->verifyAndNormalize($rawBody, $headers);
        $now = CarbonImmutable::now('UTC');
        if ($verified->signatureTimestamp->greaterThan($now->addSeconds(300))
            || $verified->signatureTimestamp->lessThan($now->subSeconds(300))) {
            throw new RuntimeException('billing_webhook_signature_timestamp_outside_window');
        }

        $tenantId = app(TenantBillingProviderAccountResolver::class)->resolve(
            $provider,
            $verified->providerAccountId,
        );
        $normalizedPayload = app(TenantBillingWebhookReferenceResolver::class)->resolve(
            $tenantId,
            $provider,
            $verified->providerAccountId,
            $verified->eventType,
            $verified->normalizedPayload,
        );

        return app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
            $tenantId,
            $provider,
            $verified->providerEventId,
            $verified->eventType,
            $normalizedPayload,
            $now,
            $verified->providerAccountId,
            $verified->occurredAt,
        );
    }
}
