<?php

namespace ResellerBytesAiOps;

final class SnapshotRedactor
{
    private const MAX_LENGTH = 200;

    public static function redact(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $redacted = $value;

        $redacted = preg_replace(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            '[redacted-email]',
            $redacted
        ) ?? $redacted;

        $redacted = preg_replace(
            '/\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d?\d)\b/',
            '[redacted-ipv4]',
            $redacted
        ) ?? $redacted;

        $redacted = preg_replace(
            '/(?:(?:[A-F0-9]{1,4}:){7}[A-F0-9]{1,4}|(?:[A-F0-9]{1,4}:){1,7}:|:(?::[A-F0-9]{1,4}){1,7}|(?:[A-F0-9]{1,4}:){1,6}:[A-F0-9]{1,4}|(?:[A-F0-9]{1,4}:){1,5}(?::[A-F0-9]{1,4}){1,2}|(?:[A-F0-9]{1,4}:){1,4}(?::[A-F0-9]{1,4}){1,3}|(?:[A-F0-9]{1,4}:){1,3}(?::[A-F0-9]{1,4}){1,4}|(?:[A-F0-9]{1,4}:){1,2}(?::[A-F0-9]{1,4}){1,5}|[A-F0-9]{1,4}:(?::[A-F0-9]{1,4}){1,6}|:(?::[A-F0-9]{1,4}){1,6})/i',
            '[redacted-ipv6]',
            $redacted
        ) ?? $redacted;

        $redacted = preg_replace(
            '#[a-z][a-z0-9+.-]*://[^\s/@]+:[^\s/@]+@[^\s]+#i',
            '[redacted-url]',
            $redacted
        ) ?? $redacted;

        if (strlen($redacted) > self::MAX_LENGTH) {
            $redacted = Text::truncateBytes($redacted, self::MAX_LENGTH);
        }

        return $redacted;
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return list<int>
     */
    public static function boundIdList(array $ids, int $limit = 50): array
    {
        $result = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $result[] = (int) $id;
            }
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}
