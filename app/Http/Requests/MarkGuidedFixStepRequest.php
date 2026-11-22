<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkGuidedFixStepRequest extends FormRequest
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
            'step_index' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
