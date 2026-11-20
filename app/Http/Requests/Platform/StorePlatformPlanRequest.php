<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\Platform\Concerns\ValidatesPlatformPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePlatformPlanRequest extends FormRequest
{
    use ValidatesPlatformPlan;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->planRules();
    }
}
