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
            'payload' => ['required', 'array:system,php,cron,modules,warnings,collector'],
            'payload.system' => ['required', 'array:whmcs_version,web_php_version,cron_php_version,database_version,web_server,ssl_verified,event_handling_ok,timezone_matches,application_url_configured,storage_paths_accessible,sensitive_directories_protected,error_display_disabled,configuration_file_writable'],
            'payload.system.whmcs_version' => ['required', 'string', 'max:32'],
            'payload.system.web_php_version' => ['sometimes', 'nullable', 'string', 'max:32'],
            'payload.system.cron_php_version' => ['sometimes', 'nullable', 'string', 'max:32'],
            'payload.system.database_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payload.system.web_server' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payload.system.ssl_verified' => ['sometimes', 'boolean'],
            'payload.system.event_handling_ok' => ['sometimes', 'boolean'],
            'payload.system.timezone_matches' => ['sometimes', 'boolean'],
            'payload.system.application_url_configured' => ['sometimes', 'boolean'],
            'payload.system.storage_paths_accessible' => ['sometimes', 'boolean'],
            'payload.system.sensitive_directories_protected' => ['sometimes', 'boolean'],
            'payload.system.error_display_disabled' => ['sometimes', 'nullable', 'boolean'],
            'payload.system.configuration_file_writable' => ['sometimes', 'nullable', 'boolean'],
            'payload.php' => ['sometimes', 'array:memory_limit,extensions'],
            'payload.php.memory_limit' => ['sometimes', 'string', 'max:32'],
            'payload.php.extensions' => ['sometimes', 'array:curl,json,mbstring,openssl,pdo,pdo_mysql,soap,xml,gd,iconv,intl,zip,zlib,fileinfo,bcmath,ioncube_loader,sodium'],
            'payload.php.extensions.*' => ['boolean'],
            'payload.cron' => ['sometimes', 'array:last_success_at,last_run_status'],
            'payload.cron.last_success_at' => ['sometimes', 'nullable', 'date'],
            'payload.cron.last_run_status' => ['sometimes', 'string', 'in:success,failed,unknown'],
            'payload.modules' => ['sometimes', 'array', 'max:50'],
            'payload.modules.*' => ['required', 'array:identifier,status'],
            'payload.modules.*.identifier' => ['required', 'string', 'max:80', 'regex:/\A[a-zA-Z0-9_.-]+\z/'],
            'payload.modules.*.status' => ['required', 'string', 'in:enabled,disabled,unknown'],
            'payload.warnings' => ['sometimes', 'array', 'max:100'],
            'payload.warnings.*' => ['required', 'array:code,severity'],
            'payload.warnings.*.code' => ['required', 'string', 'in:configuration_file_writable,cron_php_mismatch,default_directories,default_cart_template,updater_temp_path_unwritable,module_debugging_enabled'],
            'payload.warnings.*.severity' => ['required', 'string', 'in:info,warning,critical'],
            'payload.collector' => ['sometimes', 'array:capabilities,limitations'],
            'payload.collector.capabilities' => ['sometimes', 'array', 'max:50'],
            'payload.collector.capabilities.*' => ['string', 'max:80', 'regex:/\A[a-zA-Z0-9_.-]+\z/'],
            'payload.collector.limitations' => ['sometimes', 'array', 'max:50'],
            'payload.collector.limitations.*' => ['string', 'max:80', 'regex:/\A[a-zA-Z0-9_.-]+\z/'],
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
