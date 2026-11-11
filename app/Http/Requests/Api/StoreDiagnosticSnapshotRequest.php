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
            'schema_version' => ['required', 'string', 'in:1.0'],
            'addon_version' => ['required', 'string', 'max:40', 'regex:/\A[0-9A-Za-z.+_-]+\z/'],
            'collected_at' => ['required', 'date'],
            'payload' => ['required', 'array:system,php,warnings,collector'],
            'payload.system' => ['required', 'array:whmcs_version,web_php_version,database_version,web_server,application_url_configured,error_display_disabled,configuration_file_writable'],
            'payload.system.whmcs_version' => ['required', 'string', 'max:32', 'regex:/\A(?:[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.]+)?|unknown)\z/'],
            'payload.system.web_php_version' => ['required', 'string', 'max:32', 'regex:/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.]+)?\z/'],
            'payload.system.database_version' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._+-]+\z/'],
            'payload.system.web_server' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'payload.system.application_url_configured' => ['sometimes', 'boolean'],
            'payload.system.error_display_disabled' => ['required', 'nullable', 'boolean'],
            'payload.system.configuration_file_writable' => ['required', 'nullable', 'boolean'],
            'payload.php' => ['required', 'array:memory_limit,extensions'],
            'payload.php.memory_limit' => ['required', 'string', 'max:32', 'regex:/\A-?[0-9]+[KMG]?\z|unknown/'],
            'payload.php.extensions' => ['required', 'array:curl,json,mbstring,openssl,pdo,pdo_mysql,soap,xml,gd,iconv,intl,zip,zlib,fileinfo,bcmath,ioncube_loader,sodium'],
            'payload.php.extensions.*' => ['boolean'],
            'payload.warnings' => ['sometimes', 'array', 'max:10'],
            'payload.warnings.*' => ['required', 'array:code,severity'],
            'payload.warnings.*.code' => ['required', 'string', 'in:configuration_file_writable'],
            'payload.warnings.*.severity' => ['required', 'string', 'in:warning'],
            'payload.collector' => ['required', 'array:capabilities,limitations'],
            'payload.collector.capabilities' => ['required', 'array', 'max:20'],
            'payload.collector.capabilities.*' => ['required', 'string', 'in:web_php_version,whmcs_version,database_server_version,php_extension_availability,php_memory_limit,configuration_file_writability'],
            'payload.collector.limitations' => ['required', 'array', 'max:20'],
            'payload.collector.limitations.*' => ['required', 'string', 'in:cron_php_version_not_observable,module_status_not_collected,hosting_panel_configuration_not_observable,ssl_certificate_not_verified'],
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
            },
        ];
    }
}
