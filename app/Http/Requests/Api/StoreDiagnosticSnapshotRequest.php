<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDiagnosticSnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('whmcs_integration');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $schema = (string) $this->input('schema_version');
        $rules = [
            'snapshot_uuid' => ['required', 'uuid'],
            'schema_version' => ['required', 'string', 'in:1.0,1.1'],
            'addon_version' => ['required', 'string', 'max:40', 'regex:/\A[0-9A-Za-z.+_-]+\z/'],
            'collected_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
            'payload' => ['required', 'array'],
            'payload.system' => ['required', 'array'],
            'payload.system.whmcs_version' => ['required', 'string', 'max:32', 'regex:/\A(?:[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.]+)?|unknown)\z/'],
            'payload.system.web_php_version' => ['required', 'string', 'max:32', 'regex:/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.]+)?\z/'],
            'payload.system.database_version' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._+-]+\z/'],
            'payload.system.web_server' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'payload.system.application_url_configured' => ['sometimes', 'boolean'],
            'payload.system.error_display_disabled' => ['present', 'nullable', 'boolean'],
            'payload.system.configuration_file_writable' => ['present', 'nullable', 'boolean'],
            'payload.php' => ['required', 'array'],
            'payload.php.memory_limit' => ['required', 'string', 'max:32', 'regex:/\A(?:-?[0-9]+[KMG]?|unknown)\z/'],
            'payload.php.extensions' => ['required', 'array'],
            'payload.php.extensions.*' => ['boolean'],
            'payload.warnings' => ['sometimes', 'array', 'max:10'],
            'payload.warnings.*' => ['required', 'array:code,severity'],
            'payload.warnings.*.code' => ['required', 'string', 'in:configuration_file_writable'],
            'payload.warnings.*.severity' => ['required', 'string', 'in:warning'],
            'payload.collector' => ['required', 'array:capabilities,limitations'],
            'payload.collector.capabilities' => ['present', 'array', 'max:40'],
            'payload.collector.capabilities.*' => ['required', 'string', 'max:64'],
            'payload.collector.limitations' => ['present', 'array', 'max:40'],
            'payload.collector.limitations.*' => ['required', 'string', 'max:64'],
        ];

        if ($schema === '1.1') {
            $rules = array_merge($rules, $this->schemaElevenRules());
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaElevenRules(): array
    {
        $decimal = ['sometimes', 'nullable', 'string', 'max:32', 'regex:/\A[0-9]+(?:\.[0-9]{1,4})?\z/'];
        $idList = ['sometimes', 'array', 'max:50'];
        $idItem = ['integer', 'min:1'];

        return [
            'payload.php.ioncube_version' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/\A[0-9]+(?:\.[0-9]+){0,3}\z/'],
            'payload.php.max_execution_time' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            'payload.security' => ['sometimes', 'nullable', 'array:admin_dir_default,install_dir_present'],
            'payload.security.admin_dir_default' => ['sometimes', 'nullable', 'boolean'],
            'payload.security.install_dir_present' => ['sometimes', 'nullable', 'boolean'],
            'payload.host' => ['sometimes', 'nullable', 'array:disk_free_percent,disk_free_bytes,db_size_mb,db_top_tables'],
            'payload.host.disk_free_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'payload.host.disk_free_bytes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'payload.host.db_size_mb' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payload.host.db_top_tables' => ['sometimes', 'nullable', 'array', 'max:5'],
            'payload.host.db_top_tables.*' => ['required', 'array:name,size_mb'],
            'payload.host.db_top_tables.*.name' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_]+\z/'],
            'payload.host.db_top_tables.*.size_mb' => ['required', 'numeric', 'min:0'],
            'payload.whmcs' => ['sometimes', 'nullable', 'array:cron,module_queue,billing,services,domains,tickets,mail,gateways,modules,automation'],
            'payload.whmcs.cron' => ['sometimes', 'nullable', 'array:last_daily_run_at,daily_starts_24h,daily_completions_24h'],
            'payload.whmcs.cron.last_daily_run_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:s\Z'],
            'payload.whmcs.cron.daily_starts_24h' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'payload.whmcs.cron.daily_completions_24h' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'payload.whmcs.module_queue' => ['sometimes', 'nullable', 'array:source,pending_count,oldest_age_minutes,by_action,items'],
            'payload.whmcs.module_queue.source' => ['required_with:payload.whmcs.module_queue', 'string', 'in:api,table'],
            'payload.whmcs.module_queue.pending_count' => ['required_with:payload.whmcs.module_queue', 'integer', 'min:0', 'max:100000'],
            'payload.whmcs.module_queue.oldest_age_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5256000'],
            'payload.whmcs.module_queue.by_action' => ['required_with:payload.whmcs.module_queue', 'array:create,suspend,unsuspend,terminate,other'],
            'payload.whmcs.module_queue.by_action.create' => ['required_with:payload.whmcs.module_queue', 'integer', 'min:0', 'max:100000'],
            'payload.whmcs.module_queue.by_action.suspend' => ['required_with:payload.whmcs.module_queue', 'integer', 'min:0', 'max:100000'],
            'payload.whmcs.module_queue.by_action.unsuspend' => ['required_with:payload.whmcs.module_queue', 'integer', 'min:0', 'max:100000'],
            'payload.whmcs.module_queue.by_action.terminate' => ['required_with:payload.whmcs.module_queue', 'integer', 'min:0', 'max:100000'],
            'payload.whmcs.module_queue.by_action.other' => ['required_with:payload.whmcs.module_queue', 'integer', 'min:0', 'max:100000'],
            'payload.whmcs.module_queue.items' => ['sometimes', 'array', 'max:50'],
            'payload.whmcs.module_queue.items.*' => ['required', 'array:id,service_type,service_id,action,age_minutes,retries,error'],
            'payload.whmcs.module_queue.items.*.id' => ['required', 'integer', 'min:1'],
            'payload.whmcs.module_queue.items.*.service_type' => ['required', 'string', 'in:service,domain,addon'],
            'payload.whmcs.module_queue.items.*.service_id' => ['required', 'integer', 'min:1'],
            'payload.whmcs.module_queue.items.*.action' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'payload.whmcs.module_queue.items.*.age_minutes' => ['required', 'integer', 'min:0', 'max:5256000'],
            'payload.whmcs.module_queue.items.*.retries' => ['required', 'integer', 'min:0', 'max:10000'],
            'payload.whmcs.module_queue.items.*.error' => ['required', 'string', 'max:200'],
            'payload.whmcs.billing' => ['sometimes', 'nullable', 'array:overdue_unpaid_count,overdue_unpaid_total,overdue_without_reminder,due_services_without_invoice,duplicate_invoice_groups_30d,recovered_paid_7d_total'],
            'payload.whmcs.billing.overdue_unpaid_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.billing.overdue_unpaid_total' => $decimal,
            'payload.whmcs.billing.overdue_without_reminder' => ['sometimes', 'nullable', 'array:count,invoice_ids'],
            'payload.whmcs.billing.overdue_without_reminder.count' => ['required_with:payload.whmcs.billing.overdue_without_reminder', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.billing.overdue_without_reminder.invoice_ids' => $idList,
            'payload.whmcs.billing.overdue_without_reminder.invoice_ids.*' => $idItem,
            'payload.whmcs.billing.due_services_without_invoice' => ['sometimes', 'nullable', 'array:count,service_ids'],
            'payload.whmcs.billing.due_services_without_invoice.count' => ['required_with:payload.whmcs.billing.due_services_without_invoice', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.billing.due_services_without_invoice.service_ids' => $idList,
            'payload.whmcs.billing.due_services_without_invoice.service_ids.*' => $idItem,
            'payload.whmcs.billing.duplicate_invoice_groups_30d' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.billing.recovered_paid_7d_total' => $decimal,
            'payload.whmcs.services' => ['sometimes', 'nullable', 'array:suspended_with_paid_invoices,active_past_suspend_grace,orphan,pending_paid_orders'],
            'payload.whmcs.services.suspended_with_paid_invoices' => ['sometimes', 'nullable', 'array:count,service_ids'],
            'payload.whmcs.services.suspended_with_paid_invoices.count' => ['required_with:payload.whmcs.services.suspended_with_paid_invoices', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.services.suspended_with_paid_invoices.service_ids' => $idList,
            'payload.whmcs.services.suspended_with_paid_invoices.service_ids.*' => $idItem,
            'payload.whmcs.services.active_past_suspend_grace' => ['sometimes', 'nullable', 'array:count,service_ids'],
            'payload.whmcs.services.active_past_suspend_grace.count' => ['required_with:payload.whmcs.services.active_past_suspend_grace', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.services.active_past_suspend_grace.service_ids' => $idList,
            'payload.whmcs.services.active_past_suspend_grace.service_ids.*' => $idItem,
            'payload.whmcs.services.orphan' => ['sometimes', 'nullable', 'array:count,service_ids'],
            'payload.whmcs.services.orphan.count' => ['required_with:payload.whmcs.services.orphan', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.services.orphan.service_ids' => $idList,
            'payload.whmcs.services.orphan.service_ids.*' => $idItem,
            'payload.whmcs.services.pending_paid_orders' => ['sometimes', 'nullable', 'array:count,order_ids,oldest_age_minutes'],
            'payload.whmcs.services.pending_paid_orders.count' => ['required_with:payload.whmcs.services.pending_paid_orders', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.services.pending_paid_orders.order_ids' => $idList,
            'payload.whmcs.services.pending_paid_orders.order_ids.*' => $idItem,
            'payload.whmcs.services.pending_paid_orders.oldest_age_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5256000'],
            'payload.whmcs.domains' => ['sometimes', 'nullable', 'array:active_expired,bad_expiry,sync_errors_7d,renewal_failed_7d'],
            'payload.whmcs.domains.active_expired' => ['sometimes', 'nullable', 'array:count,domain_ids'],
            'payload.whmcs.domains.active_expired.count' => ['required_with:payload.whmcs.domains.active_expired', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.domains.active_expired.domain_ids' => $idList,
            'payload.whmcs.domains.active_expired.domain_ids.*' => $idItem,
            'payload.whmcs.domains.bad_expiry' => ['sometimes', 'nullable', 'array:count,domain_ids'],
            'payload.whmcs.domains.bad_expiry.count' => ['required_with:payload.whmcs.domains.bad_expiry', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.domains.bad_expiry.domain_ids' => $idList,
            'payload.whmcs.domains.bad_expiry.domain_ids.*' => $idItem,
            'payload.whmcs.domains.sync_errors_7d' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.domains.renewal_failed_7d' => ['sometimes', 'nullable', 'array:count,domain_ids'],
            'payload.whmcs.domains.renewal_failed_7d.count' => ['required_with:payload.whmcs.domains.renewal_failed_7d', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.domains.renewal_failed_7d.domain_ids' => $idList,
            'payload.whmcs.domains.renewal_failed_7d.domain_ids.*' => $idItem,
            'payload.whmcs.tickets' => ['sometimes', 'nullable', 'array:threshold_hours,sla_breach'],
            'payload.whmcs.tickets.threshold_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:8760'],
            'payload.whmcs.tickets.sla_breach' => ['sometimes', 'nullable', 'array:count,ticket_ids,oldest_hours'],
            'payload.whmcs.tickets.sla_breach.count' => ['required_with:payload.whmcs.tickets.sla_breach', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.tickets.sla_breach.ticket_ids' => $idList,
            'payload.whmcs.tickets.sla_breach.ticket_ids.*' => $idItem,
            'payload.whmcs.tickets.sla_breach.oldest_hours' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:876000'],
            'payload.whmcs.mail' => ['sometimes', 'nullable', 'array:piping_failed_7d,sending_failed_24h'],
            'payload.whmcs.mail.piping_failed_7d' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.mail.sending_failed_24h' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'payload.whmcs.gateways' => ['sometimes', 'nullable', 'array:active,failures_7d,signature_failures_7d,unmatched_success_7d'],
            'payload.whmcs.gateways.active' => ['sometimes', 'nullable', 'array', 'max:30'],
            'payload.whmcs.gateways.active.*' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._ -]+\z/'],
            'payload.whmcs.gateways.failures_7d' => ['sometimes', 'nullable', 'array', 'max:30'],
            'payload.whmcs.gateways.failures_7d.*' => ['integer', 'min:0', 'max:1000000'],
            'payload.whmcs.gateways.signature_failures_7d' => ['sometimes', 'nullable', 'array', 'max:30'],
            'payload.whmcs.gateways.signature_failures_7d.*' => ['integer', 'min:0', 'max:1000000'],
            'payload.whmcs.gateways.unmatched_success_7d' => ['sometimes', 'nullable', 'array', 'max:30'],
            'payload.whmcs.gateways.unmatched_success_7d.*' => ['integer', 'min:0', 'max:1000000'],
            'payload.whmcs.modules' => ['sometimes', 'nullable', 'array:registrars,custom_template_overrides,custom_hooks_count'],
            'payload.whmcs.modules.registrars' => ['sometimes', 'nullable', 'array', 'max:30'],
            'payload.whmcs.modules.registrars.*' => ['required', 'array:name,version'],
            'payload.whmcs.modules.registrars.*.name' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._ -]+\z/'],
            'payload.whmcs.modules.registrars.*.version' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/\A[0-9A-Za-z.+_-]+\z/'],
            'payload.whmcs.modules.custom_template_overrides' => ['sometimes', 'nullable', 'boolean'],
            'payload.whmcs.modules.custom_hooks_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'payload.whmcs.automation' => ['sometimes', 'nullable', 'array:suspend_days,invoice_generation_days'],
            'payload.whmcs.automation.suspend_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'payload.whmcs.automation.invoice_generation_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unexpected = array_diff(
                    array_keys($this->all()),
                    ['snapshot_uuid', 'schema_version', 'addon_version', 'collected_at', 'payload'],
                );

                if ($unexpected !== []) {
                    $validator->errors()->add('payload', 'The request contains unsupported fields.');
                }

                $schema = (string) $this->input('schema_version');
                $payload = $this->input('payload');
                if (! is_array($payload)) {
                    return;
                }

                $allowedPayloadKeys = $schema === '1.1'
                    ? ['system', 'php', 'warnings', 'collector', 'security', 'whmcs', 'host']
                    : ['system', 'php', 'warnings', 'collector'];

                $unexpectedPayload = array_diff(array_keys($payload), $allowedPayloadKeys);
                if ($unexpectedPayload !== []) {
                    $validator->errors()->add('payload', 'The payload contains unsupported fields.');
                }

                $this->assertNoPiiLikeKeys($validator, $payload, 'payload');

                if ($schema === '1.0') {
                    $this->assertLegacySystemAndPhpKeys($validator, $payload);
                    $this->assertLegacyCollectorAllowlists($validator, $payload);
                } else {
                    $this->assertSchemaElevenSystemAndPhpKeys($validator, $payload);
                    $this->assertSchemaElevenCollectorAllowlists($validator, $payload);
                    $this->assertGatewayMapKeys($validator, $payload);
                }
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertLegacySystemAndPhpKeys(Validator $validator, array $payload): void
    {
        $system = $payload['system'] ?? null;
        if (is_array($system)) {
            $allowed = [
                'whmcs_version',
                'web_php_version',
                'database_version',
                'web_server',
                'application_url_configured',
                'error_display_disabled',
                'configuration_file_writable',
            ];
            $unexpected = array_diff(array_keys($system), $allowed);
            if ($unexpected !== []) {
                $validator->errors()->add('payload.system', 'The system block contains unsupported fields.');
            }
        }

        $php = $payload['php'] ?? null;
        if (is_array($php)) {
            $allowed = ['memory_limit', 'extensions'];
            $unexpected = array_diff(array_keys($php), $allowed);
            if ($unexpected !== []) {
                $validator->errors()->add('payload.php', 'The php block contains unsupported fields.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSchemaElevenSystemAndPhpKeys(Validator $validator, array $payload): void
    {
        $system = $payload['system'] ?? null;
        if (is_array($system)) {
            $allowed = [
                'whmcs_version',
                'web_php_version',
                'database_version',
                'web_server',
                'application_url_configured',
                'error_display_disabled',
                'configuration_file_writable',
            ];
            $unexpected = array_diff(array_keys($system), $allowed);
            if ($unexpected !== []) {
                $validator->errors()->add('payload.system', 'The system block contains unsupported fields.');
            }
        }

        $php = $payload['php'] ?? null;
        if (is_array($php)) {
            $allowed = ['memory_limit', 'extensions', 'ioncube_version', 'max_execution_time'];
            $unexpected = array_diff(array_keys($php), $allowed);
            if ($unexpected !== []) {
                $validator->errors()->add('payload.php', 'The php block contains unsupported fields.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function assertNoPiiLikeKeys(Validator $validator, array $node, string $path): void
    {
        $forbidden = [
            'email',
            'emails',
            'customer_email',
            'client_email',
            'password',
            'passwd',
            'secret',
            'token',
            'api_key',
            'address',
            'firstname',
            'lastname',
            'full_name',
            'client_name',
            'customer_name',
            'message',
            'message_body',
            'body',
            'phone',
            'phonenumber',
        ];

        foreach ($node as $key => $value) {
            $keyString = is_string($key) ? strtolower($key) : (string) $key;
            $childPath = $path.'.'.$keyString;

            if (in_array($keyString, $forbidden, true)) {
                $validator->errors()->add($childPath, 'The selected field is not allowed.');
            }

            if (is_array($value)) {
                $this->assertNoPiiLikeKeys($validator, $value, $childPath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertGatewayMapKeys(Validator $validator, array $payload): void
    {
        $gateways = $payload['whmcs']['gateways'] ?? null;
        if (! is_array($gateways)) {
            return;
        }

        foreach (['failures_7d', 'signature_failures_7d', 'unmatched_success_7d'] as $mapKey) {
            $map = $gateways[$mapKey] ?? null;
            if (! is_array($map)) {
                continue;
            }

            foreach (array_keys($map) as $name) {
                if (! is_string($name) || preg_match('/\A[A-Za-z0-9._ -]+\z/', $name) !== 1 || strlen($name) > 64) {
                    $validator->errors()->add("payload.whmcs.gateways.{$mapKey}", 'Gateway names must be allowlisted system identifiers.');
                    break;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertLegacyCollectorAllowlists(Validator $validator, array $payload): void
    {
        $capabilities = $payload['collector']['capabilities'] ?? null;
        $limitations = $payload['collector']['limitations'] ?? null;

        $allowedCapabilities = [
            'web_php_version',
            'whmcs_version',
            'database_server_version',
            'php_extension_availability',
            'php_memory_limit',
            'configuration_file_writability',
        ];
        $allowedLimitations = [
            'cron_php_version_not_observable',
            'module_status_not_collected',
            'hosting_panel_configuration_not_observable',
            'ssl_certificate_not_verified',
        ];

        if (is_array($capabilities)) {
            foreach ($capabilities as $index => $capability) {
                if (! in_array($capability, $allowedCapabilities, true)) {
                    $validator->errors()->add("payload.collector.capabilities.{$index}", 'The selected capability is invalid.');
                }
            }
        }

        if (is_array($limitations)) {
            foreach ($limitations as $index => $limitation) {
                if (! in_array($limitation, $allowedLimitations, true)) {
                    $validator->errors()->add("payload.collector.limitations.{$index}", 'The selected limitation is invalid.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSchemaElevenCollectorAllowlists(Validator $validator, array $payload): void
    {
        $capabilities = $payload['collector']['capabilities'] ?? null;
        $limitations = $payload['collector']['limitations'] ?? null;

        $allowedCapabilities = [
            'web_php_version',
            'whmcs_version',
            'database_server_version',
            'php_extension_availability',
            'php_memory_limit',
            'configuration_file_writability',
            'whmcs_cron',
            'module_queue',
            'billing_aggregates',
            'service_aggregates',
            'domain_aggregates',
            'ticket_aggregates',
            'mail_logs',
            'gateway_logs',
            'module_inventory',
            'host_disk',
            'db_sizes',
        ];
        $allowedLimitations = [
            'cron_php_version_not_observable',
            'module_status_not_collected',
            'hosting_panel_configuration_not_observable',
            'ssl_certificate_not_verified',
            'whmcs_db_unavailable',
            'local_api_unavailable',
            'cron_unavailable',
            'module_queue_unavailable',
            'billing_unavailable',
            'services_unavailable',
            'domains_unavailable',
            'tickets_unavailable',
            'mail_unavailable',
            'gateways_unavailable',
            'modules_unavailable',
            'host_disk_unavailable',
            'db_sizes_unavailable',
            'security_flags_unavailable',
        ];

        if (is_array($capabilities)) {
            foreach ($capabilities as $index => $capability) {
                if (! in_array($capability, $allowedCapabilities, true)) {
                    $validator->errors()->add("payload.collector.capabilities.{$index}", 'The selected capability is invalid.');
                }
            }
        }

        if (is_array($limitations)) {
            foreach ($limitations as $index => $limitation) {
                if (! in_array($limitation, $allowedLimitations, true)) {
                    $validator->errors()->add("payload.collector.limitations.{$index}", 'The selected limitation is invalid.');
                }
            }
        }
    }
}
