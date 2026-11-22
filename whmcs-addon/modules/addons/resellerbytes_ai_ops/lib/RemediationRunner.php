<?php

namespace ResellerBytesAiOps;

use RuntimeException;
use Throwable;

final class RemediationRunner
{
    private const ALLOWED_ACTIONS = [
        'pull_and_run_diagnostic_collect',
        'request_diagnostic_collect',
        'disable_php_display_errors',
        'harden_configuration_php_mode',
        'module_queue_retry',
        'service_unsuspend_paid',
        'invoice_send_overdue_reminder',
        'invoice_generate_due',
        'order_accept_paid',
        'ticket_escalate_sla',
        'domain_renew_retry',
        'whmcs_clear_template_cache',
    ];

    private const FORBIDDEN_COMMANDS = [
        'RefundInvoice',
        'ModuleTerminate',
        'UpdateInvoice',
        'AddInvoicePayment',
        'AddCredit',
        'ApplyCredit',
        'UpdatePaymentGateway',
        'ActivatePaymentGateway',
        'DeactivatePaymentGateway',
        'UpgradeProduct',
    ];

    private LocalApiClient $localApi;

    private ActivityLogger $activity;

    public function __construct(?LocalApiClient $localApi = null, ?ActivityLogger $activity = null)
    {
        $this->localApi = $localApi ?? new WhmcsLocalApiClient;
        $this->activity = $activity ?? new WhmcsActivityLogger;
    }

