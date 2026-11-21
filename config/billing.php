<?php

use App\Billing\Kelviq\KelviqBillingProviderAdapter;
use App\Billing\Sandbox\SandboxBillingProviderAdapter;

return [
    // Provisional operational default; commercial grace-period policy remains
    // configurable and must be reviewed before external billing activation.
    'payment_failure_grace_days' => (int) env('BILLING_PAYMENT_FAILURE_GRACE_DAYS', 7),

    /*
    | Active checkout gateway driver: sandbox (local) or kelviq.
    | Sandbox is blocked in production unless BILLING_SANDBOX_ALLOW_IN_PRODUCTION=true.
    */
    'default_gateway' => env('BILLING_GATEWAY', 'sandbox'),

    'sandbox' => [
        'enabled' => filter_var(env('BILLING_SANDBOX_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'allow_in_production' => filter_var(env('BILLING_SANDBOX_ALLOW_IN_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN),
        'signing_secret' => env('BILLING_SANDBOX_SIGNING_SECRET'),
    ],

    'kelviq' => [
        'mode' => env('KELVIQ_MODE', 'sandbox'),
        'api_key' => env('KELVIQ_API_KEY', ''),
        'webhook_secret' => env('KELVIQ_WEBHOOK_SECRET', ''),
        'sandbox_base_url' => env('KELVIQ_SANDBOX_BASE_URL', 'https://sandboxapi.kelviq.com/api/v1'),
        'live_base_url' => env('KELVIQ_LIVE_BASE_URL', 'https://api.kelviq.com/api/v1'),
        'timeout' => (int) env('KELVIQ_TIMEOUT', 15),
        'connect_timeout' => (int) env('KELVIQ_CONNECT_TIMEOUT', 5),
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
    */
    'providers' => [
        'sandbox' => SandboxBillingProviderAdapter::class,
        'kelviq' => KelviqBillingProviderAdapter::class,
    ],
];
