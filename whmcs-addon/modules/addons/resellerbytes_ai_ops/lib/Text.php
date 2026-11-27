<?php

namespace ResellerBytesAiOps;

/**
 * Byte-safe text helpers that keep UTF-8 valid when truncating.
 */
final class Text
{
    public static function truncateBytes(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        if (function_exists('mb_strcut')) {
            return (string) mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }

        return self::stripIncompleteUtf8(substr($value, 0, $maxBytes));
    }

    private static function stripIncompleteUtf8(string $value): string
    {
        $length = strlen($value);

        if ($length === 0) {
            return '';
        }

        $index = $length - 1;
        $continuationCount = 0;

        while ($index >= 0 && (ord($value[$index]) & 0xC0) === 0x80) {
            $continuationCount++;
            $index--;
        }

        if ($index < 0) {
            return '';
        }

        $lead = ord($value[$index]);
        $needed = 0;

        if (($lead & 0x80) === 0) {
            $needed = 0;
        } elseif (($lead & 0xE0) === 0xC0) {
            $needed = 1;
        } elseif (($lead & 0xF0) === 0xE0) {
            $needed = 2;
        } elseif (($lead & 0xF8) === 0xF0) {
            $needed = 3;
        } else {
            return substr($value, 0, $index);
        }

        if ($continuationCount < $needed) {
            return substr($value, 0, $index);
        }

        return $value;
    }
}
