<?php

namespace App\Http\Requests\Billing;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantAccess;
use App\Support\CountryCodes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckoutRequest extends FormRequest
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

        // Membership before validation so outsiders always get 404 (not 422).
        try {
            app(TenantAccess::class)->requireMembership($user, $tenant->id);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', Rule::in(['monthly', 'yearly'])],
            'company_name' => ['required', 'string', 'max:160'],
            'billing_email' => ['required', 'email:rfc', 'max:255'],
            'country' => ['required', 'string', 'size:2', Rule::in(CountryCodes::codes())],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'tax_id' => ['nullable', 'string', 'max:80'],
        ];
    }
}
