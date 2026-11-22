<?php

namespace App\Http\Requests\Tenants;

use App\Models\TenantAlertChannel;
use App\Support\Alerts\WebhookUrlValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTenantAlertChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('enabled')) {
            $this->merge([
                'enabled' => $this->boolean('enabled'),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $eventKeys = array_values(array_filter(
            (array) config('alerts.event_keys', []),
            static fn (mixed $key): bool => is_string($key) && $key !== 'alert.test',
        ));

        return [
            'type' => ['required', 'string', Rule::in(TenantAlertChannel::TYPES)],
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'target' => ['required', 'string', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in($eventKeys)],
            'enabled' => ['sometimes', 'boolean'],
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

                $type = (string) $this->input('type');
                $target = trim((string) $this->input('target'));

                if ($type === TenantAlertChannel::TYPE_EMAIL) {
                    if (filter_var($target, FILTER_VALIDATE_EMAIL) === false) {
                        $validator->errors()->add('target', 'Enter a valid email address.');
                    }

                    return;
                }

                $result = app(WebhookUrlValidator::class)->validate($target, allowPrivate: false);
                if (! $result['valid']) {
                    $validator->errors()->add('target', $result['message'] ?? 'Invalid webhook URL.');
                }
            },
        ];
    }
}
