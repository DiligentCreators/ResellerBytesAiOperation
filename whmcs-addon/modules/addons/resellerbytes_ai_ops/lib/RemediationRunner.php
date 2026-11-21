<?php

namespace ResellerBytesAiOps;

use RuntimeException;
use Throwable;

final class RemediationRunner
{
    private const ALLOWED_ACTIONS = [
        'pull_and_run_diagnostic_collect',
        'request_diagnostic_collect',
        'disable_php_display_errors',
        'harden_configuration_php_mode',
    ];

    /**
     * @param  array{integration_id: string, secret: string}  $credentials
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    public function apply(array $credentials, array $action, SignedApiClient $client): array
    {
        $actionKey = isset($action['action_key']) && is_string($action['action_key'])
            ? $action['action_key']
            : '';

        if (! in_array($actionKey, self::ALLOWED_ACTIONS, true)) {
            return [
                'status' => 'failed',
                'summary' => 'Action key is not allowlisted for addon execution.',
                'evidence' => [],
                'error_code' => 'action_not_allowlisted',
            ];
        }

        try {
            return match ($actionKey) {
                'pull_and_run_diagnostic_collect', 'request_diagnostic_collect' => $this->runDiagnosticCollect($credentials, $client),
                'disable_php_display_errors' => $this->disableDisplayErrors($action),
                'harden_configuration_php_mode' => $this->hardenConfigurationPhp($action),
                default => [
                    'status' => 'failed',
                    'summary' => 'Action key is not allowlisted for addon execution.',
                    'evidence' => [],
                    'error_code' => 'action_not_allowlisted',
                ],
            };
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'summary' => 'Allowlisted remediation could not be applied.',
                'evidence' => [],
                'error_code' => 'remediation_exception',
            ];
        }
    }

    /**
     * @param  array{integration_id: string, secret: string}  $credentials
     * @return array{status: string, summary: string, evidence: array<string, mixed>}
     */
    private function runDiagnosticCollect(array $credentials, SignedApiClient $client): array
    {
        $diagnostics = (new DiagnosticCollector)->collect();
        $result = $client->submit($credentials, $diagnostics);

        return [
            'status' => 'success',
            'summary' => $result['duplicate']
                ? 'Diagnostic snapshot was already received; collect request verified.'
                : 'Diagnostic snapshot submitted successfully after approved Safe Action.',
            'evidence' => [
                'remediation_applied' => true,
                'duplicate' => $result['duplicate'],
                'http_status' => $result['http_status'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function disableDisplayErrors(array $action): array
    {
        $root = $this->localWhmcsRoot();
        $target = $root.DIRECTORY_SEPARATOR.'.user.ini';
        $line = 'display_errors = Off';

        $local = $this->writeUserIniLocally($target, $line);
        if ($local['ok']) {
            $collector = (new DiagnosticCollector)->collect();
            $disabled = (bool) ($collector['system']['error_display_disabled'] ?? false);

            return [
                'status' => 'success',
                'summary' => $disabled
                    ? 'Wrote allowlisted .user.ini display_errors=Off override using the web user and runtime proof confirms display_errors is Off.'
                    : 'Wrote allowlisted .user.ini display_errors=Off override using the web user. Runtime still reports display_errors on — waiting for verify scan / PHP reload.',
                'evidence' => [
                    'remediation_applied' => true,
                    'method' => 'local_user_ini',
                    'error_display_disabled' => $disabled,
                ],
            ];
        }

        $host = isset($action['host']) && is_array($action['host']) ? $action['host'] : null;
        if ($host === null) {
            return [
                'status' => 'failed',
                'summary' => 'Web user could not write .user.ini and no host credentials are configured.',
                'evidence' => ['local_error' => $local['error']],
                'error_code' => 'host_credentials_required',
            ];
        }

        $remoteRoot = $this->normalizeRemoteRoot((string) ($host['remote_whmcs_root'] ?? ''));
        $remotePath = $remoteRoot.'/.user.ini';
        $this->ftpWriteFile($host, $remotePath, $this->mergeUserIniContents('', $line));

        return [
            'status' => 'success',
            'summary' => 'Wrote allowlisted .user.ini display_errors=Off override over FTP host credentials.',
            'evidence' => [
                'remediation_applied' => true,
                'method' => 'ftp_user_ini',
                'note' => 'Re-collect diagnostics after PHP picks up .user.ini (may require a new request).',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{status: string, summary: string, evidence: array<string, mixed>, error_code?: string}
     */
    private function hardenConfigurationPhp(array $action): array
    {
        $root = $this->localWhmcsRoot();
        $target = $root.DIRECTORY_SEPARATOR.'configuration.php';

        if (is_file($target) && @chmod($target, 0440)) {
            clearstatcache(true, $target);
            $writable = is_writable($target);

            return [
                'status' => $writable ? 'failed' : 'success',
                'summary' => $writable
                    ? 'chmod applied but configuration.php remains writable for the web user.'
                    : 'Removed write permission from configuration.php using the web user.',
                'evidence' => [
                    'remediation_applied' => ! $writable,
                    'method' => 'local_chmod',
                    'configuration_file_writable' => $writable,
                ],
                'error_code' => $writable ? 'still_writable' : null,
            ];
        }

        $host = isset($action['host']) && is_array($action['host']) ? $action['host'] : null;
        if ($host === null) {
            return [
                'status' => 'failed',
                'summary' => 'Web user could not chmod configuration.php and no host credentials are configured.',
                'evidence' => [],
                'error_code' => 'host_credentials_required',
            ];
        }

        $remoteRoot = $this->normalizeRemoteRoot((string) ($host['remote_whmcs_root'] ?? ''));
        $remotePath = $remoteRoot.'/configuration.php';
        $this->ftpChmod($host, $remotePath, 0440);

        return [
            'status' => 'success',
            'summary' => 'Requested non-writable mode on configuration.php over FTP host credentials.',
            'evidence' => [
                'remediation_applied' => true,
                'method' => 'ftp_chmod',
                'configuration_file_writable' => false,
            ],
        ];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function writeUserIniLocally(string $path, string $line): array
    {
        $existing = is_file($path) ? (string) @file_get_contents($path) : '';
        if ($existing !== '' && str_contains($existing, 'display_errors')) {
            $existing = preg_replace('/^\s*display_errors\s*=.*$/mi', $line, $existing) ?? $existing;
            $contents = $existing;
        } else {
            $contents = $this->mergeUserIniContents($existing, $line);
        }

        $dir = dirname($path);
        if (! is_dir($dir) || ! is_writable($dir)) {
            return ['ok' => false, 'error' => 'directory_not_writable'];
        }

        if (@file_put_contents($path, $contents) === false) {
            return ['ok' => false, 'error' => 'write_failed'];
        }

        return ['ok' => true];
    }

    private function mergeUserIniContents(string $existing, string $line): string
    {
        $existing = trim($existing);

        return ($existing === '' ? '' : $existing."\n").$line."\n";
    }

    private function localWhmcsRoot(): string
    {
        if (! defined('ROOTDIR') || ! is_string(ROOTDIR) || ROOTDIR === '') {
            throw new RuntimeException('whmcs_root_unavailable');
        }

        $resolved = realpath(ROOTDIR);
        if ($resolved === false) {
            throw new RuntimeException('whmcs_root_unresolved');
        }

        return $resolved;
    }

    private function normalizeRemoteRoot(string $root): string
    {
        $root = str_replace('\\', '/', trim($root));
        $root = rtrim($root, '/');
        if ($root === '' || str_contains($root, '..')) {
            throw new RuntimeException('invalid_remote_root');
        }

        return $root;
    }

    /**
     * @param  array<string, mixed>  $host
     */
    private function ftpConnect(array $host)
    {
        if (! function_exists('ftp_connect')) {
            throw new RuntimeException('ftp_extension_unavailable');
        }

        $hostname = (string) ($host['host'] ?? '');
        $port = (int) ($host['port'] ?? 21);
        $username = (string) ($host['username'] ?? '');
        $password = (string) ($host['password'] ?? '');
        $protocol = (string) ($host['protocol'] ?? 'ftp');

        if ($hostname === '' || $username === '' || $password === '') {
            throw new RuntimeException('host_credentials_incomplete');
        }

        if ($protocol === 'ftps' && function_exists('ftp_ssl_connect')) {
            $connection = @ftp_ssl_connect($hostname, $port, 15);
        } else {
            $connection = @ftp_connect($hostname, $port, 15);
        }

        if ($connection === false) {
            throw new RuntimeException('ftp_connect_failed');
        }

        if (! @ftp_login($connection, $username, $password)) {
            @ftp_close($connection);

            throw new RuntimeException('ftp_login_failed');
        }

        @ftp_pasv($connection, true);

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $host
     */
    private function ftpWriteFile(array $host, string $remotePath, string $contents): void
    {
        $connection = $this->ftpConnect($host);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            @ftp_close($connection);

            throw new RuntimeException('temp_stream_failed');
        }

        fwrite($stream, $contents);
        rewind($stream);

        $uploaded = @ftp_fput($connection, $remotePath, $stream, FTP_ASCII);
        fclose($stream);
        @ftp_close($connection);

        if ($uploaded !== true) {
            throw new RuntimeException('ftp_upload_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $host
     */
    private function ftpChmod(array $host, string $remotePath, int $mode): void
    {
        $connection = $this->ftpConnect($host);
        if (! function_exists('ftp_chmod') || @ftp_chmod($connection, $mode, $remotePath) === false) {
            @ftp_close($connection);

            throw new RuntimeException('ftp_chmod_failed');
        }
        @ftp_close($connection);
    }
}
