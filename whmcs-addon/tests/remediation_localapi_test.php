<?php

namespace {
    use ResellerBytesAiOps\ActivityLogger;
    use ResellerBytesAiOps\FakeLocalApiClient;
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

    function assertLocalApi(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    final class RecordingActivityLogger implements ActivityLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log(string $message): void
        {
            $this->messages[] = $message;
        }
    }

    $credentials = [
        'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
        'secret' => str_repeat('b', 64),
    ];
    $client = new SignedApiClient;
    $fake = new FakeLocalApiClient;
    $logger = new RecordingActivityLogger;
    $runner = new RemediationRunner($fake, $logger);

    // ModuleUnsuspend command + params
    $unsuspend = $runner->apply($credentials, [
        'action_key' => 'service_unsuspend_paid',
        'target_ids' => [201],
    ], $client);
    assertLocalApi($unsuspend['status'] === 'success', 'Unsuspend should succeed with fake LocalAPI.');
    assertLocalApi(($fake->calls[0]['command'] ?? null) === 'ModuleUnsuspend', 'Expected ModuleUnsuspend.');
    assertLocalApi(($fake->calls[0]['values']['serviceid'] ?? null) === 201, 'Expected serviceid 201.');

    // Terminate refused
    $fake = new FakeLocalApiClient;
    $runner = new RemediationRunner($fake, $logger);
    $terminate = $runner->apply($credentials, [
        'action_key' => 'module_queue_retry',
        'preview' => [
            'items' => [[
                'id' => 12,
                'command' => 'ModuleTerminate',
                'params' => ['serviceid' => 102],
            ]],
        ],
    ], $client);
    assertLocalApi($terminate['status'] === 'failed', 'Terminate must fail closed.');
    assertLocalApi(($terminate['evidence']['item_results'][0]['error_code'] ?? null) === 'terminate_refused', 'Terminate refused.');
    assertLocalApi($fake->calls === [], 'Terminate must not call LocalAPI.');

    // Auto mode refuses ModuleCreate / ModuleSuspend / ModuleUnsuspend / ModuleTerminate
    foreach (['ModuleCreate', 'ModuleSuspend', 'ModuleUnsuspend', 'ModuleTerminate'] as $autoForbiddenCommand) {
        $fake = new FakeLocalApiClient;
        $runner = new RemediationRunner($fake, $logger);
        $autoRefusal = $runner->apply($credentials, [
            'action_key' => 'module_queue_retry',
            'mode' => 'auto',
            'preview' => [
                'items' => [[
                    'id' => 40,
                    'command' => $autoForbiddenCommand,
                    'params' => ['serviceid' => 400],
                ]],
            ],
        ], $client);
        $expectedError = $autoForbiddenCommand === 'ModuleTerminate' ? 'terminate_refused' : 'auto_action_refused';
        assertLocalApi($autoRefusal['status'] === 'failed', 'Auto mode must refuse '.$autoForbiddenCommand.'.');
        assertLocalApi(
            ($autoRefusal['evidence']['item_results'][0]['error_code'] ?? null) === $expectedError,
            'Auto mode '.$autoForbiddenCommand.' error code.',
        );
        assertLocalApi($fake->calls === [], 'Auto mode must not call LocalAPI for '.$autoForbiddenCommand.'.');
    }

    // Manual (non-auto) ModuleUnsuspend via module_queue_retry still works
    $fake = new FakeLocalApiClient;
    $runner = new RemediationRunner($fake, $logger);
    $manualQueue = $runner->apply($credentials, [
        'action_key' => 'module_queue_retry',
        'mode' => 'one_click',
        'preview' => [
            'items' => [[
                'id' => 11,
                'command' => 'ModuleUnsuspend',
                'params' => ['serviceid' => 101],
            ]],
        ],
    ], $client);
    assertLocalApi($manualQueue['status'] === 'success', 'Manual module_queue_retry unsuspend should succeed.');
    assertLocalApi(($fake->calls[0]['command'] ?? null) === 'ModuleUnsuspend', 'Manual ModuleUnsuspend expected.');

    // Duplicate GenInvoices guard
    $_ENV['RB_AI_OPS_FORCE_DUPLICATE_INVOICE'] = '1';
    $fake = new FakeLocalApiClient;
    $runner = new RemediationRunner($fake, $logger);
    $dup = $runner->apply($credentials, [
        'action_key' => 'invoice_generate_due',
        'target_ids' => [501],
    ], $client);
    unset($_ENV['RB_AI_OPS_FORCE_DUPLICATE_INVOICE']);
    assertLocalApi(($dup['evidence']['item_results'][0]['error_code'] ?? null) === 'duplicate_invoice_guard', 'Duplicate guard.');
    assertLocalApi($fake->calls === [], 'Duplicate guard must skip GenInvoices.');

