<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditAnchorService;
use Illuminate\Console\Command;
use Throwable;

class VerifyAuditAnchorCheckpoint extends Command
{
    private const MAX_CHECKPOINT_BYTES = 65536;
    protected $signature = 'audit:anchor:verify {checkpoint : Path to the downloaded signed checkpoint JSON} {--expected-previous= : Expected prior checkpoint SHA-256 digest}';

    protected $description = 'Verify an external signed audit checkpoint against the local audit-chain prefix';

    public function handle(AuditAnchorService $anchors): int
    {
        $path = $this->argument('checkpoint');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            $this->error('A readable checkpoint file path is required.');

            return self::FAILURE;
        }

        $bytes = file_get_contents($path, false, null, 0, self::MAX_CHECKPOINT_BYTES + 1);
        if ($bytes === false) {
            $this->error('Unable to read the checkpoint file.');

            return self::FAILURE;
        }
        if (strlen($bytes) > self::MAX_CHECKPOINT_BYTES) {
            $this->error('Audit checkpoint exceeds the 64 KiB size limit.');

            return self::FAILURE;
        }

        $previous = $this->option('expected-previous');
        $expectedPrevious = is_string($previous) && $previous !== '' ? $previous : null;

        try {
            $result = $anchors->verifyCheckpoint($bytes, $expectedPrevious);
        } catch (Throwable $exception) {
            $this->error('Audit anchor verification failed. Check sanitized operational logs and configuration.');

            return self::FAILURE;
        }

        $this->info('Signed external checkpoint verified.');
        $this->line('Checkpoint SHA-256: '.$result['checkpoint_sha256']);
        $this->line('Anchored sequence: '.$result['anchor_sequence']);
        $this->line('Local verified sequence: '.$result['local_sequence']);
        $this->line('Unanchored tail: '.$result['unanchored_tail']);

        return self::SUCCESS;
    }
}
