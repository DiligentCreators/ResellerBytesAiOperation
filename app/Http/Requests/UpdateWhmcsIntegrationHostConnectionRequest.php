<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWhmcsIntegrationHostConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clear' => ['sometimes', 'boolean'],
            'protocol' => ['required_without:clear', 'string', 'in:ftp,ftps'],
            'host' => ['required_without:clear', 'string', 'max:255'],
            'port' => ['required_without:clear', 'integer', 'min:1', 'max:65535'],
            'username' => ['required_without:clear', 'string', 'max:255'],
            'password' => ['required_without:clear', 'string', 'max:255'],
            'remote_whmcs_root' => ['required_without:clear', 'string', 'max:500'],
        ];
    }
}
