<?php

namespace {
    use ResellerBytesAiOps\DiagnosticCollector;
    use ResellerBytesAiOps\Identifier;
    use ResellerBytesAiOps\SnapshotRedactor;
    use ResellerBytesAiOps\WhmcsDataSource;

    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/Text.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/Identifier.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/WhmcsDataSource.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/SnapshotRedactor.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/CapsuleWhmcsDataSource.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/DiagnosticCollector.php';

    function assertCollectorCondition(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    final class FakeWhmcsDataSource implements WhmcsDataSource
    {
        public bool $failBilling = false;

        public bool $failLocalApi = false;

        /** @var list<array<string, mixed>>|null */
        public ?array $moduleQueueRows = null;

        /** @var array<string, mixed>|null */
        public ?array $gatewayOverride = null;

        public function configurationValue(string $setting): ?string
        {
            return match ($setting) {
                'Version' => '8.13.7-release.1',
                'SystemURL' => 'https://portal.example.test',
                default => null,
            };
        }

        public function databaseVersion(): ?string
        {
            return '10.11.19-MariaDB';
        }

        public function localApi(string $command, array $params = []): ?array
        {
            if ($this->failLocalApi) {
                return null;
            }

            if ($command === 'GetModuleQueue') {
                $queue = $this->moduleQueueRows ?? [
                    [
                        'id' => 11,
                        'serviceType' => 'service',
                        'serviceId' => 101,
                        'moduleAction' => 'ModuleCreate',
                        'createdAt' => gmdate('Y-m-d H:i:s', time() - 10800),
                        'attempts' => 2,
                        'errorMsg' => 'Module create failed for user@example.com at 203.0.113.10',
                    ],
                ];

                return [
                    'result' => 'success',
                    'queue' => $queue,
                ];
            }

            return null;
        }

        public function cronAggregates(): ?array
        {
            return [
                'last_daily_run_at' => '2026-11-22T01:00:00Z',
                'daily_starts_24h' => 1,
                'daily_completions_24h' => 1,
            ];
        }

        public function moduleQueueFromTable(): ?array
        {
            return [
                [
                    'id' => 99,
                    'service_type' => 'Hosting',
                    'service_id' => 50,
                    'module_action' => 'SuspendAccount',
                    'created_at' => gmdate('Y-m-d H:i:s', time() - 600),
                    'num_retries' => 1,
                    'error' => 'suspend failed',
                ],
            ];
        }

        public function billingAggregates(): ?array
        {
            if ($this->failBilling) {
                throw new RuntimeException('billing_unavailable');
            }

            return [
                'overdue_unpaid_count' => 2,
                'overdue_unpaid_total' => '100.00',
                'overdue_without_reminder' => [
                    'count' => 55,
                    'invoice_ids' => range(1, 60),
                ],
                'due_services_without_invoice' => [
                    'count' => 1,
                    'service_ids' => [301],
                ],
                'duplicate_invoice_groups_30d' => 0,
                'recovered_paid_7d_total' => '0.00',
            ];
        }

        public function serviceAggregates(): ?array
        {
            return [
                'suspended_with_paid_invoices' => ['count' => 0, 'service_ids' => []],
                'active_past_suspend_grace' => ['count' => 0, 'service_ids' => []],
                'orphan' => ['count' => 0, 'service_ids' => []],
                'pending_paid_orders' => ['count' => 0, 'order_ids' => [], 'oldest_age_minutes' => null],
            ];
        }

        public function domainAggregates(): ?array
        {
            return [
                'active_expired' => ['count' => 0, 'domain_ids' => []],
                'bad_expiry' => ['count' => 0, 'domain_ids' => []],
                'sync_errors_7d' => 0,
                'renewal_failed_7d' => ['count' => 0, 'domain_ids' => []],
            ];
        }

        public function ticketAggregates(int $thresholdHours): ?array
        {
            return [
                'threshold_hours' => $thresholdHours,
                'sla_breach' => ['count' => 0, 'ticket_ids' => [], 'oldest_hours' => null],
            ];
        }

        public function mailAggregates(): ?array
        {
            return [
                'piping_failed_7d' => 0,
                'sending_failed_24h' => 0,
            ];
        }

        public function gatewayAggregates(): ?array
        {
            if ($this->gatewayOverride !== null) {
                return $this->gatewayOverride;
            }

            return [
                'active' => ['paypal'],
                'failures_7d' => [],
                'signature_failures_7d' => [],
                'unmatched_success_7d' => [],
            ];
        }

        public function moduleInventory(): ?array
        {
            return [
                'registrars' => [['name' => 'RegistrarX', 'version' => '1.0.0']],
                'custom_template_overrides' => false,
                'custom_hooks_count' => 0,
            ];
        }

        public function automationSettings(): ?array
        {
            return [
                'suspend_days' => 5,
                'invoice_generation_days' => 14,
            ];
        }

        public function securityFlags(): ?array
        {
            return [
                'admin_dir_default' => true,
                'install_dir_present' => false,
            ];
        }

        public function hostDiskStats(string $root): ?array
        {
            return [
                'disk_free_percent' => 42.0,
                'disk_free_bytes' => 50000000000,
            ];
        }

        public function dbSizeStats(): ?array
        {
            return [
                'db_size_mb' => 512.0,
                'db_top_tables' => [
                    ['name' => 'tblactivitylog', 'size_mb' => 100.5],
                ],
            ];
        }
    }

    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'resellerbytes-ai-collector-'.bin2hex(random_bytes(8));
    mkdir($root, 0700, true);
    define('ROOTDIR', $root);

    file_put_contents($root.DIRECTORY_SEPARATOR.'configuration.php', '<?php');
    $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.62';
    ini_set('display_errors', '0');

    try {
        $fake = new FakeWhmcsDataSource;
        $payload = (new DiagnosticCollector($fake))->collect();

        assertCollectorCondition($payload['system']['whmcs_version'] === '8.13.7-release.1', 'WHMCS version was not collected.');
        assertCollectorCondition($payload['system']['database_version'] === '10.11.19-MariaDB', 'Database version was not collected.');
        assertCollectorCondition($payload['system']['application_url_configured'] === true, 'System URL status was not collected.');
        assertCollectorCondition($payload['system']['web_server'] === 'Apache', 'Web server value was not sanitized.');
        assertCollectorCondition($payload['system']['error_display_disabled'] === true, 'Error display state was not collected.');
        assertCollectorCondition(isset($payload['php']['extensions']['curl']), 'Allowlisted PHP extensions were not collected.');
        assertCollectorCondition(array_key_exists('max_execution_time', $payload['php']), 'max_execution_time was not collected.');
        assertCollectorCondition($payload['system']['configuration_file_writable'] === true, 'Configuration file writability was not observed.');
        assertCollectorCondition(isset($payload['warnings'][0]['code']), 'Configuration file writability warning was not collected.');
        assertCollectorCondition(! array_key_exists('cron_php_version', $payload['system']), 'Unobservable cron PHP version was reported.');

        assertCollectorCondition(isset($payload['security']['admin_dir_default']), 'Security block missing.');
        assertCollectorCondition(isset($payload['whmcs']['cron']['last_daily_run_at']), 'Cron block missing.');
        assertCollectorCondition($payload['whmcs']['module_queue']['source'] === 'api', 'Module queue source was not api.');
        assertCollectorCondition(isset($payload['whmcs']['billing']['overdue_unpaid_count']), 'Billing block missing.');
        assertCollectorCondition(isset($payload['whmcs']['services']['orphan']), 'Services block missing.');
        assertCollectorCondition(isset($payload['whmcs']['domains']['sync_errors_7d']), 'Domains block missing.');
        assertCollectorCondition(isset($payload['whmcs']['tickets']['sla_breach']), 'Tickets block missing.');
        assertCollectorCondition(isset($payload['whmcs']['mail']['piping_failed_7d']), 'Mail block missing.');
        assertCollectorCondition(isset($payload['whmcs']['gateways']['active']), 'Gateways block missing.');
        assertCollectorCondition(isset($payload['whmcs']['modules']['registrars']), 'Modules block missing.');
        assertCollectorCondition(isset($payload['whmcs']['automation']['suspend_days']), 'Automation block missing.');
        assertCollectorCondition(isset($payload['host']['disk_free_percent']), 'Host disk block missing.');
        assertCollectorCondition(isset($payload['host']['db_size_mb']), 'Host db sizes missing.');

        assertCollectorCondition(
            in_array('module_inventory', $payload['collector']['capabilities'], true),
            'module_inventory capability missing.',
        );
        assertCollectorCondition(
            ! in_array('module_status_not_collected', $payload['collector']['limitations'], true),
            'module_status_not_collected should be dropped when inventory is present.',
        );
        assertCollectorCondition(
            in_array('cron_php_version_not_observable', $payload['collector']['limitations'], true),
            'Legacy cron PHP limitation missing.',
        );

        $invoiceIds = $payload['whmcs']['billing']['overdue_without_reminder']['invoice_ids'];
        assertCollectorCondition(count($invoiceIds) === 50, 'Invoice ID list was not capped at 50.');

        $moduleError = $payload['whmcs']['module_queue']['items'][0]['error'] ?? '';
        assertCollectorCondition(strpos($moduleError, 'user@example.com') === false, 'Email was not redacted from module queue error.');
        assertCollectorCondition(strpos($moduleError, '203.0.113.10') === false, 'IPv4 was not redacted from module queue error.');

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        assertCollectorCondition(strpos($encoded, 'portal.example.test') === false, 'The configured system URL was transmitted.');
        assertCollectorCondition(strpos($encoded, 'user@example.com') === false, 'Email leaked into payload.');

        $redacted = SnapshotRedactor::redact('Contact a@b.co from 2001:db8::1 via https://user:pass@example.com/x');
        assertCollectorCondition(strpos($redacted, 'a@b.co') === false, 'SnapshotRedactor left an email.');
        assertCollectorCondition(strpos($redacted, '2001:db8::1') === false, 'SnapshotRedactor left an IPv6.');
        assertCollectorCondition(strpos($redacted, 'user:pass@') === false, 'SnapshotRedactor left credentialed URL.');
        assertCollectorCondition(strlen($redacted) <= 200, 'SnapshotRedactor did not truncate.');

        $failing = new FakeWhmcsDataSource;
        $failing->failBilling = true;
        $failingPayload = (new DiagnosticCollector($failing))->collect();
        assertCollectorCondition(! isset($failingPayload['whmcs']['billing']), 'Failed billing block was not omitted.');
        assertCollectorCondition(
            ! in_array('billing_aggregates', $failingPayload['collector']['capabilities'], true),
            'billing_aggregates capability present after failure.',
        );

        $apiFail = new FakeWhmcsDataSource;
        $apiFail->failLocalApi = true;
        $apiFailPayload = (new DiagnosticCollector($apiFail))->collect();
        assertCollectorCondition(
            ($apiFailPayload['whmcs']['module_queue']['source'] ?? null) === 'table',
            'Module queue did not fall back to table when local API failed.',
        );

        $serviceidOnly = new FakeWhmcsDataSource;
        $serviceidOnly->moduleQueueRows = [
            [
                'id' => 42,
                'serviceType' => 'service',
                'serviceid' => 777,
                'moduleAction' => 'ModuleCreate',
                'createdAt' => gmdate('Y-m-d H:i:s', time() - 120),
                'attempts' => 0,
                'errorMsg' => 'pending',
            ],
        ];
        $serviceidPayload = (new DiagnosticCollector($serviceidOnly))->collect();
        assertCollectorCondition(
            ($serviceidPayload['whmcs']['module_queue']['items'][0]['service_id'] ?? null) === 777,
            'Lowercase serviceid was not mapped to service_id.',
        );

        $zeroService = new FakeWhmcsDataSource;
        $zeroService->moduleQueueRows = [
            [
                'id' => 55,
                'serviceType' => 'service',
                'service_id' => 0,
                'moduleAction' => 'ModuleSuspend',
                'createdAt' => gmdate('Y-m-d H:i:s', time() - 60),
                'attempts' => 1,
                'errorMsg' => 'orphan queue row',
            ],
            [
                'queueid' => 56,
                'serviceType' => 'service',
                'serviceid' => 200,
                'moduleAction' => 'ModuleCreate',
                'createdAt' => gmdate('Y-m-d H:i:s', time() - 30),
                'attempts' => 0,
                'errorMsg' => 'ok',
            ],
        ];
        $zeroPayload = (new DiagnosticCollector($zeroService))->collect();
        assertCollectorCondition(
            ($zeroPayload['whmcs']['module_queue']['pending_count'] ?? null) === 2,
            'pending_count must include omitted service_id 0 rows.',
        );
        assertCollectorCondition(
            count($zeroPayload['whmcs']['module_queue']['items'] ?? []) === 1,
            'service_id 0 rows must be omitted from items.',
        );
        assertCollectorCondition(
            ($zeroPayload['whmcs']['module_queue']['items'][0]['id'] ?? null) === 56,
            'queueid fallback for id was not applied.',
        );

        $dirtyGateways = new FakeWhmcsDataSource;
        $dirtyGateways->gatewayOverride = [
            'active' => ['PayPal (Recommended)', 'stripe'],
            'failures_7d' => ['PayPal (Recommended)' => 3, 'paypal & express' => 2],
            'signature_failures_7d' => [],
            'unmatched_success_7d' => ['Foo/Bar' => 1],
        ];
        $gatewayPayload = (new DiagnosticCollector($dirtyGateways))->collect();
        assertCollectorCondition(
            in_array('PayPal Recommended', $gatewayPayload['whmcs']['gateways']['active'] ?? [], true),
            'Gateway display name was not sanitized.',
        );
        assertCollectorCondition(
            ($gatewayPayload['whmcs']['gateways']['failures_7d']['PayPal Recommended'] ?? null) === 3,
            'Gateway map key was not sanitized.',
        );
        assertCollectorCondition(
            ($gatewayPayload['whmcs']['gateways']['failures_7d']['paypal express'] ?? null) === 2,
            'Gateway map key with ampersand was not sanitized.',
        );
        assertCollectorCondition(
            ($gatewayPayload['whmcs']['gateways']['unmatched_success_7d']['Foo Bar'] ?? null) === 1,
            'Gateway map key with slash was not sanitized.',
        );

        assertCollectorCondition(
            Identifier::sanitize('PayPal (Recommended)') === 'PayPal Recommended',
            'Identifier::sanitize did not normalize parentheses.',
        );
        assertCollectorCondition(
            DiagnosticCollector::parseIoncubeVersion('13.0.2 VPS') === '13.0.2',
            'ionCube version with suffix was not extracted.',
        );
        assertCollectorCondition(
            DiagnosticCollector::parseIoncubeVersion('v13.0.2') === '13.0.2',
            'ionCube version with leading v was not extracted.',
        );
        assertCollectorCondition(
            DiagnosticCollector::parseIoncubeVersion('13.0.2') === '13.0.2',
            'Clean ionCube version was altered.',
        );
    } finally {
        $configurationFile = $root.DIRECTORY_SEPARATOR.'configuration.php';

        if (is_file($configurationFile)) {
            unlink($configurationFile);
        }

        if (is_dir($root)) {
            rmdir($root);
        }
    }

    echo "WHMCS addon diagnostic collector tests passed.\n";
}
