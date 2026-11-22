<?php

namespace ResellerBytesAiOps;

/**
 * Sanitizes exception messages before they are written to the WHMCS activity log.
 *
 * Addon RuntimeExceptions use snake_case codes; other throwables (PDOException,
 * TypeError, Error, etc.) may include paths, SQL, or other sensitive detail.
 */
final class FailureCode
{
    public const UNEXPECTED = 'unexpected_error';

    private const MAX_LENGTH = 64;

    public static function sanitize(string $message): string
    {
        if (strlen($message) <= self::MAX_LENGTH && preg_match('/^[a-z_]+$/', $message) === 1) {
            return $message;
        }

        return self::UNEXPECTED;
    }
}