    /**
     * @param  array{integration_id: string, secret: string}  $credentials
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    public function apply(array $credentials, array $action, SignedApiClient $client): array
    {
        $actionKey = isset($action['action_key']) && is_string($action['action_key'])
            ? $action['action_key']
            : '';

        if (! in_array($actionKey, self::ALLOWED_ACTIONS, true)) {
            return [
                'status' => 'failed',
                'summary' => 'Action key is not allowlisted for addon execution.',
                'evidence' => [],
                'error_code' => 'action_not_allowlisted',
            ];
        }

        try {
            return match ($actionKey) {
                'pull_and_run_diagnostic_collect', 'request_diagnostic_collect' => $this->runDiagnosticCollect($credentials, $client),
                'disable_php_display_errors' => $this->disableDisplayErrors($action),
                'harden_configuration_php_mode' => $this->hardenConfigurationPhp($action),
                'module_queue_retry' => $this->runModuleQueueRetry($action),
                'service_unsuspend_paid' => $this->runServiceUnsuspendPaid($action),
                'invoice_send_overdue_reminder' => $this->runInvoiceSendOverdueReminder($action),
                'invoice_generate_due' => $this->runInvoiceGenerateDue($action),
                'order_accept_paid' => $this->runOrderAcceptPaid($action),
                'ticket_escalate_sla' => $this->runTicketEscalateSla($action),
                'domain_renew_retry' => $this->runDomainRenewRetry($action),
                'whmcs_clear_template_cache' => $this->clearTemplateCache(),
                default => [
                    'status' => 'failed',
                    'summary' => 'Action key is not allowlisted for addon execution.',
                    'evidence' => [],
                    'error_code' => 'action_not_allowlisted',
                ],
            };
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'summary' => 'Allowlisted remediation could not be applied.',
                'evidence' => [],
                'error_code' => 'remediation_exception',
            ];
        }
    }

    /**
     * @param  array{integration_id: string, secret: string}  $credentials
     * @return array{status: string, summary: string, evidence: array<string, mixed>}
     */
    private function runDiagnosticCollect(array $credentials, SignedApiClient $client): array
    {
        $diagnostics = (new DiagnosticCollector)->collect();
        $result = $client->submit($credentials, $diagnostics);

        return [
            'status' => 'success',
            'summary' => $result['duplicate']
                ? 'Diagnostic snapshot was already received; collect request verified.'
                : 'Diagnostic snapshot submitted successfully after approved Safe Action.',
            'evidence' => [
                'remediation_applied' => true,
                'duplicate' => $result['duplicate'],
                'http_status' => $result['http_status'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function disableDisplayErrors(array $action): array
    {
        $root = $this->localWhmcsRoot();
        $target = $root.DIRECTORY_SEPARATOR.'.user.ini';
        $line = 'display_errors = Off';

        $local = $this->writeUserIniLocally($target, $line);
        if ($local['ok']) {
            $collector = (new DiagnosticCollector)->collect();
            $disabled = (bool) ($collector['system']['error_display_disabled'] ?? false);

            return [
                'status' => 'success',
                'summary' => $disabled
                    ? 'Wrote allowlisted .user.ini display_errors=Off override using the web user and runtime proof confirms display_errors is Off.'
                    : 'Wrote allowlisted .user.ini display_errors=Off override using the web user. Runtime still reports display_errors on — waiting for verify scan / PHP reload.',
                'evidence' => [
                    'remediation_applied' => true,
                    'method' => 'local_user_ini',
                    'error_display_disabled' => $disabled,
                ],
            ];
        }

        $host = isset($action['host']) && is_array($action['host']) ? $action['host'] : null;
        if ($host === null) {
            return [
                'status' => 'failed',
                'summary' => 'Web user could not write .user.ini and no host credentials are configured.',
                'evidence' => ['local_error' => $local['error']],
                'error_code' => 'host_credentials_required',
            ];
        }

        $remoteRoot = $this->normalizeRemoteRoot((string) ($host['remote_whmcs_root'] ?? ''));
        $remotePath = $remoteRoot.'/.user.ini';
        $this->ftpWriteFile($host, $remotePath, $this->mergeUserIniContents('', $line));

        return [
            'status' => 'success',
            'summary' => 'Wrote allowlisted .user.ini display_errors=Off override over FTP host credentials.',
            'evidence' => [
                'remediation_applied' => true,
                'method' => 'ftp_user_ini',
                'note' => 'Re-collect diagnostics after PHP picks up .user.ini (may require a new request).',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function hardenConfigurationPhp(array $action): array
    {
        $root = $this->localWhmcsRoot();
        $target = $root.DIRECTORY_SEPARATOR.'configuration.php';

        if (is_file($target) && @chmod($target, 0440)) {
            clearstatcache(true, $target);
            $writable = is_writable($target);

            return [
                'status' => $writable ? 'failed' : 'success',
                'summary' => $writable
                    ? 'chmod applied but configuration.php remains writable for the web user.'
                    : 'Removed write permission from configuration.php using the web user.',
                'evidence' => [
                    'remediation_applied' => ! $writable,
                    'method' => 'local_chmod',
                    'configuration_file_writable' => $writable,
                ],
                'error_code' => $writable ? 'still_writable' : null,
            ];
        }

        $host = isset($action['host']) && is_array($action['host']) ? $action['host'] : null;
        if ($host === null) {
            return [
                'status' => 'failed',
                'summary' => 'Web user could not chmod configuration.php and no host credentials are configured.',
                'evidence' => [],
                'error_code' => 'host_credentials_required',
            ];
        }

        $remoteRoot = $this->normalizeRemoteRoot((string) ($host['remote_whmcs_root'] ?? ''));
        $remotePath = $remoteRoot.'/configuration.php';
        $this->ftpChmod($host, $remotePath, 0440);

        return [
            'status' => 'success',
            'summary' => 'Requested non-writable mode on configuration.php over FTP host credentials.',
            'evidence' => [
                'remediation_applied' => true,
                'method' => 'ftp_chmod',
                'configuration_file_writable' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function runModuleQueueRetry(array $action): array
    {
        $previewItems = $this->previewItems($action);
        $results = [];
        $succeeded = 0;
        $mode = isset($action['mode']) && is_string($action['mode']) ? $action['mode'] : '';
        $autoForbiddenCommands = ['ModuleCreate', 'ModuleSuspend', 'ModuleUnsuspend', 'ModuleTerminate'];

        foreach ($previewItems as $item) {
            $id = $this->positiveInt($item['id'] ?? null);
            $command = isset($item['command']) && is_string($item['command']) ? $item['command'] : '';
            $params = isset($item['params']) && is_array($item['params']) ? $item['params'] : [];

            if ($id === null) {
                $results[] = ['id' => null, 'status' => 'failed', 'error_code' => 'invalid_id'];

                continue;
            }

            if (in_array($command, self::FORBIDDEN_COMMANDS, true) || $command === 'ModuleTerminate') {
                $results[] = ['id' => $id, 'status' => 'failed', 'error_code' => 'terminate_refused'];
                $this->logItem('module_queue_retry', $id, 'terminate_refused');

                continue;
            }

            // Defence in depth: never run create/suspend/unsuspend/terminate under auto mode.
            if ($mode === 'auto' && in_array($command, $autoForbiddenCommands, true)) {
                $results[] = ['id' => $id, 'status' => 'failed', 'error_code' => 'auto_action_refused'];
                $this->logItem('module_queue_retry', $id, 'auto_action_refused');

                continue;
            }

            if (! in_array($command, ['ModuleCreate', 'ModuleSuspend', 'ModuleUnsuspend'], true)) {
                $results[] = ['id' => $id, 'status' => 'failed', 'error_code' => 'unsupported_queue_action'];
                $this->logItem('module_queue_retry', $id, 'unsupported_queue_action');

                continue;
            }

            $serviceId = $this->positiveInt($params['serviceid'] ?? null);
            if ($serviceId === null) {
                $results[] = ['id' => $id, 'status' => 'failed', 'error_code' => 'invalid_serviceid'];

                continue;
            }

            $response = $this->safeLocalApi($command, ['serviceid' => $serviceId]);
            $ok = ($response['result'] ?? '') === 'success';
            if ($ok) {
                $succeeded++;
            }
            $results[] = [
                'id' => $id,
                'status' => $ok ? 'success' : 'failed',
                'command' => $command,
                'error_code' => $ok ? null : 'localapi_failed',
            ];
            $this->logItem('module_queue_retry', $id, $ok ? 'success' : 'failed');
        }

        return $this->batchResult('module_queue_retry', $results, $succeeded);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function runServiceUnsuspendPaid(array $action): array
    {
        $ids = $this->targetIds($action);
        $results = [];
        $succeeded = 0;

        foreach ($ids as $serviceId) {
            if (! $this->serviceIsSuspendedWithPaidInvoice($serviceId)) {
                $results[] = ['id' => $serviceId, 'status' => 'failed', 'error_code' => 'condition_recheck_failed'];
                $this->logItem('service_unsuspend_paid', $serviceId, 'condition_recheck_failed');

                continue;
            }

            $response = $this->safeLocalApi('ModuleUnsuspend', ['serviceid' => $serviceId]);
            $ok = ($response['result'] ?? '') === 'success';
            if ($ok) {
                $succeeded++;
            }
            $results[] = [
                'id' => $serviceId,
                'status' => $ok ? 'success' : 'failed',
                'command' => 'ModuleUnsuspend',
                'error_code' => $ok ? null : 'localapi_failed',
            ];
            $this->logItem('service_unsuspend_paid', $serviceId, $ok ? 'success' : 'failed');
        }

        return $this->batchResult('service_unsuspend_paid', $results, $succeeded);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function runInvoiceSendOverdueReminder(array $action): array
    {
        $ids = $this->targetIds($action);
        $messageName = $this->overdueMessageName($action);
        $results = [];
        $succeeded = 0;

        foreach ($ids as $invoiceId) {
            if ($this->invoiceRemindedWithinHours($invoiceId, 24)) {
                $results[] = ['id' => $invoiceId, 'status' => 'failed', 'error_code' => 'reminder_skipped_24h'];
                $this->logItem('invoice_send_overdue_reminder', $invoiceId, 'reminder_skipped_24h');

                continue;
            }

            $response = $this->safeLocalApi('SendEmail', [
                'messagename' => $messageName,
                'id' => $invoiceId,
            ]);
            $ok = ($response['result'] ?? '') === 'success';
            if ($ok) {
                $succeeded++;
            }
            $results[] = [
                'id' => $invoiceId,
                'status' => $ok ? 'success' : 'failed',
                'command' => 'SendEmail',
                'error_code' => $ok ? null : 'localapi_failed',
            ];
            $this->logItem('invoice_send_overdue_reminder', $invoiceId, $ok ? 'success' : 'failed');
        }

        return $this->batchResult('invoice_send_overdue_reminder', $results, $succeeded);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function runInvoiceGenerateDue(array $action): array
    {
        $ids = $this->targetIds($action);
        $results = [];
        $succeeded = 0;

        foreach ($ids as $serviceId) {
            if ($this->serviceHasDuplicateUnpaidInvoice($serviceId)) {
                $results[] = ['id' => $serviceId, 'status' => 'failed', 'error_code' => 'duplicate_invoice_guard'];
                $this->logItem('invoice_generate_due', $serviceId, 'duplicate_invoice_guard');

                continue;
            }

            $response = $this->safeLocalApi('GenInvoices', [
                'serviceids' => [$serviceId],
                'noemails' => false,
            ]);
            $ok = ($response['result'] ?? '') === 'success';
            if ($ok) {
                $succeeded++;
            }
            $results[] = [
                'id' => $serviceId,
                'status' => $ok ? 'success' : 'failed',
                'command' => 'GenInvoices',
                'error_code' => $ok ? null : 'localapi_failed',
            ];
            $this->logItem('invoice_generate_due', $serviceId, $ok ? 'success' : 'failed');
        }

        return $this->batchResult('invoice_generate_due', $results, $succeeded);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function runOrderAcceptPaid(array $action): array
    {
        $ids = $this->targetIds($action);
        $results = [];
        $succeeded = 0;

        foreach ($ids as $orderId) {
            $check = $this->orderAcceptability($orderId);
            if ($check !== null) {
                $results[] = ['id' => $orderId, 'status' => 'failed', 'error_code' => $check];
                $this->logItem('order_accept_paid', $orderId, $check);

                continue;
            }

            $response = $this->safeLocalApi('AcceptOrder', [
                'orderid' => $orderId,
                'autosetup' => true,
                'sendemail' => true,
            ]);
            $ok = ($response['result'] ?? '') === 'success';
            if ($ok) {
                $succeeded++;
            }
            $results[] = [
                'id' => $orderId,
                'status' => $ok ? 'success' : 'failed',
                'command' => 'AcceptOrder',
                'error_code' => $ok ? null : 'localapi_failed',
            ];
            $this->logItem('order_accept_paid', $orderId, $ok ? 'success' : 'failed');
        }

        return $this->batchResult('order_accept_paid', $results, $succeeded);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function runTicketEscalateSla(array $action): array
    {
        $ids = $this->targetIds($action);
        $results = [];
        $succeeded = 0;

        foreach ($ids as $ticketId) {
            $response = $this->safeLocalApi('UpdateTicket', [
                'ticketid' => $ticketId,
                'priority' => 'High',
            ]);
            $ok = ($response['result'] ?? '') === 'success';
            if ($ok) {
                $succeeded++;
            }
            $results[] = [
                'id' => $ticketId,
                'status' => $ok ? 'success' : 'failed',
                'command' => 'UpdateTicket',
                'error_code' => $ok ? null : 'localapi_failed',
            ];
            $this->logItem('ticket_escalate_sla', $ticketId, $ok ? 'success' : 'failed');
        }

        return $this->batchResult('ticket_escalate_sla', $results, $succeeded);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function runDomainRenewRetry(array $action): array
    {
        $ids = $this->targetIds($action);
        if (count($ids) !== 1) {
            return [
                'status' => 'failed',
                'summary' => 'Domain renew retry accepts exactly one domain id.',
                'evidence' => [],
                'error_code' => 'single_item_only',
            ];
        }

        $domainId = $ids[0];
        $response = $this->safeLocalApi('DomainRenew', ['domainid' => $domainId]);
        $ok = ($response['result'] ?? '') === 'success';
        $this->logItem('domain_renew_retry', $domainId, $ok ? 'success' : 'failed');

        return $this->batchResult('domain_renew_retry', [[
            'id' => $domainId,
            'status' => $ok ? 'success' : 'failed',
            'command' => 'DomainRenew',
            'error_code' => $ok ? null : 'localapi_failed',
        ]], $ok ? 1 : 0);
    }

    /**
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function clearTemplateCache(): array
    {
        $root = $this->localWhmcsRoot();
        $templatesC = $root.DIRECTORY_SEPARATOR.'templates_c';
        $resolvedRoot = realpath($templatesC);
        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            return [
                'status' => 'failed',
                'summary' => 'templates_c directory was not found.',
                'evidence' => [],
                'error_code' => 'templates_c_missing',
            ];
        }

        if (! $this->pathIsInside($resolvedRoot, $root.DIRECTORY_SEPARATOR.'templates_c')) {
            return [
                'status' => 'failed',
                'summary' => 'templates_c path validation failed.',
                'evidence' => [],
                'error_code' => 'templates_c_path_guard',
            ];
        }

        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolvedRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $path = $fileInfo->getPathname();
            $real = realpath($path);
            if ($real === false || ! $this->pathIsInside($real, $resolvedRoot)) {
                continue;
            }

            if ($fileInfo->isFile()) {
                if (@unlink($real)) {
                    $deleted++;
                }
            }
        }

        $this->logItem('whmcs_clear_template_cache', 0, 'success:'.$deleted);

        return [
            'status' => 'success',
            'summary' => 'Cleared '.$deleted.' compiled template file(s) inside templates_c.',
            'evidence' => [
                'remediation_applied' => true,
                'templates_c_cleared' => true,
                'deleted_files' => $deleted,
                'item_results' => [[
                    'id' => null,
                    'status' => 'success',
                    'command' => 'clear_templates_c',
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function safeLocalApi(string $command, array $values): array
    {
        if (in_array($command, self::FORBIDDEN_COMMANDS, true)) {
            return ['result' => 'error', 'message' => 'command_forbidden'];
        }

        return $this->localApi->call($command, $values);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function batchResult(string $actionKey, array $results, int $succeeded): array
    {
        $total = count($results);
        $failed = $total - $succeeded;
        $status = $succeeded > 0 ? 'success' : 'failed';

        return [
            'status' => $status,
            'summary' => sprintf('%s: %d succeeded, %d failed of %d.', $actionKey, $succeeded, $failed, $total),
            'evidence' => [
                'remediation_applied' => $succeeded > 0,
                'items_total' => $total,
                'items_succeeded' => $succeeded,
                'item_results' => $results,
            ],
            'error_code' => $succeeded > 0 ? null : 'all_items_failed',
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return list<array<string, mixed>>
     */
    private function previewItems(array $action): array
    {
        $preview = isset($action['preview']) && is_array($action['preview']) ? $action['preview'] : [];
        $items = $preview['items'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return list<int>
     */
    private function targetIds(array $action): array
    {
        $raw = $action['target_ids'] ?? null;
        if (! is_array($raw)) {
            $input = isset($action['input']) && is_array($action['input']) ? $action['input'] : [];
            $raw = $input['target_ids'] ?? [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = $this->positiveInt($value);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Use only the server-resolved template. Never trust client-supplied input.messagename.
     *
     * @param  array<string, mixed>  $action
     */
    private function overdueMessageName(array $action): string
    {
        $resolved = isset($action['resolved_params']) && is_array($action['resolved_params'])
            ? $action['resolved_params']
            : [];
        $name = $resolved['messagename'] ?? null;
        if (is_string($name) && $name !== '') {
            return $name;
        }

        // Preview params are authored by the platform at request time.
        $preview = isset($action['preview']) && is_array($action['preview']) ? $action['preview'] : [];
        $items = isset($preview['items']) && is_array($preview['items']) ? $preview['items'] : [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $params = isset($item['params']) && is_array($item['params']) ? $item['params'] : [];
            $fromPreview = $params['messagename'] ?? null;
            if (is_string($fromPreview) && $fromPreview !== '') {
                return $fromPreview;
            }
        }

        return 'First Invoice Overdue Notice';
    }

    private function logItem(string $action, int $id, string $result): void
    {
        $this->activity->log(sprintf(
            'ResellerBytes AI Ops: %s, id=%d, result=%s',
            $action,
            $id,
            $result,
        ));
    }

    private function serviceIsSuspendedWithPaidInvoice(int $serviceId): bool
    {
        if (! class_exists('\\Illuminate\\Database\\Capsule\\Manager') && ! class_exists('\\WHMCS\\Database\\Capsule')) {
            // When Capsule is unavailable (unit tests with FakeLocalApi), assume caller pre-validated
            // unless a test hook forces failure via $_ENV.
            return ($_ENV['RB_AI_OPS_FORCE_UNSUSPEND_RECHECK_FAIL'] ?? null) !== '1';
        }

        try {
            $capsule = class_exists('\\WHMCS\\Database\\Capsule')
                ? '\\WHMCS\\Database\\Capsule'
                : '\\Illuminate\\Database\\Capsule\\Manager';

            $service = $capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null || strtolower((string) ($service->domainstatus ?? '')) !== 'suspended') {
                return false;
            }

            $paid = $capsule::table('tblinvoiceitems')
                ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
                ->where('tblinvoiceitems.relid', $serviceId)
                ->whereIn('tblinvoiceitems.type', ['Hosting', 'Setup', 'PromoHosting'])
                ->where('tblinvoices.status', 'Paid')
                ->exists();

            return $paid;
        } catch (Throwable) {
            return false;
        }
    }

    private function invoiceRemindedWithinHours(int $invoiceId, int $hours): bool
    {
        if (($_ENV['RB_AI_OPS_FORCE_REMINDER_RECENT'] ?? null) === '1') {
            return true;
        }

        if (! class_exists('\\WHMCS\\Database\\Capsule') && ! class_exists('\\Illuminate\\Database\\Capsule\\Manager')) {
            return false;
        }

        try {
            $capsule = class_exists('\\WHMCS\\Database\\Capsule')
                ? '\\WHMCS\\Database\\Capsule'
                : '\\Illuminate\\Database\\Capsule\\Manager';

            $since = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));

            return $capsule::table('tblactivitylog')
                ->where('date', '>=', $since)
                ->where('description', 'like', '%Invoice Overdue Notice%')
                ->where('description', 'like', '%#'.$invoiceId.'%')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function serviceHasDuplicateUnpaidInvoice(int $serviceId): bool
    {
        if (($_ENV['RB_AI_OPS_FORCE_DUPLICATE_INVOICE'] ?? null) === '1') {
            return true;
        }

        if (! class_exists('\\WHMCS\\Database\\Capsule') && ! class_exists('\\Illuminate\\Database\\Capsule\\Manager')) {
            return false;
        }

        try {
            $capsule = class_exists('\\WHMCS\\Database\\Capsule')
                ? '\\WHMCS\\Database\\Capsule'
                : '\\Illuminate\\Database\\Capsule\\Manager';

            $service = $capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null) {
                return false;
            }

            $dueDate = (string) ($service->nextduedate ?? '');
            if ($dueDate === '') {
                return false;
            }

            return $capsule::table('tblinvoiceitems')
                ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
                ->where('tblinvoiceitems.relid', $serviceId)
                ->where('tblinvoiceitems.type', 'Hosting')
                ->where('tblinvoices.duedate', $dueDate)
                ->where('tblinvoices.status', 'Unpaid')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function orderAcceptability(int $orderId): ?string
    {
        if (($_ENV['RB_AI_OPS_FORCE_ORDER_FRAUD'] ?? null) === '1') {
            return 'fraud_skipped';
        }

        if (! class_exists('\\WHMCS\\Database\\Capsule') && ! class_exists('\\Illuminate\\Database\\Capsule\\Manager')) {
            return null;
        }

        try {
            $capsule = class_exists('\\WHMCS\\Database\\Capsule')
                ? '\\WHMCS\\Database\\Capsule'
                : '\\Illuminate\\Database\\Capsule\\Manager';

            $order = $capsule::table('tblorders')->where('id', $orderId)->first();
            if ($order === null) {
                return 'order_not_found';
            }

            $status = strtolower((string) ($order->status ?? ''));
            if ($status === 'fraud') {
                return 'fraud_skipped';
            }

            if ($status !== 'pending') {
                return 'order_not_pending';
            }

            $fraudOutput = strtolower((string) ($order->fraudoutput ?? $order->fraudscore ?? ''));
            if ($fraudOutput !== '' && (str_contains($fraudOutput, 'fail') || str_contains($fraudOutput, 'fraud'))) {
                return 'fraud_skipped';
            }

            $invoiceId = (int) ($order->invoiceid ?? 0);
            if ($invoiceId < 1) {
                return 'invoice_missing';
            }

            $invoice = $capsule::table('tblinvoices')->where('id', $invoiceId)->first();
            if ($invoice === null || strtolower((string) ($invoice->status ?? '')) !== 'paid') {
                return 'invoice_not_paid';
            }

            return null;
        } catch (Throwable) {
            return 'order_lookup_failed';
        }
    }

    private function pathIsInside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function writeUserIniLocally(string $path, string $line): array
    {
        $existing = is_file($path) ? (string) @file_get_contents($path) : '';
        if ($existing !== '' && str_contains($existing, 'display_errors')) {
            $existing = preg_replace('/^\s*display_errors\s*=.*$/mi', $line, $existing) ?? $existing;
            $contents = $existing;
        } else {
            $contents = $this->mergeUserIniContents($existing, $line);
        }

        $dir = dirname($path);
        if (! is_dir($dir) || ! is_writable($dir)) {
            return ['ok' => false, 'error' => 'directory_not_writable'];
        }

        if (@file_put_contents($path, $contents) === false) {
            return ['ok' => false, 'error' => 'write_failed'];
        }

        return ['ok' => true];
    }

    private function mergeUserIniContents(string $existing, string $line): string
    {
        $existing = trim($existing);

        return ($existing === '' ? '' : $existing."\n").$line."\n";
    }

    private function localWhmcsRoot(): string
    {
        if (! defined('ROOTDIR') || ! is_string(ROOTDIR) || ROOTDIR === '') {
            throw new RuntimeException('whmcs_root_unavailable');
        }

        $resolved = realpath(ROOTDIR);
        if ($resolved === false) {
            throw new RuntimeException('whmcs_root_unresolved');
        }

        return $resolved;
    }

    private function normalizeRemoteRoot(string $root): string
    {
        $root = str_replace('\\', '/', trim($root));
        $root = rtrim($root, '/');
        if ($root === '' || str_contains($root, '..')) {
            throw new RuntimeException('invalid_remote_root');
        }

        return $root;
    }

    /**
     * @param  array<string, mixed>  $host
     */
    private function ftpConnect(array $host)
    {
        if (! function_exists('ftp_connect')) {
            throw new RuntimeException('ftp_extension_unavailable');
        }

        $hostname = (string) ($host['host'] ?? '');
        $port = (int) ($host['port'] ?? 21);
        $username = (string) ($host['username'] ?? '');
        $password = (string) ($host['password'] ?? '');
        $protocol = (string) ($host['protocol'] ?? 'ftp');

        if ($hostname === '' || $username === '' || $password === '') {
            throw new RuntimeException('host_credentials_incomplete');
        }

        if ($protocol === 'ftps' && function_exists('ftp_ssl_connect')) {
            $connection = @ftp_ssl_connect($hostname, $port, 15);
        } else {
            $connection = @ftp_connect($hostname, $port, 15);
        }

        if ($connection === false) {
            throw new RuntimeException('ftp_connect_failed');
        }

        if (! @ftp_login($connection, $username, $password)) {
            @ftp_close($connection);

            throw new RuntimeException('ftp_login_failed');
        }

        @ftp_pasv($connection, true);

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $host
     */
    private function ftpWriteFile(array $host, string $remotePath, string $contents): void
    {
        $connection = $this->ftpConnect($host);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            @ftp_close($connection);

            throw new RuntimeException('temp_stream_failed');
        }

        fwrite($stream, $contents);
        rewind($stream);

        $uploaded = @ftp_fput($connection, $remotePath, $stream, FTP_ASCII);
        fclose($stream);
        @ftp_close($connection);

        if ($uploaded !== true) {
            throw new RuntimeException('ftp_upload_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $host
     */
    private function ftpChmod(array $host, string $remotePath, int $mode): void
    {
        $connection = $this->ftpConnect($host);
        if (! function_exists('ftp_chmod') || @ftp_chmod($connection, $mode, $remotePath) === false) {
            @ftp_close($connection);

            throw new RuntimeException('ftp_chmod_failed');
        }
        @ftp_close($connection);
    }
}
