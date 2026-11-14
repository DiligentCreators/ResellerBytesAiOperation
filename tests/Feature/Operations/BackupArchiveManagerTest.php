<?php

declare(strict_types=1);

use App\Services\Operations\Backups\BackupArchiveManager;
use RuntimeException;

it('fails closed before database or network operations when Wasabi configuration is incomplete', function () {
    config([
        'filesystems.disks.s3.key' => null,
        'filesystems.disks.s3.secret' => null,
        'filesystems.disks.s3.bucket' => null,
        'filesystems.disks.s3.endpoint' => null,
        'operations.backups.object_lock_retention_days' => 0,
        'operations.metrics.backup_success_marker_path' => null,
    ]);

    expect(fn () => app(BackupArchiveManager::class)->create())
        ->toThrow(RuntimeException::class);
});
