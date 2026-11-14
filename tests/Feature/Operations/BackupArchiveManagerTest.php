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

it('refuses to restore a backup unless database name host and port all match', function () {
    $connectionName = (string) config('database.default');
    $targetDatabase = (string) config('database.connections.'.$connectionName.'.database');

    // Keep this identity-only test independent from a live PostgreSQL connection.
    config([
        'database.connections.'.$connectionName.'.host' => 'recovery-db.internal',
        'database.connections.'.$connectionName.'.port' => 5432,
    ]);

    $backups = app(BackupArchiveManager::class);
    $matchingIdentity = [
        'database_name' => $targetDatabase,
        'database_host' => 'recovery-db.internal',
        'database_port' => 5432,
    ];

    expect(fn () => $backups->assertRestoreTargetMatches([
        ...$matchingIdentity,
        'database_name' => 'different_database',
    ]))->toThrow(RuntimeException::class, 'database identity does not match');

    expect(fn () => $backups->assertRestoreTargetMatches([
        ...$matchingIdentity,
        'database_host' => 'other-db.internal',
    ]))->toThrow(RuntimeException::class, 'database identity does not match');

    expect(fn () => $backups->assertRestoreTargetMatches([
        ...$matchingIdentity,
        'database_port' => 5433,
    ]))->toThrow(RuntimeException::class, 'database identity does not match');

    expect(fn () => $backups->assertRestoreTargetMatches($matchingIdentity))
        ->not->toThrow(RuntimeException::class);
});
