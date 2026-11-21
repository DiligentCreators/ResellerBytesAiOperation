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
        return [
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
            'payload.php.ioncube_version' => ['sometimes', 'nullable', 'string', 'max:32'],
            'payload.php.max_execution_time' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            'payload.warnings' => ['sometimes', 'array', 'max:10'],
            'payload.warnings.*' => ['required', 'array:code,severity'],
            'payload.warnings.*.code' => ['required', 'string', 'in:configuration_file_writable'],
            'payload.warnings.*.severity' => ['required', 'string', 'in:warning'],
            'payload.collector' => ['required', 'array:capabilities,limitations'],
            'payload.collector.capabilities' => ['required', 'array', 'max:40'],
            'payload.collector.capabilities.*' => ['required', 'string', 'max:64'],
            'payload.collector.limitations' => ['required', 'array', 'max:40'],
            'payload.collector.limitations.*' => ['required', 'string', 'max:64'],
            'payload.security' => ['sometimes', 'nullable', 'array'],
            'payload.security.admin_dir_default' => ['sometimes', 'nullable', 'boolean'],
            'payload.security.install_dir_present' => ['sometimes', 'nullable', 'boolean'],
            'payload.whmcs' => ['sometimes', 'nullable', 'array'],
            'payload.host' => ['sometimes', 'nullable', 'array'],
            'payload.host.disk_free_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'payload.host.disk_free_bytes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'payload.host.db_size_mb' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payload.host.db_top_tables' => ['sometimes', 'nullable', 'array', 'max:5'],
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

                if ($schema === '1.0') {
                    $this->assertLegacyCollectorAllowlists($validator, $payload);
                } else {
                    $this->assertSchemaElevenCollectorAllowlists($validator, $payload);
                }
            },
        ];
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
