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
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'host' => 'recovery-db.internal',
            'port' => 5432,
            'database' => 'recovery_target',
            'username' => 'rb_runtime',
            'password' => 'runtime-secret',
        ],
        'operations.backups.restore_username' => 'rb_migrator',
        'operations.backups.restore_password' => 'restore-secret',
    ]);

    expect(fn () => app(BackupArchiveManager::class)->restore([
        'manifest' => [
            'database_driver' => 'pgsql',
            'database_name' => 'production_database',
        ],
        'dump_path' => '/tmp/not-opened.dump',
    ]))->toThrow(RuntimeException::class, 'database identity does not match');
});


it('rejects a restore manifest whose database name differs from the configured target', function () {
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.database' => 'resellerbytes_rehearsal',
    ]);

    expect(fn () => app(BackupArchiveManager::class)->assertRestoreTargetMatches([
        'database_name' => 'resellerbytes_production',
    ]))->toThrow(RuntimeException::class);

    expect(fn () => app(BackupArchiveManager::class)->assertRestoreTargetMatches([
        'database_name' => 'resellerbytes_rehearsal',
    ]))->not->toThrow(RuntimeException::class);
});
