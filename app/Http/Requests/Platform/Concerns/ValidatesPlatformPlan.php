<?php

namespace App\Http\Requests\Platform\Concerns;

use App\Models\Plan;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesPlatformPlan
{
    /**
     * @return array<string, mixed>
     */
    protected function planRules(?Plan $plan = null): array
    {
        $codeRules = ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('plans', 'code')->ignore($plan?->id)];

        if ($plan !== null && $plan->isReferencedByBilling()) {
            // Code is immutable once any subscription references the plan.
            $codeRules = ['required', 'string', 'in:'.$plan->code];
        }

        return [
            'code' => $codeRules,
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_monthly' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'price_yearly' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'currency' => ['required', 'string', 'size:3'],
            'trial_enabled' => ['sometimes', 'boolean'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'kelviq_plan_identifier_monthly' => ['nullable', 'string', 'max:160'],
            'kelviq_plan_identifier_yearly' => ['nullable', 'string', 'max:160'],
            'features' => ['nullable', 'array', 'max:20'],
            'features.*' => ['required', 'string', 'max:120'],
            'monthly_active' => ['sometimes', 'boolean'],
            'yearly_active' => ['sometimes', 'boolean'],
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
     * @return array<int, callable(Validator): void>
     */
    protected function planAfterValidation(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['trial_enabled', 'trial_days'])) {
                    return;
                }

                if ($this->boolean('trial_enabled') && (int) $this->input('trial_days') < 1) {
                    $validator->errors()->add('trial_days', 'Trial days must be between 1 and 90 when trial is enabled.');
                }
            },
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
        $data['trial_enabled'] = $this->boolean('trial_enabled');
        $data['monthly_active'] = $this->boolean('monthly_active');
        $data['yearly_active'] = $this->boolean('yearly_active');
        $data['currency'] = strtoupper((string) $data['currency']);
        $data['price_monthly_minor'] = $this->majorToMinor($data['price_monthly']);
        $data['price_yearly_minor'] = $this->majorToMinor($data['price_yearly']);
        unset($data['price_monthly'], $data['price_yearly']);

        if ($data['trial_enabled'] !== true) {
            // Keep stored days for when trial is re-enabled; UI may still send 0.
            $data['trial_days'] = max(0, (int) $data['trial_days']);
        }

        $features = $data['features'] ?? null;
        if (is_array($features)) {
            $data['features'] = array_values(array_map(
                static fn (mixed $line): string => trim((string) $line),
                $features,
            ));
            $data['features'] = array_values(array_filter(
                $data['features'],
                static fn (string $line): bool => $line !== '',
            ));
            if ($data['features'] === []) {
                $data['features'] = null;
            }
        } else {
            $data['features'] = null;
        }

        $data['entitlements'] = [
            ...$data['entitlements'],
            'diagnostics.analysis' => true,
            'diagnostics.reanalyze' => true,
        ];

        $data['prices_confirmed_at'] = now();

        return $data;
    }

    private function majorToMinor(mixed $major): int
    {
        return (int) round(((float) $major) * 100);
    }
}
