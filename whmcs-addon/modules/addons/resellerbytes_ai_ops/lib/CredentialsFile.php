<?php

namespace ResellerBytesAiOps;

use RuntimeException;

final class CredentialsFile
{
    /**
     * @return array{integration_id: string, secret: string}
     */
    public static function load(string $configuredPath, string $whmcsRoot, string $documentRoot): array
    {
        $configuredPath = trim($configuredPath);

        if ($configuredPath === '') {
            throw new RuntimeException('credentials_file_not_configured');
        }

        if ($configuredPath[0] !== DIRECTORY_SEPARATOR) {
            throw new RuntimeException('credentials_file_path_must_be_absolute');
        }

        $path = realpath($configuredPath);

        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('credentials_file_unavailable');
        }

        $root = realpath($whmcsRoot);
        $document = $documentRoot !== '' ? realpath($documentRoot) : false;
        $configuredPath = self::normalizeAbsolutePath($configuredPath);
        $rootLexical = self::normalizeAbsolutePath($whmcsRoot);
        $documentLexical = $documentRoot !== '' ? self::normalizeAbsolutePath($documentRoot) : false;

        if (($root !== false && self::isWithinDirectory($path, $root))
            || self::isWithinDirectory($configuredPath, $rootLexical)
            || ($document !== false && self::isWithinDirectory($path, $document))
            || ($documentLexical !== false && self::isWithinDirectory($configuredPath, $documentLexical))) {
            throw new RuntimeException('credentials_file_must_be_outside_web_root');
        }

        $permissions = fileperms($path);

        if ($permissions === false || ($permissions & 0777) !== 0600) {
            throw new RuntimeException('credentials_file_permissions_invalid');
        }

        $size = filesize($path);

        if ($size === false || $size < 2 || $size > 4096) {
            throw new RuntimeException('credentials_file_size_invalid');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('credentials_file_unreadable');
        }

        try {
            $credentials = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('credentials_file_invalid_json');
        }

        if (! is_array($credentials)
            || count($credentials) !== 2
            || ! isset($credentials['integration_id'], $credentials['secret'])
            || ! is_string($credentials['integration_id'])
            || ! is_string($credentials['secret'])) {
            throw new RuntimeException('credentials_file_invalid_structure');
        }

        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $credentials['integration_id'])
            || ! preg_match('/\A[a-f0-9]{64}\z/', $credentials['secret'])) {
            throw new RuntimeException('credentials_file_invalid_values');
        }

        return [
            'integration_id' => strtolower($credentials['integration_id']),
            'secret' => $credentials['secret'],
        ];
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        $segments = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
    }

    private static function isWithinDirectory(string $path, string $directory): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);

        return $path === $directory
            || strpos($path, $directory.DIRECTORY_SEPARATOR) === 0;
    }
}
