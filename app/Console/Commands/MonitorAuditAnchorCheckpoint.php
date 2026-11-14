<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditAnchorService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

class MonitorAuditAnchorCheckpoint extends Command
{
    protected $signature = 'audit:anchor:monitor {checkpoint : Path to the downloaded signed checkpoint JSON} {--max-age-minutes=60 : Maximum permitted checkpoint age} {--max-unanchored-tail=0 : Maximum permitted unanchored audit events} {--expected-previous= : Expected prior checkpoint SHA-256 digest} {--json : Emit a redacted machine-readable evidence record}';

    protected $description = 'Fail closed when the external audit checkpoint is missing, stale, invalid, or behind the local chain';

    public function handle(AuditAnchorService $anchors): int
    {
        $observedAt = now()->utc()->toIso8601String();
        $evidence = [
            'schema_version' => 1,
            'status' => 'failed',
            'reason_code' => 'verification_failed',
            'observed_at_utc' => $observedAt,
            'environment' => null,
            'checkpoint_sha256' => null,
            'anchor_sequence' => null,
            'local_sequence' => null,
            'unanchored_tail' => null,
            'checkpoint_age_seconds' => null,
            'exit_code' => self::FAILURE,
        ];

        $finish = function (string $reasonCode, array $metrics = []) use (&$evidence): int {
            $evidence = array_merge($evidence, $metrics, [
                'status' => $reasonCode === 'healthy' ? 'healthy' : 'failed',
                'reason_code' => $reasonCode,
                'exit_code' => $reasonCode === 'healthy' ? self::SUCCESS : self::FAILURE,
            ]);

            if ($this->option('json')) {
                $this->line(json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } elseif ($reasonCode === 'healthy') {
                $this->info('Audit anchor monitor: healthy.');
                $this->line('Checkpoint SHA-256: '.$evidence['checkpoint_sha256']);
                $this->line('Checkpoint age seconds: '.max(0, (int) $evidence['checkpoint_age_seconds']));
                $this->line('Anchored sequence: '.$evidence['anchor_sequence']);
                $this->line('Local verified sequence: '.$evidence['local_sequence']);
                $this->line('Unanchored tail: '.$evidence['unanchored_tail']);
            } else {
                $this->error('Audit anchor monitor failed: '.$reasonCode);
            }

            return $evidence['exit_code'];
        };

        $path = $this->argument('checkpoint');
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return $finish('checkpoint_unavailable');
        }

        $maxAgeMinutes = filter_var($this->option('max-age-minutes'), FILTER_VALIDATE_INT);
        $maxUnanchoredTail = filter_var($this->option('max-unanchored-tail'), FILTER_VALIDATE_INT);
        if (! is_int($maxAgeMinutes) || $maxAgeMinutes < 1 || ! is_int($maxUnanchoredTail) || $maxUnanchoredTail < 0) {
            return $finish('invalid_threshold');
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            return $finish('checkpoint_read_failed');
        }

        $previous = $this->option('expected-previous');
        $expectedPrevious = is_string($previous) && $previous !== '' ? $previous : null;

        try {
            $verification = $anchors->verifyCheckpoint($bytes, $expectedPrevious);
            $envelope = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            $payload = is_array($envelope) ? ($envelope['payload'] ?? null) : null;
            $createdAt = is_array($payload) ? ($payload['created_at'] ?? null) : null;
            $environment = is_array($payload) ? ($payload['environment'] ?? null) : null;
            if (! is_string($createdAt) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $createdAt) !== 1) {
                return $finish('invalid_timestamp');
            }

            $checkpointTime = new DateTimeImmutable($createdAt, new DateTimeZone('UTC'));
            $ageSeconds = now()->utc()->getTimestamp() - $checkpointTime->getTimestamp();
            if ($ageSeconds < -300) {
                return $finish('timestamp_in_future');
            }
            if ($ageSeconds > $maxAgeMinutes * 60) {
                return $finish('checkpoint_stale', [
                    'checkpoint_sha256' => $verification['checkpoint_sha256'],
                    'anchor_sequence' => $verification['anchor_sequence'],
                    'local_sequence' => $verification['local_sequence'],
                    'unanchored_tail' => $verification['unanchored_tail'],
                    'checkpoint_age_seconds' => $ageSeconds,
                    'environment' => is_string($environment) && preg_match('/\A[a-zA-Z0-9._-]+\z/', $environment) === 1 ? $environment : null,
                ]);
            }
            if ($verification['unanchored_tail'] > $maxUnanchoredTail) {
                return $finish('unanchored_tail_exceeded', [
                    'checkpoint_sha256' => $verification['checkpoint_sha256'],
                    'anchor_sequence' => $verification['anchor_sequence'],
                    'local_sequence' => $verification['local_sequence'],
                    'unanchored_tail' => $verification['unanchored_tail'],
                    'checkpoint_age_seconds' => $ageSeconds,
                    'environment' => is_string($environment) && preg_match('/\A[a-zA-Z0-9._-]+\z/', $environment) === 1 ? $environment : null,
                ]);
            }

            return $finish('healthy', [
                'checkpoint_sha256' => $verification['checkpoint_sha256'],
                'anchor_sequence' => $verification['anchor_sequence'],
                'local_sequence' => $verification['local_sequence'],
                'unanchored_tail' => $verification['unanchored_tail'],
                'checkpoint_age_seconds' => $ageSeconds,
                'environment' => is_string($environment) && preg_match('/\A[a-zA-Z0-9._-]+\z/', $environment) === 1 ? $environment : null,
            ]);
        } catch (Throwable) {
            // Do not forward exception messages: database/provider exceptions may contain
            // credentials, hostnames, SQL fragments, or customer-controlled data.
            return $finish('verification_failed');
        }
    }
}
