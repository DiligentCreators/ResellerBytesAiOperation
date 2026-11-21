<?php

namespace App\Http\Requests\Platform;

use App\Services\Billing\BillingSettings;
use App\Services\CentralStaffAuthorization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBillingGatewaySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CentralStaffAuthorization::class)
            ->can($this->user(), 'billing.settings.manage');
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'gateway' => ['required', 'string', Rule::in(['sandbox', 'kelviq'])],
            'kelviq_mode' => ['required', 'string', Rule::in(['sandbox', 'live'])],
            'kelviq_sandbox_api_key' => ['nullable', 'string', 'max:4096'],
            'kelviq_live_api_key' => ['nullable', 'string', 'max:4096'],
            'kelviq_sandbox_webhook_secret' => ['nullable', 'string', 'max:4096'],
            'kelviq_live_webhook_secret' => ['nullable', 'string', 'max:4096'],
            'clear_kelviq_sandbox_api_key' => ['sometimes', 'boolean'],
            'clear_kelviq_live_api_key' => ['sometimes', 'boolean'],
            'clear_kelviq_sandbox_webhook_secret' => ['sometimes', 'boolean'],
            'clear_kelviq_live_webhook_secret' => ['sometimes', 'boolean'],
            'trials_enabled' => ['required', 'boolean'],
            'trial_card_required' => ['required', 'boolean'],
            'trial_first_time_only' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'trials_enabled' => filter_var($this->input('trials_enabled', false), FILTER_VALIDATE_BOOLEAN),
            'trial_card_required' => filter_var($this->input('trial_card_required', true), FILTER_VALIDATE_BOOLEAN),
            'trial_first_time_only' => filter_var($this->input('trial_first_time_only', true), FILTER_VALIDATE_BOOLEAN),
            'clear_kelviq_sandbox_api_key' => filter_var($this->input('clear_kelviq_sandbox_api_key', false), FILTER_VALIDATE_BOOLEAN),
            'clear_kelviq_live_api_key' => filter_var($this->input('clear_kelviq_live_api_key', false), FILTER_VALIDATE_BOOLEAN),
            'clear_kelviq_sandbox_webhook_secret' => filter_var($this->input('clear_kelviq_sandbox_webhook_secret', false), FILTER_VALIDATE_BOOLEAN),
            'clear_kelviq_live_webhook_secret' => filter_var($this->input('clear_kelviq_live_webhook_secret', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var BillingSettings $settings */
            $settings = app(BillingSettings::class);
            $gateway = (string) $this->input('gateway');
            $mode = (string) $this->input('kelviq_mode');

            if ($gateway === 'sandbox'
                && app()->environment('production')
                && ! filter_var(config('billing.sandbox.allow_in_production'), FILTER_VALIDATE_BOOLEAN)) {
                $validator->errors()->add(
                    'gateway',
                    'The sandbox gateway is blocked in production unless BILLING_SANDBOX_ALLOW_IN_PRODUCTION is enabled.',
                );
            }

            $sandboxApiKey = $this->resolvedSecretAfterSave($settings, 'kelviq_sandbox_api_key', 'clear_kelviq_sandbox_api_key', 'sandbox', 'api');
            $liveApiKey = $this->resolvedSecretAfterSave($settings, 'kelviq_live_api_key', 'clear_kelviq_live_api_key', 'live', 'api');
            $liveWebhook = $this->resolvedSecretAfterSave($settings, 'kelviq_live_webhook_secret', 'clear_kelviq_live_webhook_secret', 'live', 'webhook');

            $activeApiKey = $mode === 'live' ? $liveApiKey : $sandboxApiKey;

            if ($gateway === 'kelviq' && $activeApiKey === '') {
                $field = $mode === 'live' ? 'kelviq_live_api_key' : 'kelviq_sandbox_api_key';
                $validator->errors()->add(
                    $field,
                    'Switching to Kelviq requires a resolvable API key for the selected mode.',
                );
            }

            if ($mode === 'live') {
                if ($liveApiKey === '') {
                    $validator->errors()->add(
                        'kelviq_live_api_key',
                        'Live mode requires a resolvable live API key.',
                    );
                }
                if ($liveWebhook === '') {
                    $validator->errors()->add(
                        'kelviq_live_webhook_secret',
                        'Live mode requires a resolvable live webhook secret.',
                    );
                }
            }
        });
    }

    private function resolvedSecretAfterSave(
        BillingSettings $settings,
        string $inputField,
        string $clearField,
        string $mode,
        string $kind,
    ): string {
        if (filter_var($this->input($clearField), FILTER_VALIDATE_BOOLEAN)) {
            return $kind === 'api'
                ? (string) (config('billing.kelviq.api_key') ?? '')
                : (string) (config('billing.kelviq.webhook_secret') ?? '');
        }

        $incoming = $this->input($inputField);
        if (is_string($incoming) && trim($incoming) !== '') {
            return trim($incoming);
        }

        return $kind === 'api'
            ? $settings->kelviqApiKeyForMode($mode)
            : $settings->kelviqWebhookSecretForMode($mode);
    }
}
