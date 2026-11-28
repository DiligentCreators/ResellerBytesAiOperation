<?php

namespace ResellerBytesAiOps;

use Throwable;

final class DiagnosticCollector
{
    private const ALLOWED_EXTENSIONS = [
        'curl',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'pdo_mysql',
        'soap',
        'xml',
        'gd',
        'iconv',
        'intl',
        'zip',
        'zlib',
        'fileinfo',
        'bcmath',
        'ioncube_loader',
        'sodium',
    ];

    private const ID_LIMIT = 50;

    private const TICKET_THRESHOLD_HOURS = 24;

    private WhmcsDataSource $dataSource;

    /** @var list<string> */
    private array $capabilities = [];

    /** @var list<string> */
    private array $limitations = [];

    private bool $sawDbFailure = false;

    private bool $sawLocalApiFailure = false;

    public function __construct(?WhmcsDataSource $dataSource = null)
    {
        $this->dataSource = $dataSource ?? new CapsuleWhmcsDataSource;
    }

    /**
     * Collect allowlisted technical signals for schema 1.1.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $this->capabilities = [
            'web_php_version',
            'whmcs_version',
            'php_extension_availability',
            'php_memory_limit',
            'configuration_file_writability',
        ];
        $this->limitations = [
            'cron_php_version_not_observable',
            'hosting_panel_configuration_not_observable',
            'ssl_certificate_not_verified',
        ];
        $this->sawDbFailure = false;
        $this->sawLocalApiFailure = false;

        $system = [
            'whmcs_version' => $this->whmcsVersion(),
            'web_php_version' => PHP_VERSION,
            'error_display_disabled' => $this->errorDisplayDisabled(),
            'configuration_file_writable' => $this->configurationFileWritable(),
        ];

        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
        if (is_string($serverSoftware)
            && preg_match('/\A([A-Za-z0-9._-]+)/', $serverSoftware, $matches) === 1) {
            $system['web_server'] = Text::truncateBytes($matches[1], 64);
        }

        $databaseVersion = $this->safe(fn (): ?string => $this->dataSource->databaseVersion());
        if (is_string($databaseVersion) && $databaseVersion !== '') {
            $system['database_version'] = Text::truncateBytes($databaseVersion, 64);
            $this->addCapability('database_server_version');
        }

        $systemUrl = $this->safe(fn (): ?string => $this->dataSource->configurationValue('SystemURL'));
        if (is_string($systemUrl)) {
            $system['application_url_configured'] = $systemUrl !== '';
        }

        $php = [
            'memory_limit' => Text::truncateBytes((string) (ini_get('memory_limit') ?: 'unknown'), 32),
            'extensions' => $this->extensionStatus(),
        ];

        $ioncube = $this->ioncubeVersion();
        if ($ioncube !== null) {
            $php['ioncube_version'] = $ioncube;
        }

        $maxExecution = ini_get('max_execution_time');
        if (is_string($maxExecution) && $maxExecution !== '' && is_numeric($maxExecution)) {
            $php['max_execution_time'] = max(0, min(86400, (int) $maxExecution));
        }

        $payload = [
            'system' => $system,
            'php' => $php,
        ];

        $security = $this->safe(fn (): ?array => $this->dataSource->securityFlags());
        if (is_array($security)) {
            $payload['security'] = [
                'admin_dir_default' => $security['admin_dir_default'] ?? null,
                'install_dir_present' => $security['install_dir_present'] ?? null,
            ];
        }

        $whmcs = [];

        $cron = $this->safe(fn (): ?array => $this->dataSource->cronAggregates());
        if (is_array($cron)) {
            $whmcs['cron'] = $cron;
            $this->addCapability('whmcs_cron');
        } else {
            $this->sawDbFailure = true;
        }

        $moduleQueue = $this->collectModuleQueue();
        if ($moduleQueue !== null) {
            $whmcs['module_queue'] = $moduleQueue;
            $this->addCapability('module_queue');
        }

        $billing = $this->safe(fn (): ?array => $this->boundAggregateIds(
            $this->dataSource->billingAggregates(),
            [
                'overdue_without_reminder' => 'invoice_ids',
                'due_services_without_invoice' => 'service_ids',
            ]
        ));
        if (is_array($billing)) {
            $whmcs['billing'] = $billing;
            $this->addCapability('billing_aggregates');
        } else {
            $this->sawDbFailure = true;
        }

        $services = $this->safe(fn (): ?array => $this->boundAggregateIds(
            $this->dataSource->serviceAggregates(),
            [
                'suspended_with_paid_invoices' => 'service_ids',
                'active_past_suspend_grace' => 'service_ids',
                'orphan' => 'service_ids',
                'pending_paid_orders' => 'order_ids',
            ]
        ));
        if (is_array($services)) {
            $whmcs['services'] = $services;
            $this->addCapability('service_aggregates');
        } else {
            $this->sawDbFailure = true;
        }

        $domains = $this->safe(fn (): ?array => $this->boundAggregateIds(
            $this->dataSource->domainAggregates(),
            [
                'active_expired' => 'domain_ids',
                'bad_expiry' => 'domain_ids',
                'renewal_failed_7d' => 'domain_ids',
            ]
        ));
        if (is_array($domains)) {
            $whmcs['domains'] = $domains;
            $this->addCapability('domain_aggregates');
        } else {
            $this->sawDbFailure = true;
        }

        $tickets = $this->safe(fn (): ?array => $this->boundAggregateIds(
            $this->dataSource->ticketAggregates(self::TICKET_THRESHOLD_HOURS),
            ['sla_breach' => 'ticket_ids']
        ));
        if (is_array($tickets)) {
            $whmcs['tickets'] = $tickets;
            $this->addCapability('ticket_aggregates');
        } else {
            $this->sawDbFailure = true;
        }

        $mail = $this->safe(fn (): ?array => $this->dataSource->mailAggregates());
        if (is_array($mail)) {
            $whmcs['mail'] = $mail;
            $this->addCapability('mail_logs');
        } else {
            $this->sawDbFailure = true;
        }

        $gateways = $this->safe(fn (): ?array => $this->dataSource->gatewayAggregates());
        if (is_array($gateways)) {
            if (isset($gateways['active']) && is_array($gateways['active'])) {
                $gateways['active'] = array_values(array_slice($gateways['active'], 0, 30));
            }
            $whmcs['gateways'] = $gateways;
            $this->addCapability('gateway_logs');
        } else {
            $this->sawDbFailure = true;
        }

        $modules = $this->safe(fn (): ?array => $this->dataSource->moduleInventory());
        if (is_array($modules)) {
            if (isset($modules['registrars']) && is_array($modules['registrars'])) {
                $modules['registrars'] = array_values(array_slice($modules['registrars'], 0, 30));
            }
            $whmcs['modules'] = $modules;
            $this->addCapability('module_inventory');
        } else {
            $this->addLimitation('module_status_not_collected');
        }

        $automation = $this->safe(fn (): ?array => $this->dataSource->automationSettings());
        if (is_array($automation)) {
            $whmcs['automation'] = $automation;
        }

        if ($whmcs !== []) {
            $payload['whmcs'] = $whmcs;
        }

        $host = [];
        $root = defined('ROOTDIR') ? (string) ROOTDIR : '';
        $disk = $this->safe(fn (): ?array => $this->dataSource->hostDiskStats($root));
        if (is_array($disk)) {
            $host['disk_free_percent'] = $disk['disk_free_percent'] ?? null;
            $host['disk_free_bytes'] = $disk['disk_free_bytes'] ?? null;
            $this->addCapability('host_disk');
        }

        $dbSizes = $this->safe(fn (): ?array => $this->dataSource->dbSizeStats());
        if (is_array($dbSizes)) {
            $host['db_size_mb'] = $dbSizes['db_size_mb'] ?? null;
            $tables = $dbSizes['db_top_tables'] ?? [];
            $host['db_top_tables'] = is_array($tables) ? array_values(array_slice($tables, 0, 5)) : [];
            $this->addCapability('db_sizes');
        } else {
            $this->sawDbFailure = true;
        }

        if ($host !== []) {
            $payload['host'] = $host;
        }

        $dbCapabilities = [
            'whmcs_cron',
            'billing_aggregates',
            'service_aggregates',
            'domain_aggregates',
            'ticket_aggregates',
            'mail_logs',
            'gateway_logs',
            'db_sizes',
        ];
        $anyDbCapability = false;
        foreach ($dbCapabilities as $capability) {
            if (in_array($capability, $this->capabilities, true)) {
                $anyDbCapability = true;
                break;
            }
        }
        if ($this->sawDbFailure && ! $anyDbCapability) {
            $this->addLimitation('whmcs_db_unavailable');
        }

        if ($this->sawLocalApiFailure && ! in_array('module_queue', $this->capabilities, true)) {
            $this->addLimitation('local_api_unavailable');
        }

        $payload['collector'] = [
            'capabilities' => array_values(array_unique($this->capabilities)),
            'limitations' => array_values(array_unique($this->limitations)),
        ];

        if (($payload['system']['configuration_file_writable'] ?? null) === true) {
            $payload['warnings'] = [[
                'code' => 'configuration_file_writable',
                'severity' => 'warning',
            ]];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function collectModuleQueue(): ?array
    {
        try {
            $api = $this->dataSource->localApi('GetModuleQueue');
            $items = $this->normalizeModuleQueueItems($api);
            $source = 'api';

            if ($items === null || $items === []) {
                if ($api === null || (is_array($api) && strtolower((string) ($api['result'] ?? '')) !== 'success')) {
                    $this->sawLocalApiFailure = true;
                }

                $tableRows = $this->dataSource->moduleQueueFromTable();
                if ($tableRows === null) {
                    $this->sawDbFailure = true;

                    return null;
                }

                $source = 'table';
                $items = $this->normalizeModuleQueueItems(['queue' => $tableRows]) ?? [];
            }

            $byAction = [
                'create' => 0,
                'suspend' => 0,
                'unsuspend' => 0,
                'terminate' => 0,
                'other' => 0,
            ];
            $oldest = null;
            $normalizedItems = [];
            foreach ($items as $item) {
                $item['action'] = $this->moduleActionKey((string) ($item['action'] ?? 'other'));
                $byAction[$item['action']]++;
                $age = (int) ($item['age_minutes'] ?? 0);
                $oldest = $oldest === null ? $age : max($oldest, $age);
                $normalizedItems[] = $item;
            }

            return [
                'source' => $source,
                'pending_count' => count($normalizedItems),
                'oldest_age_minutes' => $oldest,
                'by_action' => $byAction,
                'items' => array_slice($normalizedItems, 0, self::ID_LIMIT),
            ];
        } catch (Throwable) {
            $this->sawDbFailure = true;

            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $api
     * @return list<array<string, mixed>>|null
     */
    private function normalizeModuleQueueItems(?array $api): ?array
    {
        if ($api === null) {
            return null;
        }

        $raw = $api['queue'] ?? $api['items'] ?? $api['modules'] ?? null;
        if ($raw === null && isset($api[0]) && is_array($api[0])) {
            $raw = $api;
        }
        if (! is_array($raw)) {
            return null;
        }

        $items = [];
        foreach (array_slice($raw, 0, self::ID_LIMIT) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $serviceType = strtolower((string) ($row['service_type'] ?? $row['serviceType'] ?? $row['reltype'] ?? 'service'));
            if ($serviceType === 'hosting') {
                $serviceType = 'service';
            }
            if (! in_array($serviceType, ['service', 'domain', 'addon'], true)) {
                $serviceType = 'service';
            }

            $ageMinutes = $row['age_minutes'] ?? null;
            if (! is_numeric($ageMinutes)) {
                $createdAt = $row['created_at'] ?? $row['createdAt'] ?? $row['last_attempt'] ?? $row['lastAttempt'] ?? null;
                $timestamp = is_string($createdAt) ? strtotime($createdAt) : false;
                $ageMinutes = $timestamp === false ? 0 : max(0, (int) floor((time() - $timestamp) / 60));
            }

            $rawAction = (string) ($row['action'] ?? $row['module_action'] ?? $row['moduleAction'] ?? 'other');

            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'service_type' => $serviceType,
                'service_id' => (int) ($row['service_id'] ?? $row['serviceId'] ?? $row['relid'] ?? 0),
                'action' => $this->moduleActionKey($rawAction),
                'age_minutes' => (int) $ageMinutes,
                'retries' => (int) ($row['retries'] ?? $row['num_retries'] ?? $row['attempts'] ?? 0),
                'error' => SnapshotRedactor::redact((string) ($row['error'] ?? $row['last_error'] ?? $row['errorMsg'] ?? $row['errormsg'] ?? $row['failure_reason'] ?? '')),
            ];
        }

