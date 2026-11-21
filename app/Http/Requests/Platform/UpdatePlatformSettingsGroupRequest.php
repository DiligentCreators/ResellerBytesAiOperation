<?php

namespace App\Http\Requests\Platform;

use App\Services\CentralStaffAuthorization;
use App\Support\PlatformSettings\PlatformSettingDefinition;
use App\Support\PlatformSettings\PlatformSettingsRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\ValidationException;
use Override;

class UpdatePlatformSettingsGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CentralStaffAuthorization::class)->can($this->user(), 'settings');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
        ];
    }

    /**
     * Settings keyed by full registry key (dots preserved).
     *
     * @return array<string, mixed>
     */
    public function validatedSettings(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = $this->input('settings', []);

        return $settings;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $group = (string) $this->route('group');
            $registry = app(PlatformSettingsRegistry::class);

            if (! $registry->hasGroup($group)) {
                $validator->errors()->add('group', 'Unknown settings group.');

                return;
            }

            /** @var array<string, mixed> $incoming */
            $incoming = $this->input('settings', []);
            $allowed = [];

            foreach ($registry->forGroup($group) as $definition) {
                $allowed[$definition->key] = $definition;
            }

            foreach (array_keys($incoming) as $key) {
                if (! array_key_exists($key, $allowed)) {
                    $validator->errors()->add('settings.'.$key, 'Setting is not part of this group.');
                }
            }

            foreach ($allowed as $key => $definition) {
                if (! array_key_exists($key, $incoming)) {
                    continue;
                }

                $value = $incoming[$key];

                if ($definition->isSecret() && ($value === null || $value === '')) {
                    continue;
                }

                $rules = $this->rulesForDefinition($definition);
                $normalized = $this->normalizeIncoming($definition, $value);
                // Validate under a non-dotted attribute; registry keys contain dots and
                // Laravel would otherwise treat them as nested array paths.
                $fieldValidator = ValidatorFacade::make(
                    ['value' => $normalized],
                    ['value' => $rules],
                    [],
                    ['value' => $definition->label],
                );

                if ($fieldValidator->fails()) {
                    foreach ($fieldValidator->errors()->all() as $message) {
                        $validator->errors()->add('settings.'.$key, $message);
                    }
                } else {
                    $incoming[$key] = $normalized;
                }
            }

            $this->merge(['settings' => $incoming]);
        });
    }

    /**
     * @return list<string>
     */
    private function rulesForDefinition(PlatformSettingDefinition $definition): array
    {
        $rules = $definition->rules;

        if ($definition->isSecret()) {
            return array_values(array_unique(array_merge(['nullable'], $rules)));
        }

        if (! in_array('nullable', $rules, true)
            && ! in_array($definition->type, [
                PlatformSettingDefinition::TYPE_EMAIL,
                PlatformSettingDefinition::TYPE_URL,
                PlatformSettingDefinition::TYPE_STRING,
            ], true)) {
            array_unshift($rules, 'required');
        }

        return $rules;
    }

    private function normalizeIncoming(PlatformSettingDefinition $definition, mixed $value): mixed
    {
        return match ($definition->type) {
            PlatformSettingDefinition::TYPE_BOOL => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            PlatformSettingDefinition::TYPE_INT => is_numeric($value) ? (int) $value : $value,
            PlatformSettingDefinition::TYPE_FLOAT => is_numeric($value) ? (float) $value : $value,
            PlatformSettingDefinition::TYPE_SECRET,
            PlatformSettingDefinition::TYPE_STRING,
            PlatformSettingDefinition::TYPE_EMAIL,
            PlatformSettingDefinition::TYPE_URL => is_string($value) ? $value : (is_scalar($value) ? (string) $value : $value),
            default => $value,
        };
    }

    #[Override]
    protected function failedValidation(Validator $validator): void
    {
        throw (new ValidationException($validator))
            ->redirectTo(route('platform.settings.show', [
                'group' => (string) $this->route('group'),
            ]));
    }
}
