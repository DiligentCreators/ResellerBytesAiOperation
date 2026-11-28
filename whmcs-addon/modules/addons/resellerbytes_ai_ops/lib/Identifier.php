<?php

namespace ResellerBytesAiOps;

/**
 * Sanitizes display/module identifiers to the ops allowlist character set.
 */
final class Identifier
{
    /**
     * Keep only A-Za-z0-9._ - characters, collapse spaces, trim, and cap length.
     * Returns null when the result would be empty.
     */
    public static function sanitize(string $value, int $maxLength = 64): ?string
    {
        if ($maxLength <= 0) {
            return null;
        }

        $replaced = preg_replace('/[^A-Za-z0-9._ -]+/', ' ', $value);
        if (! is_string($replaced)) {
            return null;
        }

        $collapsed = preg_replace('/ {2,}/', ' ', $replaced);
        if (! is_string($collapsed)) {
            return null;
        }

        $trimmed = trim($collapsed);
        if ($trimmed === '') {
            return null;
        }

        return Text::truncateBytes($trimmed, $maxLength);
    }

    /**
     * Re-key a count map through sanitize(), summing counts when keys collide.
     *
     * @param  array<mixed, mixed>  $map
     * @return array<string, int>
     */
    public static function sanitizeCountMap(array $map, int $maxLength = 64): array
    {
        $out = [];

        foreach ($map as $key => $count) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $sanitized = self::sanitize((string) $key, $maxLength);
            if ($sanitized === null) {
                continue;
            }

            $out[$sanitized] = ($out[$sanitized] ?? 0) + (int) $count;
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $names
     * @return list<string>
     */
    public static function sanitizeNameList(array $names, int $maxLength = 64, int $maxItems = 30): array
    {
        $out = [];

        foreach ($names as $name) {
            if (! is_string($name) && ! is_int($name)) {
                continue;
            }

            $sanitized = self::sanitize((string) $name, $maxLength);
            if ($sanitized === null) {
                continue;
            }

            $out[] = $sanitized;
            if (count($out) >= $maxItems) {
                break;
            }
        }

        return $out;
    }
}