        return $items;
    }

    private function moduleActionKey(string $action): string
    {
        $normalized = strtolower($action);
        if (str_contains($normalized, 'create')) {
            return 'create';
        }
        if (str_contains($normalized, 'unsuspend')) {
            return 'unsuspend';
        }
        if (str_contains($normalized, 'suspend')) {
            return 'suspend';
        }
        if (str_contains($normalized, 'terminate')) {
            return 'terminate';
        }

        return 'other';
    }

    /**
     * @param  array<string, mixed>|null  $block
     * @param  array<string, string>  $map
     * @return array<string, mixed>|null
     */
    private function boundAggregateIds(?array $block, array $map): ?array
    {
        if ($block === null) {
            return null;
        }

        foreach ($map as $nestedKey => $idsKey) {
            if (! isset($block[$nestedKey]) || ! is_array($block[$nestedKey])) {
                continue;
            }
            $ids = $block[$nestedKey][$idsKey] ?? [];
            if (is_array($ids)) {
                $block[$nestedKey][$idsKey] = SnapshotRedactor::boundIdList($ids, self::ID_LIMIT);
            }
        }

        return $block;
    }

    private function addCapability(string $capability): void
    {
        if (! in_array($capability, $this->capabilities, true)) {
            $this->capabilities[] = $capability;
        }
    }

    private function addLimitation(string $limitation): void
    {
        if (! in_array($limitation, $this->limitations, true)) {
            $this->limitations[] = $limitation;
        }
    }

    private function whmcsVersion(): string
    {
        $version = $this->safe(fn (): ?string => $this->dataSource->configurationValue('Version'));

        return is_string($version) && $version !== ''
            ? Text::truncateBytes($version, 32)
            : 'unknown';
    }

    private function errorDisplayDisabled(): ?bool
    {
        $setting = ini_get('display_errors');
        if (! is_string($setting)) {
            return null;
        }

        $value = strtolower(trim($setting));
        if ($value === '') {
            return null;
        }

        return in_array($value, ['0', 'off', 'false', 'no'], true);
    }

    private function configurationFileWritable(): ?bool
    {
        if (! defined('ROOTDIR')) {
            return null;
        }

        $configurationFile = rtrim((string) ROOTDIR, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'configuration.php';

        return is_file($configurationFile) ? is_writable($configurationFile) : null;
    }

    private function ioncubeVersion(): ?string
    {
        if (! function_exists('ioncube_loader_version')) {
            return null;
        }

        try {
            $version = ioncube_loader_version();

            return is_string($version) && $version !== ''
                ? Text::truncateBytes($version, 32)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, bool>
     */
    private function extensionStatus(): array
    {
        $extensions = [];
        foreach (self::ALLOWED_EXTENSIONS as $extension) {
            $extensions[$extension] = $extension === 'ioncube_loader'
                ? (extension_loaded('ionCube Loader') || extension_loaded('ioncube_loader'))
                : extension_loaded($extension);
        }

        return $extensions;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
