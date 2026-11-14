<?php

namespace App\\Contracts\\Billing;

use App\\Data\\Billing\\VerifiedBillingWebhook;

interface BillingProviderAdapter
{
    public function provider(): string;

    /**
     * Verify the provider signature against the exact raw request body and
     * normalize the event. Implementations must reject invalid signatures and
     * malformed provider envelopes without exposing signature material.
     *
     * @param array<string, string|string[]> $headers
     */
    public function verifyAndNormalize(string $rawBody, array $headers): VerifiedBillingWebhook;
}
