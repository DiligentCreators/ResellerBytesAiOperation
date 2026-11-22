<?php

namespace App\Support\PlatformSettings;

use Closure;

final class PlatformSettingDefinition
{
    public const TYPE_BOOL = 'bool';

    public const TYPE_INT = 'int';

    public const TYPE_FLOAT = 'float';

    public const TYPE_STRING = 'string';

    public const TYPE_EMAIL = 'email';

    public const TYPE_URL = 'url';

    public const TYPE_JSON = 'json';

    public const TYPE_SECRET = 'secret';

    /**
     * @param  Closure(): mixed  $default
     * @param  list<string>  $rules
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $label,
        public readonly string $help,
        public readonly string $type,
        public readonly Closure $default,
        public readonly array $rules = [],
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
    ) {}

    public function resolveDefault(): mixed
    {
        return ($this->default)();
    }

    public function isSecret(): bool
    {
        return $this->type === self::TYPE_SECRET;
    }

    /**
     * Cast a raw decoded value to the definition's type.
     */
    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            self::TYPE_BOOL => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_INT => (int) $value,
            self::TYPE_FLOAT => (float) $value,
            self::TYPE_JSON => is_array($value) ? $value : (json_decode(is_string($value) ? $value : 'null', true) ?? []),
            self::TYPE_SECRET, self::TYPE_STRING, self::TYPE_EMAIL, self::TYPE_URL => $value === null ? null : (string) $value,
            default => $value,
        };
    }
}
