<?php

use App\Billing\Sandbox\SandboxBillingProviderAdapter;

return [
    // Provisional operational default; commercial grace-period policy remains
    // configurable and must be reviewed before external billing activation.
    'payment_failure_grace_days' => (int) env('BILLING_PAYMENT_FAILURE_GRACE_DAYS', 7),

    /*
    | Active checkout gateway driver. C3 will add Kelviq; sandbox is for local/dev.
    | Sandbox is blocked in production unless BILLING_SANDBOX_ALLOW_IN_PRODUCTION=true.
    */
    'default_gateway' => env('BILLING_GATEWAY', 'sandbox'),

    'sandbox' => [
        'enabled' => filter_var(env('BILLING_SANDBOX_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'allow_in_production' => filter_var(env('BILLING_SANDBOX_ALLOW_IN_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN),
        'signing_secret' => env('BILLING_SANDBOX_SIGNING_SECRET', 'local-sandbox-billing-secret'),
    ],

    'seller' => array_filter([
        'company_name' => env('BILLING_SELLER_COMPANY', 'ResellerBytes'),
        'address' => env('BILLING_SELLER_ADDRESS', ''),
        'email' => env('BILLING_SELLER_EMAIL', 'billing@resellerbytes.com'),
        'tax_id' => env('BILLING_SELLER_TAX_ID', ''),
    ], static fn ($value) => $value !== null),

    'invoice_number_prefix' => env('BILLING_INVOICE_PREFIX', 'RB'),

    /*
    | Provider adapters for webhook verification (BillingProviderAdapterRegistry).
    | Sandbox is registered for local checkout settlement; Kelviq arrives in C3.
    */
    'providers' => [
        'sandbox' => SandboxBillingProviderAdapter::class,
    ],
];
