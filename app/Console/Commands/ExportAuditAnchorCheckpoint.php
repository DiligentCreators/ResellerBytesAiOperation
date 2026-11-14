<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditAnchorService;
use Illuminate\Console\Command;
use Throwable;

class ExportAuditAnchorCheckpoint extends Command
{
    protected $signature = 'audit:anchor:export {--previous= : SHA-256 digest of the prior external checkpoint}';

    protected $description = 'Export a signed Ed25519 checkpoint of the verified audit-chain head';

    public function handle(AuditAnchorService $anchors): int
    {
        try {
            $previous = $this->option('previous');
            $previousDigest = is_string($previous) && $previous !== '' ? $previous : null;
            $this->output->write($anchors->createCheckpoint($previousDigest));
        } catch (Throwable $exception) {
            $this->error('Audit anchor export failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
