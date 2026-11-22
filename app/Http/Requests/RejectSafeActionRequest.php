<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RejectSafeActionRequest extends FormRequest
{
    public const FROM_FIX_CENTER = 'fix-center';

    /**
     * @var list<string>
     */
    public const FIX_CENTER_TABS = ['open', 'fixing', 'fixed', 'ignored'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
            'from' => ['nullable', 'string', Rule::in([self::FROM_FIX_CENTER])],
            'tab' => [
                'exclude_unless:from,'.self::FROM_FIX_CENTER,
                'nullable',
                'string',
                Rule::in(self::FIX_CENTER_TABS),
            ],
        ];
    }

    public function shouldReturnToFixCenter(): bool
    {
        return $this->validated('from') === self::FROM_FIX_CENTER;
    }

    /**
     * Allowlisted Fix Center query params only — never a user-supplied URL.
     *
     * @return array{tab?: string}
     */
    public function fixCenterQuery(): array
    {
        $tab = $this->validated('tab');

        if (! is_string($tab) || $tab === '') {
            return [];
        }

        return ['tab' => $tab];
    }
}
