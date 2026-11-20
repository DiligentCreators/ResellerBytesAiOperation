<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\Platform\Concerns\ValidatesPlatformPlan;
use App\Models\Plan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformPlanRequest extends FormRequest
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
        /** @var Plan|null $plan */
        $plan = $this->route('plan');

        return $this->planRules($plan instanceof Plan ? $plan : null);
    }
}
