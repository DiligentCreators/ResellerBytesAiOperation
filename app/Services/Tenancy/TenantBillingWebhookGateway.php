<?php

namespace App\\Services\\Tenancy;

use App\\Contracts\\Billing\\BillingProviderAdapter;
use App\\Models\\TenantBillingWebhookEvent;
use Carbon\\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

class TenantBillingWebhookGateway
{
    /**
     * Verify, tenant-map and ingest a provider webhook. This is an internal
     * application boundary; no public HTTP route is registered by this class.
     *
     * @param array<string, string|string[]> $headers
     */
    public function ingest(
        string $provider,
        BillingProviderAdapter $adapter,
        string $rawBody,
        array $headers,
    ): TenantBillingWebhookEvent {
        if (strlen($rawBody) < 1 || strlen($rawBody) > 262144) {
            throw new InvalidArgumentException('billing_webhook_request_body_invalid');
        }

        $verified = $adapter->verifyAndNormalize($rawBody, $headers);
        $now = CarbonImmutable::now('UTC');
        if ($verified->signatureTimestamp->diffInSeconds($now, false) > 300
            || $verified->signatureTimestamp->diffInSeconds($now, false) < -300
            || $verified->signatureTimestamp->greaterThan($now->addSeconds(300))
            || $verified->signatureTimestamp->lessThan($now->subSeconds(300))) {
            throw new RuntimeException('billing_webhook_signature_timestamp_outside_window');
        }

        $tenantId = app(TenantBillingProviderAccountResolver::class)->resolve(
            $provider,
            $verified->providerAccountId,
        );

        return app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
            $tenantId,
            $provider,
            $verified->providerEventId,
            $verified->eventType,
            $verified->normalizedPayload,
            $verified->occurredAt,
            $verified->providerAccountId,
        );
    }
}
