<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\Backups\BackupArchiveManager;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class CreateOperationalBackup extends Command
{
    protected $signature = 'operations:backup:create';

    protected $description = 'Create, encrypt, upload, and verify a compliance-locked PostgreSQL backup';

    /**
     * @throws JsonException
     */
    public function handle(BackupArchiveManager $backups): int
    {
        try {
            $manifest = $backups->create();
        } catch (Throwable $exception) {
            $this->error('Backup failed: '.$exception::class);

            return self::FAILURE;
        }

        $this->line(json_encode([
            'status' => 'verified',
            'backup' => $manifest,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
