<?php

namespace {
    use ResellerBytesAiOps\RemediationRunner;
    use ResellerBytesAiOps\SignedApiClient;

    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/LocalApiClient.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/WhmcsLocalApiClient.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/ActivityLogger.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/WhmcsActivityLogger.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/FakeLocalApiClient.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/RemediationRunner.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/HttpTransport.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/CurlHttpTransport.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/SignedApiClient.php';

    function assertRemediationCondition(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    $runner = new RemediationRunner;
    $client = new SignedApiClient;

    $rejected = $runner->apply(
        ['integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10', 'secret' => str_repeat('a', 64)],
        ['action_key' => 'drop_all_tables', 'id' => '00000000-0000-4000-8000-000000000001'],
        $client,
    );

    assertRemediationCondition($rejected['status'] === 'failed', 'Unknown actions must fail closed.');
    assertRemediationCondition(
        ($rejected['error_code'] ?? null) === 'action_not_allowlisted',
        'Unknown actions must report action_not_allowlisted.',
    );

    assertRemediationCondition(
        ! array_key_exists('error_display_disabled', $rejected['evidence'] ?? []),
        'Rejected actions must not invent runtime proof evidence.',
    );

    echo "RemediationRunner allowlist checks passed.\n";
}
