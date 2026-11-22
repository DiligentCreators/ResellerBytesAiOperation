<?php

namespace App\Http\Requests\Tenants;

use App\Models\TenantAlertChannel;
use App\Support\Alerts\WebhookUrlValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTenantAlertChannelRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'target' => ['sometimes', 'nullable', 'string', 'max:2048'],
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

                $target = $this->input('target');
                if (! is_string($target) || trim($target) === '') {
                    return;
                }

                $channel = $this->route('channel');
                $type = $channel instanceof TenantAlertChannel
                    ? $channel->type
                    : null;

                if ($type === TenantAlertChannel::TYPE_EMAIL) {
                    if (filter_var(trim($target), FILTER_VALIDATE_EMAIL) === false) {
                        $validator->errors()->add('target', 'Enter a valid email address.');
                    }

                    return;
                }

                $result = app(WebhookUrlValidator::class)->validate(trim($target), allowPrivate: false);
                if (! $result['valid']) {
                    $validator->errors()->add('target', $result['message'] ?? 'Invalid webhook URL.');
                }
            },
        ];
    }
}
