<?php

namespace App\Http\Requests\Tenants;

use App\Models\Tenant;
use App\Models\WhmcsIntegration;
use App\Services\SafeActions\SafeActionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAutoFixPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $policies = $this->input('policies');
        if (! is_array($policies)) {
            return;
        }

        $normalized = [];
        foreach ($policies as $policy) {
            if (! is_array($policy)) {
                continue;
            }

            if (array_key_exists('enabled', $policy)) {
                $enabled = $policy['enabled'];
                if (is_array($enabled)) {
                    $enabled = end($enabled);
                }
                $policy['enabled'] = filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }

            if (array_key_exists('integration_id', $policy) && ($policy['integration_id'] === '' || $policy['integration_id'] === 'all')) {
                $policy['integration_id'] = null;
            }

            if (array_key_exists('daily_cap', $policy) && ($policy['daily_cap'] === '' || $policy['daily_cap'] === null)) {
                $policy['daily_cap'] = null;
            }

            $normalized[] = $policy;
        }

        $this->merge(['policies' => $normalized]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->route('tenant');
        $autoAllowed = $this->autoAllowedActionKeys();

        return [
            'policies' => ['required', 'array', 'min:1'],
            'policies.*.action_key' => ['required', 'string', 'max:64', Rule::in($autoAllowed)],
            'policies.*.integration_id' => [
                'nullable',
                'uuid',
                Rule::exists('whmcs_integrations', 'id')->where(
                    fn ($query) => $query->where('tenant_id', $tenant->id),
                ),
            ],
            'policies.*.enabled' => ['required', 'boolean'],
            'policies.*.daily_cap' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Tenant $tenant */
                $tenant = $this->route('tenant');
                $seen = [];

                foreach ((array) $this->input('policies', []) as $index => $policy) {
                    if (! is_array($policy)) {
                        continue;
                    }

                    $actionKey = (string) ($policy['action_key'] ?? '');
                    $integrationId = $policy['integration_id'] ?? null;
                    $scopeKey = $actionKey.'|'.(is_string($integrationId) ? $integrationId : 'all');

                    if (isset($seen[$scopeKey])) {
                        $validator->errors()->add(
                            "policies.{$index}.action_key",
                            'Duplicate policy for the same action and installation.',
                        );
                    }
                    $seen[$scopeKey] = true;

                    if (is_string($integrationId) && $integrationId !== '') {
                        $belongs = WhmcsIntegration::query()
                            ->whereKey($integrationId)
                            ->where('tenant_id', $tenant->id)
                            ->exists();
                        if (! $belongs) {
                            $validator->errors()->add(
                                "policies.{$index}.integration_id",
                                'Installation does not belong to this workspace.',
                            );
                        }
                    }
                }
            },
        ];
    }

    /**
     * @return list<string>
     */
    private function autoAllowedActionKeys(): array
    {
        $keys = [];
        foreach (app(SafeActionRegistry::class)->all() as $definition) {
            if (($definition['auto_allowed'] ?? false) === true) {
                $keys[] = $definition['key'];
            }
        }

        return $keys;
    }
}
