<?php

namespace ResellerBytesAiOps;

final class DiagnosticCollector
{
    private const ALLOWED_EXTENSIONS = [
        'curl',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'pdo_mysql',
        'soap',
        'xml',
        'gd',
        'iconv',
        'intl',
        'zip',
        'zlib',
        'fileinfo',
        'bcmath',
        'ioncube_loader',
        'sodium',
    ];

    /**
     * Collect only approved technical signals. No customer or billing data is read.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $system = [
            'whmcs_version' => $this->whmcsVersion(),
            'web_php_version' => PHP_VERSION,
            'error_display_disabled' => $this->errorDisplayDisabled(),
        ];

        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';

        if (is_string($serverSoftware)
            && preg_match('/\A([A-Za-z0-9._-]+)/', $serverSoftware, $matches) === 1) {
            $system['web_server'] = substr($matches[1], 0, 64);
        }

        $systemUrl = $this->systemUrl();

        if ($systemUrl !== null) {
            $system['application_url_configured'] = $systemUrl !== '';
        }

        $payload = [
            'system' => $system,
            'php' => [
                'memory_limit' => substr((string) (ini_get('memory_limit') ?: 'unknown'), 0, 32),
                'extensions' => $this->extensionStatus(),
            ],
            'collector' => [
                'capabilities' => [
                    'web_php_version',
                    'whmcs_version',
                    'php_extension_availability',
                    'php_memory_limit',
                    'configuration_file_writability',
                ],
                'limitations' => [
                    'cron_php_version_not_observable',
                    'database_server_version_not_collected',
                    'module_status_not_collected',
                    'hosting_panel_configuration_not_observable',
                    'ssl_certificate_not_verified',
                ],
            ],
        ];

        if (defined('ROOTDIR')) {
            $configurationFile = rtrim((string) ROOTDIR, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'configuration.php';

            if (is_file($configurationFile) && is_writable($configurationFile)) {
                $payload['warnings'] = [[
                    'code' => 'configuration_file_writable',
                    'severity' => 'warning',
                ]];
            }
        }

        return $payload;
    }

    private function whmcsVersion(): string
    {
        try {
            if (class_exists('WHMCS\\Config\\Setting')) {
                $version = \WHMCS\Config\Setting::getValue('Version');

                if (is_string($version) && $version !== '') {
                    return substr($version, 0, 32);
                }
            }
        } catch (\Throwable $exception) {
            // Missing visibility is represented as unknown, never as healthy.
        }

        return 'unknown';
    }

    private function systemUrl(): ?string
    {
        try {
            if (class_exists('WHMCS\\Config\\Setting')) {
                $url = \WHMCS\Config\Setting::getValue('SystemURL');

                return is_string($url) ? $url : null;
            }
        } catch (\Throwable $exception) {
            // Do not expose configuration values or exception details.
        }

        return null;
    }

    private function errorDisplayDisabled(): bool
    {
        $value = strtolower(trim((string) ini_get('display_errors')));

        return in_array($value, ['', '0', 'off', 'false', 'no'], true);
    }

    /**
     * @return array<string, bool>
     */
    private function extensionStatus(): array
    {
        $extensions = [];

        foreach (self::ALLOWED_EXTENSIONS as $extension) {
            $extensions[$extension] = $extension === 'ioncube_loader'
                ? (extension_loaded('ionCube Loader') || extension_loaded('ioncube_loader'))
                : extension_loaded($extension);
        }

        return $extensions;
    }
}
