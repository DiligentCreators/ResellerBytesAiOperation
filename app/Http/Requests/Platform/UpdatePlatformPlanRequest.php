<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\Platform\Concerns\ValidatesPlatformPlan;
use App\Models\Plan;
use App\Services\CentralStaffAuthorization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePlatformPlanRequest extends FormRequest
{
    use ValidatesPlatformPlan;

    public function authorize(): bool
    {
        return app(CentralStaffAuthorization::class)->can($this->user(), 'billing.settings.manage');
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

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return $this->planAfterValidation();
    }
}
