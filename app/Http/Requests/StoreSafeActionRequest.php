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
            // Auto mode is reserved for AutoFixRunner; HTTP clients may not request it.
            'input.mode' => ['sometimes', 'string', Rule::in(['one_click', 'guided', 'bulk'])],
            'input.target_ids' => ['sometimes', 'array', 'max:500'],
            'input.target_ids.*' => ['integer', 'min:1'],
        ];
    }
}
