<?php

namespace App\Http\Requests;

use App\Services\Diagnostics\FindingIgnoreService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IgnoreFindingRequest extends FormRequest
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
            'snooze' => ['required', 'string', Rule::in(FindingIgnoreService::SNOOZE_OPTIONS)],
        ];
    }
}
