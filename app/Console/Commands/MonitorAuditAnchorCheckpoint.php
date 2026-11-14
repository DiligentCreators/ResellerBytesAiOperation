<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditAnchorService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

class MonitorAuditAnchorCheckpoint extends Command
{
    protected $signature = 'audit:anchor:monitor {checkpoint : Path to the downloaded signed checkpoint JSON} {--max-age-minutes=60 : Maximum permitted checkpoint age} {--max-unanchored-tail=0 : Maximum permitted unanchored audit events} {--expected-previous= : Expected prior checkpoint SHA-256 digest}';

    protected $description = 'Fail closed when the external audit checkpoint is missing, stale, invalid, or behind the local chain';

    public function handle(AuditAnchorService $anchors): int
    {
        $path = $this->argument('checkpoint');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            $this->error('Audit anchor monitor: checkpoint file is unavailable.');

            return self::FAILURE;
        }

        $maxAgeMinutes = filter_var($this->option('max-age-minutes'), FILTER_VALIDATE_INT);
        $maxUnanchoredTail = filter_var($this->option('max-unanchored-tail'), FILTER_VALIDATE_INT);
        if (! is_int($maxAgeMinutes) || $maxAgeMinutes < 1 || ! is_int($maxUnanchoredTail) || $maxUnanchoredTail < 0) {
            $this->error('Audit anchor monitor: thresholds are invalid.');

            return self::FAILURE;
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            $this->error('Audit anchor monitor: checkpoint could not be read.');

            return self::FAILURE;
        }

        $previous = $this->option('expected-previous');
        $expectedPrevious = is_string($previous) && $previous !== '' ? $previous : null;

        try {
            $verification = $anchors->verifyCheckpoint($bytes, $expectedPrevious);
            $envelope = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            $createdAt = $envelope['payload']['created_at'] ?? null;
            if (! is_string($createdAt) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $createdAt) !== 1) {
                throw new \RuntimeException('audit_anchor_invalid_timestamp');
            }

            $checkpointTime = new DateTimeImmutable($createdAt, new DateTimeZone('UTC'));
            $ageSeconds = now()->utc()->getTimestamp() - $checkpointTime->getTimestamp();
            if ($ageSeconds < -300) {
                throw new \RuntimeException('audit_anchor_timestamp_in_future');
            }
            if ($ageSeconds > $maxAgeMinutes * 60) {
                throw new \RuntimeException('audit_anchor_checkpoint_stale');
            }
            if ($verification['unanchored_tail'] > $maxUnanchoredTail) {
                throw new \RuntimeException('audit_anchor_unanchored_tail_exceeded');
            }
        } catch (Throwable $exception) {
            $this->error('Audit anchor monitor: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Audit anchor monitor: healthy.');
        $this->line('Checkpoint SHA-256: '.$verification['checkpoint_sha256']);
        $this->line('Checkpoint age seconds: '.max(0, $ageSeconds));
        $this->line('Anchored sequence: '.$verification['anchor_sequence']);
        $this->line('Local verified sequence: '.$verification['local_sequence']);
        $this->line('Unanchored tail: '.$verification['unanchored_tail']);

        return self::SUCCESS;
    }
}
