<?php

declare(strict_types=1);

use Illuminate\\Contracts\\Console\\Kernel;
use Illuminate\\Database\\QueryException;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$runtime = DB::connection('pgsql');
$operations = DB::connection('pgsql_operations');

if ($runtime->getDriverName() !== 'pgsql' || $operations->getDriverName() !== 'pgsql') {
    throw new RuntimeException('billing_rls_rehearsal_requires_postgresql_runtime_and_operations_connections');
}

$tenantA = (string) env('BILLING_RLS_TENANT_A', '');
$tenantB = (string) env('BILLING_RLS_TENANT_B', '');
$paymentId = filter_var(env('BILLING_RLS_PAYMENT_A'), FILTER_VALIDATE_INT);

if (! Str::isUuid($tenantA) || ! Str::isUuid($tenantB) || $tenantA === $tenantB || $paymentId === false || $paymentId < 1) {
    throw new RuntimeException('billing_rls_rehearsal_requires_two_tenant_uuids_and_payment_fixture_id');
}

$payment = $operations->table('tenant_billing_payments')
    ->where('id', $paymentId)
    ->where('tenant_id', $tenantA)
    ->first();

if ($payment === null || ! is_string($payment->provider) || ! is_string($payment->provider_account_id)) {
    throw new RuntimeException('billing_rls_rehearsal_payment_fixture_invalid');
}

$role = $runtime->selectOne('SELECT rolbypassrls, rolsuper FROM pg_roles WHERE rolname = current_user');
if ($role === null || $role->rolbypassrls || $role->rolsuper) {
    throw new RuntimeException('billing_rls_runtime_role_must_not_bypass_rls_or_be_superuser');
}

$tables = [
    'tenant_billing_outbox',
    'tenant_billing_webhook_events',
    'tenant_billing_provider_accounts',
    'tenant_billing_adjustments',
];

foreach ($tables as $table) {
    $row = $runtime->selectOne('SELECT relrowsecurity, pg_get_userbyid(relowner) AS owner FROM pg_class WHERE oid = to_regclass(?)', [$table]);
    if ($row === null || ! $row->relrowsecurity || $row->owner === $runtime->selectOne('SELECT current_user AS name')->name) {
        throw new RuntimeException('billing_rls_table_policy_or_owner_boundary_invalid: '.$table);
    }
}

$token = (string) Str::uuid();
$provider = $payment->provider;
$account = $payment->provider_account_id;
$now = now('UTC');

$mapping = $operations->table('tenant_billing_provider_accounts')
    ->where('provider', $provider)
    ->where('provider_account_id', $account)
    ->first();

$createdMapping = false;
if ($mapping === null) {
    $mappingId = $operations->table('tenant_billing_provider_accounts')->insertGetId([
        'tenant_id' => $tenantA,
        'provider' => $provider,
        'provider_account_id' => $account,
        'status' => 'active',
        'verified_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $createdMapping = true;
} else {
    if ($mapping->tenant_id !== $tenantA) {
        throw new RuntimeException('billing_rls_payment_provider_account_not_bound_to_tenant_a');
    }
    $mappingId = $mapping->id;
}

$outboxId = $operations->table('tenant_billing_outbox')->insertGetId([
    'tenant_id' => $tenantA,
    'idempotency_key' => 'rls-test-'.$token,
    'event_type' => 'billing.rls_probe',
    'aggregate_type' => 'billing_rls_probe',
    'aggregate_id' => '1',
    'payload' => json_encode(['probe' => $token], JSON_THROW_ON_ERROR),
    'status' => 'pending',
    'attempts' => 0,
    'manual_replay_count' => 0,
    'available_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
]);

$webhookId = $operations->table('tenant_billing_webhook_events')->insertGetId([
    'tenant_id' => $tenantA,
    'provider' => $provider,
    'provider_account_id' => $account,
    'provider_event_id' => 'rls-'.$token,
    'event_type' => 'invoice.payment_failed',
    'payload' => json_encode(['invoice_id' => 1, 'failure_code' => 'rls_probe'], JSON_THROW_ON_ERROR),
    'payload_sha256' => hash('sha256', $token),
    'status' => 'pending',
    'attempts' => 0,
    'manual_replay_count' => 0,
    'received_at' => $now,
    'available_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
]);

$adjustmentId = $operations->table('tenant_billing_adjustments')->insertGetId([
    'tenant_id' => $tenantA,
    'tenant_billing_payment_id' => $paymentId,
    'provider' => $provider,
    'provider_account_id' => $account,
    'provider_adjustment_id' => 'rls-'.$token,
    'kind' => 'dispute_opened',
    'idempotency_key' => 'rls-adjustment-'.$token,
    'amount_minor' => 1,
    'currency' => $payment->currency,
    'occurred_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
]);

try {
    $runtime->statement("SELECT set_config('app.current_tenant_id', ?, false)", [$tenantA]);
    $runtime->statement("SELECT set_config('app.current_user_id', '', false)");

    $counts = [];
    foreach ([
        'tenant_billing_outbox' => $outboxId,
        'tenant_billing_webhook_events' => $webhookId,
        'tenant_billing_provider_accounts' => $mappingId,
        'tenant_billing_adjustments' => $adjustmentId,
    ] as $table => $ownId) {
        $own = $runtime->table($table)->where('id', $ownId)->count();
        $foreign = $runtime->table($table)->where('tenant_id', $tenantB)->count();
        if ($own !== 1 || $foreign !== 0) {
            throw new RuntimeException('billing_rls_tenant_visibility_failed: '.$table);
        }

        $updated = $runtime->table($table)->where('id', $ownId)->update(['updated_at' => $now]);
        if ($updated !== 1) {
            throw new RuntimeException('billing_rls_own_tenant_update_failed: '.$table);
        }

        $counts[$table] = ['own_tenant' => $own, 'foreign_tenant' => $foreign];
    }

    try {
        $runtime->table('tenant_billing_outbox')->insert([
            'tenant_id' => $tenantB,
            'idempotency_key' => 'rls-cross-tenant-'.$token,
            'event_type' => 'billing.rls_probe',
            'aggregate_type' => 'billing_rls_probe',
            'aggregate_id' => '2',
            'payload' => json_encode(['probe' => $token], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'manual_replay_count' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        throw new RuntimeException('billing_rls_cross_tenant_insert_was_not_denied');
    } catch (QueryException) {
        // Expected: WITH CHECK rejects a row for a different tenant.
    }

    fwrite(STDOUT, json_encode([
        'status' => 'passed',
        'runtime_role' => $runtime->selectOne('SELECT current_user AS name')->name,
        'runtime_role_bypassrls' => false,
        'runtime_role_superuser' => false,
        'tables' => $counts,
        'cross_tenant_insert' => 'denied',
        'provider_account_mapping_id' => $mappingId,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
} finally {
    $operations->table('tenant_billing_adjustments')->where('id', $adjustmentId)->delete();
    $operations->table('tenant_billing_webhook_events')->where('id', $webhookId)->delete();
    $operations->table('tenant_billing_outbox')->where('id', $outboxId)->delete();
    if ($createdMapping) {
        $operations->table('tenant_billing_provider_accounts')->where('id', $mappingId)->delete();
    }
}
