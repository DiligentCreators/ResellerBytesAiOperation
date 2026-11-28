<?php

namespace ResellerBytesAiOps;

use Throwable;

final class CronMonitor
{
    private const LOCK_FILENAME = 'resellerbytes_ai_ops_cron.lock';

    private const LOCK_TTL_SECONDS = 900;

    private const HEARTBEAT_CONNECT_TIMEOUT = 2;

    private const HEARTBEAT_TIMEOUT = 5;

    /** @var (callable(string): void)|null */
    private $activityLogger;

    /**
     * @param  (callable(string): void)|null  $activityLogger
     */
    public function __construct(
        private readonly SignedApiClient $client = new SignedApiClient,
        ?callable $activityLogger = null,
    ) {
        $this->activityLogger = $activityLogger;
    }

    /**
     * Run after AfterCronJob: heartbeat, optional auto-collect, optional approved remediations.
     *
     * @param  array{integration_id: string, secret: string}  $credentials
     * @param  array{addon_version: string, whmcs_version: string, cli_php_version: string, sent_at: string, cron_kind: string}  $heartbeatBody
     */
    public function run(array $credentials, array $heartbeatBody, string $lockDirectory): void
    {
        $lockHandle = $this->acquireLock($lockDirectory);
        if ($lockHandle === false) {
            // Another cron worker holds the lock.
            return;
        }

        $previousFailure = $GLOBALS['resellerbytes_ai_ops_last_cron_failure'] ?? null;

        try {
            $heartbeat = $this->client->heartbeat(
                $credentials,
                $heartbeatBody,
                self::HEARTBEAT_CONNECT_TIMEOUT,
                self::HEARTBEAT_TIMEOUT,
            );

            if (! empty($heartbeat['auto_collect_due'])) {
                $diagnostics = (new DiagnosticCollector)->collect();
                $this->client->submit($credentials, $diagnostics);
            }

            if (! empty($heartbeat['pending_approved_actions'])) {
                $this->applyApprovedActions($credentials);
            }

            $GLOBALS['resellerbytes_ai_ops_last_cron_failure'] = null;
        } catch (Throwable $exception) {
            $code = FailureCode::sanitize($exception->getMessage());
            if ($previousFailure !== $code) {
                $this->logFailure('ResellerBytes AI Ops cron monitor failed: '.$code);
            }
            $GLOBALS['resellerbytes_ai_ops_last_cron_failure'] = $code;
        } finally {
            if (is_resource($lockHandle)) {
                $this->releaseLock($lockHandle);
            }
        }
    }

    /**
     * @param  array{integration_id: string, secret: string}  $credentials
     */
    private function applyApprovedActions(array $credentials): void
    {
        $pending = $this->client->listPendingSafeActions($credentials);
        $runner = new RemediationRunner;

        foreach ($pending['actions'] as $action) {
            if (! is_array($action) || ! isset($action['id']) || ! is_string($action['id'])) {
                continue;
            }

            $result = $runner->apply($credentials, $action, $this->client);
            $payload = [
                'status' => $result['status'],
                'summary' => $result['summary'],
                'evidence' => $result['evidence'],
            ];
            if (isset($result['error_code'])) {
                $payload['error_code'] = $result['error_code'];
            }

            $this->client->reportSafeActionResult($credentials, $action['id'], $payload);

            if ($result['status'] === 'success'
                && ! in_array($action['action_key'] ?? '', [
                    'pull_and_run_diagnostic_collect',
                    'request_diagnostic_collect',
                ], true)
            ) {
                $diagnostics = (new DiagnosticCollector)->collect();
                $this->client->submit($credentials, $diagnostics);
            }
        }
    }

    /**
     * @return resource|false|null False when another process holds the lock; null when locking is unavailable.
     */
    private function acquireLock(string $lockDirectory)
    {
        if ($lockDirectory === '' || ! is_dir($lockDirectory) || ! is_writable($lockDirectory)) {
            return null;
        }

        $path = rtrim(str_replace('\\', '/', $lockDirectory), '/').'/'.self::LOCK_FILENAME;
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return null;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            // Stale lock recovery: if the lock file is older than TTL, try once more after truncating.
            $mtime = @filemtime($path);
            if (is_int($mtime) && (time() - $mtime) > self::LOCK_TTL_SECONDS) {
                ftruncate($handle, 0);
                if (! flock($handle, LOCK_EX | LOCK_NB)) {
                    fclose($handle);

                    return false;
                }
            } else {
                fclose($handle);

                return false;
            }
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function logFailure(string $message): void
    {
        if ($this->activityLogger !== null) {
            ($this->activityLogger)($message);

            return;
        }

        if (function_exists('logActivity')) {
            logActivity($message);
        }
    }
}
