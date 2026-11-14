<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audit\AuditCheckpointPublisher;
use Illuminate\Console\Command;
use Throwable;

final class PublishAuditAnchorCheckpoint extends Command
{
    protected $signature = 'audit:anchor:publish {checkpoint : Path to the exact signed checkpoint JSON bytes} {--expected-previous= : Required prior checkpoint SHA-256 digest for every checkpoint after the first}';

    protected $description = 'Publish a signed audit checkpoint to versioned S3 Object Lock storage and verify its exact immutable version';

    public function handle(AuditCheckpointPublisher $publisher): int
    {
        $path = $this->argument('checkpoint');
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            $this->error('A readable signed checkpoint file path is required.');

            return self::FAILURE;
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes) || $bytes === '') {
            $this->error('The signed checkpoint file is empty or unreadable.');

            return self::FAILURE;
        }

        $previous = $this->option('expected-previous');
        $expectedPrevious = is_string($previous) && trim($previous) !== '' ? trim($previous) : null;

        try {
            $result = $publisher->publish($bytes, $expectedPrevious);
        } catch (Throwable $exception) {
            // Exception messages are intentionally limited to stable reason codes.
            $this->error('Audit anchor publication failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Signed audit checkpoint published and verified.');
        $this->line('Object key: '.$result['object_key']);
        $this->line('Object VersionId: '.$result['object_version_id']);
        $this->line('Checkpoint SHA-256: '.$result['checkpoint_sha256']);
        $this->line('Anchored sequence: '.$result['chain_sequence']);
        $this->line('COMPLIANCE retain-until UTC: '.$result['retain_until']);
        $this->line('Reconciled after create error: '.($result['reconciled_after_create_error'] ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
