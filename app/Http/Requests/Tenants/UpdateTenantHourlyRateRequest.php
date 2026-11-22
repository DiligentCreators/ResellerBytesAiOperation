<?php

namespace App\Http\Requests\Tenants;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantHourlyRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User) {
            return false;
        }

        $tenant = $this->route('tenant');
        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        // Authorize before validation so outsiders cannot probe via 422s.
        app(TenantRoleAuthorization::class)->require($user, $tenant->id, 'tenant.settings.manage');

        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('hourly_rate_usd') === '' || $this->input('hourly_rate_usd') === null) {
            $this->merge(['hourly_rate_usd' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hourly_rate_usd' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'hourly_rate_usd' => 'hourly rate',
        ];
    }
}
