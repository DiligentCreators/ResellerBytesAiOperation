<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreSafeActionResultRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:success,failed'],
            'summary' => ['required', 'string', 'max:500'],
            'evidence' => ['sometimes', 'array'],
            'evidence.*' => ['nullable'],
            'error_code' => ['nullable', 'string', 'max:64'],
        ];
    }
}
