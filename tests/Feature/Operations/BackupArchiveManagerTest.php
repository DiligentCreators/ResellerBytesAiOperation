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

it('refuses to restore a backup into a database with a different identity', function () {
    $connectionName = (string) config('database.default');
    $targetDatabase = (string) config('database.connections.'.$connectionName.'.database');
    $backups = app(BackupArchiveManager::class);

    expect(fn () => $backups->assertRestoreTargetMatches([
        'database_name' => 'different_database',
    ]))->toThrow(RuntimeException::class, 'database identity does not match');

    expect(fn () => $backups->assertRestoreTargetMatches([
        'database_name' => $targetDatabase,
    ]))->not->toThrow(RuntimeException::class);
});
