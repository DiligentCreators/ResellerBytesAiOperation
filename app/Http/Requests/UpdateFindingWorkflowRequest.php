<?php

namespace App\Http\Requests;

use App\Models\DiagnosticFinding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFindingWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('assignee_user_id') && $this->input('assignee_user_id') === '') {
            $this->merge(['assignee_user_id' => null]);
        }

        if ($this->exists('status_note') && $this->input('status_note') === '') {
            $this->merge(['status_note' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::in(DiagnosticFinding::STATUSES)],
            'assignee_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'status_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{status?: string, assignee_user_id?: int|null, status_note?: string|null}
     */
    public function workflowInput(): array
    {
        /** @var array{status?: string, assignee_user_id?: int|null, status_note?: string|null} $validated */
        $validated = $this->safe()->only(['status', 'assignee_user_id', 'status_note']);

        return $validated;
    }
}
