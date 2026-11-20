<?php

namespace App\Http\Requests\Platform;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBillingSellerRequest extends FormRequest
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
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:80'],
        ];
    }
}