    // Unsuspend re-check
    $_ENV['RB_AI_OPS_FORCE_UNSUSPEND_RECHECK_FAIL'] = '1';
    $fake = new FakeLocalApiClient;
    $runner = new RemediationRunner($fake, $logger);
    $recheck = $runner->apply($credentials, [
        'action_key' => 'service_unsuspend_paid',
        'target_ids' => [201],
    ], $client);
    unset($_ENV['RB_AI_OPS_FORCE_UNSUSPEND_RECHECK_FAIL']);
    assertLocalApi(($recheck['evidence']['item_results'][0]['error_code'] ?? null) === 'condition_recheck_failed', 'Unsuspend recheck.');

    // Fraud order skip
    $_ENV['RB_AI_OPS_FORCE_ORDER_FRAUD'] = '1';
    $fake = new FakeLocalApiClient;
    $runner = new RemediationRunner($fake, $logger);
    $fraud = $runner->apply($credentials, [
        'action_key' => 'order_accept_paid',
        'target_ids' => [301],
    ], $client);
    unset($_ENV['RB_AI_OPS_FORCE_ORDER_FRAUD']);
    assertLocalApi(($fraud['evidence']['item_results'][0]['error_code'] ?? null) === 'fraud_skipped', 'Fraud skipped.');

    // SendEmail / UpdateTicket / DomainRenew commands
    $fake = new FakeLocalApiClient;
    $runner = new RemediationRunner($fake, $logger);
    $runner->apply($credentials, [
        'action_key' => 'invoice_send_overdue_reminder',
        'target_ids' => [401],
        'resolved_params' => ['messagename' => 'Server Resolved Overdue Notice'],
        'input' => ['messagename' => 'Crafted Client Template'],
    ], $client);
    assertLocalApi(($fake->calls[0]['command'] ?? null) === 'SendEmail', 'SendEmail expected.');
    assertLocalApi(($fake->calls[0]['values']['id'] ?? null) === 401, 'Invoice id expected.');
    assertLocalApi(
        ($fake->calls[0]['values']['messagename'] ?? null) === 'Server Resolved Overdue Notice',
        'Addon must use server-resolved messagename only.',
    );

    // Client-crafted input.messagename must never win over resolved_params / preview.
    $fake = new FakeLocalApiClient;
    $runner = new RemediationRunner($fake, $logger);
    $runner->apply($credentials, [
        'action_key' => 'invoice_send_overdue_reminder',
        'target_ids' => [402],
        'input' => ['messagename' => 'Crafted Client Template'],
        'preview' => [
            'items' => [[
                'id' => 402,
                'command' => 'SendEmail',
                'params' => ['messagename' => 'Preview Authored Template', 'id' => 402],
            ]],
        ],
    ], $client);
    assertLocalApi(
        ($fake->calls[0]['values']['messagename'] ?? null) === 'Preview Authored Template',
        'Without resolved_params, preview messagename is the server-authored source.',
    );

    $fake = new FakeLocalApiClient;
    $runner = new RemediationRunner($fake, $logger);
    $runner->apply($credentials, [
        'action_key' => 'ticket_escalate_sla',
        'target_ids' => [601],
    ], $client);
    assertLocalApi(($fake->calls[0]['command'] ?? null) === 'UpdateTicket', 'UpdateTicket expected.');
    assertLocalApi(($fake->calls[0]['values']['priority'] ?? null) === 'High', 'High priority expected.');

    $fake = new FakeLocalApiClient;
    $runner = new RemediationRunner($fake, $logger);
    $runner->apply($credentials, [
        'action_key' => 'domain_renew_retry',
        'target_ids' => [701],
    ], $client);
    assertLocalApi(($fake->calls[0]['command'] ?? null) === 'DomainRenew', 'DomainRenew expected.');

    // templates_c path guard with temp ROOTDIR
    $tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rb_ai_ops_tpl_'.uniqid();
    $templatesC = $tempRoot.DIRECTORY_SEPARATOR.'templates_c';
    mkdir($templatesC, 0777, true);
    file_put_contents($templatesC.DIRECTORY_SEPARATOR.'compiled.php', '<?php');
    if (! defined('ROOTDIR')) {
        define('ROOTDIR', $tempRoot);
    }
    $fake = new FakeLocalApiClient;
    $runner = new RemediationRunner($fake, $logger);
    $clear = $runner->apply($credentials, [
        'action_key' => 'whmcs_clear_template_cache',
    ], $client);
    assertLocalApi($clear['status'] === 'success', 'Template cache clear should succeed.');
    assertLocalApi(($clear['evidence']['templates_c_cleared'] ?? false) === true, 'templates_c_cleared proof.');
    assertLocalApi(! file_exists($templatesC.DIRECTORY_SEPARATOR.'compiled.php'), 'Compiled file should be deleted.');

    // Activity log line written
    assertLocalApi(
        count(array_filter($logger->messages, static fn (string $message): bool => str_contains($message, 'ResellerBytes AI Ops:'))) > 0,
        'Activity log lines expected.',
    );

    // Cleanup temp files
    @rmdir($templatesC);
    @rmdir($tempRoot);

    echo "RemediationRunner LocalAPI checks passed.\n";
}
