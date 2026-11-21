<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreHeartbeatRequest extends FormRequest
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
            'addon_version' => ['required', 'string', 'max:40', 'regex:/\A[0-9A-Za-z.+_-]+\z/'],
            'whmcs_version' => ['required', 'string', 'max:32', 'regex:/\A(?:[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.]+)?|unknown)\z/'],
            'cli_php_version' => ['required', 'string', 'max:32', 'regex:/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.]+)?\z/'],
            'sent_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
            'cron_kind' => ['required', 'string', 'in:system,daily'],
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
                    ['addon_version', 'whmcs_version', 'cli_php_version', 'sent_at', 'cron_kind'],
                );

                if ($unexpected !== []) {
                    $validator->errors()->add('heartbeat', 'The request contains unsupported fields.');
                }
            },
        ];
    }
}
