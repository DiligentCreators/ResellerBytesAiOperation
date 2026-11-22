<?php

namespace App\Services\Diagnostics;

/**
 * Catalog keys contain dots (e.g. php.display_errors_on). Never use Laravel config()
 * dot-path concatenation for those keys — index the top-level array instead.
 */
final class WhmcsIssueCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $catalog = config('whmcs_issue_catalog', []);

        return is_array($catalog) ? $catalog : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $issueKey): ?array
    {
        $entry = self::all()[$issueKey] ?? null;

        return is_array($entry) ? $entry : null;
    }

    public static function getString(string $issueKey, string $field, ?string $default = null): ?string
    {
        $entry = self::get($issueKey);
        $value = $entry[$field] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public static function getInt(string $issueKey, string $field, int $default): int
    {
        $entry = self::get($issueKey);
        $value = $entry[$field] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<string>
     */
    public static function actionKeys(string $issueKey): array
    {
        $entry = self::get($issueKey);
        $keys = $entry['action_keys'] ?? [];
        if (! is_array($keys)) {
            return [];
        }

        $normalized = [];
        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $normalized[] = $key;
            }
        }

        return $normalized;
    }
}
