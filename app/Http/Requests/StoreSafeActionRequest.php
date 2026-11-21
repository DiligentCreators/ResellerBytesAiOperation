<?php

namespace App\Http\Requests;

use App\Services\SafeActions\SafeActionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSafeActionRequest extends FormRequest
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
        $keys = array_keys(app(SafeActionRegistry::class)->all());

        return [
            'integration_id' => ['required', 'uuid', 'exists:whmcs_integrations,id'],
            'action_key' => ['required', 'string', Rule::in($keys)],
            'finding_id' => ['nullable', 'uuid', 'exists:diagnostic_findings,id'],
            'input' => ['sometimes', 'array'],
        ];
    }
}
