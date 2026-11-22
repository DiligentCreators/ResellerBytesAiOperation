<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWhmcsIntegrationAutoCollectRequest extends FormRequest
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
            'auto_collect_enabled' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('auto_collect_enabled')) {
            $this->merge([
                'auto_collect_enabled' => filter_var(
                    $this->input('auto_collect_enabled'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                ) ?? $this->input('auto_collect_enabled'),
            ]);
        }
    }
}
