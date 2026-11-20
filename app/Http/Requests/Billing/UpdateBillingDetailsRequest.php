<?php

namespace App\Http\Requests\Billing;

use App\Support\CountryCodes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillingDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
