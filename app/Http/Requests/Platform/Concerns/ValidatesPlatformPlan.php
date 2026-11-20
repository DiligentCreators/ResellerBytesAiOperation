<?php

namespace App\Http\Requests\Platform\Concerns;

use App\Models\Plan;
use Illuminate\Validation\Rule;

trait ValidatesPlatformPlan
{
    /**
     * @return array<string, mixed>
     */
    protected function planRules(?Plan $plan = null): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_monthly_minor' => ['required', 'integer', 'min:0'],
            'price_yearly_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'kelviq_plan_identifier_monthly' => ['nullable', 'string', 'max:160'],
            'kelviq_plan_identifier_yearly' => ['nullable', 'string', 'max:160'],
            'entitlements' => ['required', 'array'],
            'entitlements.limits' => ['required', 'array'],
            'entitlements.limits.members' => ['required', 'integer', 'min:0'],
            'entitlements.limits.integrations' => ['required', 'integer', 'min:0'],
            'entitlements.limits.diagnostics_per_period' => ['required', 'integer', 'min:0'],
            'entitlements.limits.retention_days' => ['required', 'integer', 'min:1'],
            'entitlements.support_level' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planPayload(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $data['is_active'] = $this->boolean('is_active');
        $data['is_public'] = $this->boolean('is_public');
        $data['is_contact_sales'] = $this->boolean('is_contact_sales');
        $data['currency'] = strtoupper((string) $data['currency']);
        $data['entitlements'] = [
            ...$data['entitlements'],
            'diagnostics.analysis' => true,
            'diagnostics.reanalyze' => true,
        ];

        return $data;
    }
}
