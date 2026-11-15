<?php

declare(strict_types=1);

namespace App\Services\Operations\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class BackupArchiveManager
{
    public function __construct(private readonly BackupArchiveCipher $cipher) {}

    /**
     * @return array<string, mixed>
     */
    public function create(): array
    {
        $settings = $this->storageSettings();
        $encryptionKey = $this->encryptionKey();
        $this->verifyBucket($settings);

        $connection = $this->postgresConnection(false);
        $workingDirectory = $this->createWorkingDirectory();
        $dumpPath = $workingDirectory.'/database.dump';
        $encryptedPath = $workingDirectory.'/database.rbbackup';
        $verificationPath = $workingDirectory.'/downloaded.rbbackup';
        $manifestPath = $workingDirectory.'/manifest.json';

        try {
            $this->dumpDatabase($connection, $dumpPath);
            $checksums = $this->cipher->encryptFile($dumpPath, $encryptedPath, $encryptionKey);
            if ($checksums['encrypted_bytes'] > 5_000_000_000) {
                throw new RuntimeException('Encrypted backup exceeds the 5 GiB single-PutObject limit; multipart Object Lock upload is required.');
            }

            $backupId = (string) Str::uuid();
            $createdAt = CarbonImmutable::now()->utc();
            $retentionUntil = $createdAt
                ->addDays($settings['retention_days'])
                ->format('Y-m-d\TH:i:s\Z');
            $objectKey = 'backups/'.$createdAt->format('Y/m/d').'/'.$backupId.'.rbbackup';
            $manifestKey = 'backups/manifests/'.$backupId.'.json';

            $manifest = [
                'schema_version' => 2,
                'backup_id' => $backupId,
                'created_at' => $createdAt->toIso8601String(),
                'database_driver' => 'pgsql',
                'database_name' => (string) $connection['database'],
                'database_host' => (string) ($connection['host'] ?? ''),
                'database_port' => (int) ($connection['port'] ?? 5432),
                'archive_format' => 'pg_dump_custom',
                'object_key' => $objectKey,
                'manifest_key' => $manifestKey,
                'plaintext_sha256' => $checksums['plaintext_sha256'],
                'encrypted_sha256' => $checksums['encrypted_sha256'],
                'encrypted_bytes' => $checksums['encrypted_bytes'],
                'object_lock_mode' => 'COMPLIANCE',
                'retention_until' => $retentionUntil,
                'rpo_hours' => (int) config('operations.recovery.rpo_hours', 24),
                'rto_hours' => (int) config('operations.recovery.rto_hours', 4),
            ];

            $objectVersionId = $this->putLockedObject(
                $settings,
                $objectKey,
                $encryptedPath,
                $retentionUntil,
                $checksums['encrypted_sha256'],
                'application/octet-stream',
            );
            $manifest['object_version_id'] = $objectVersionId;
            $this->verifyLockedObject(
                $settings,
                $objectKey,
                $checksums['encrypted_sha256'],
                $checksums['encrypted_bytes'],
                $retentionUntil,
                $objectVersionId,
            );

            $this->downloadObject($settings, $objectKey, $verificationPath, $objectVersionId);
            if (! hash_equals($checksums['encrypted_sha256'], (string) hash_file('sha256', $verificationPath))) {
                throw new RuntimeException('Uploaded backup did not pass read-back checksum verification.');
            }

            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $manifestHash = hash_file('sha256', $manifestPath);
            if (! is_string($manifestHash)) {
                throw new RuntimeException('Unable to calculate backup manifest checksum.');
            }

            $manifestVersionId = $this->putLockedObject(
                $settings,
                $manifestKey,
                $manifestPath,
                $retentionUntil,
                $manifestHash,
                'application/json',
            );
            $manifestBytes = (int) filesize($manifestPath);
            $this->verifyLockedObject(
                $settings,
                $manifestKey,
                $manifestHash,
                $manifestBytes,
                $retentionUntil,
                $manifestVersionId,
            );
            $this->downloadObject($settings, $manifestKey, $verificationPath, $manifestVersionId);
            if (! hash_equals($manifestHash, (string) hash_file('sha256', $verificationPath))) {
                throw new RuntimeException('Uploaded backup manifest did not pass exact-version read-back checksum verification.');
            }

            $this->updateSuccessMarker($backupId, $createdAt);

            return $manifest;
        } finally {
            $this->removeWorkingDirectory($workingDirectory);
        }
    }

    /**
     * @return array{manifest: array<string, mixed>, dump_path: string, working_directory: string}
     */
    public function downloadAndVerify(string $backupId): array
    {
        if (! Str::isUuid($backupId)) {
            throw new RuntimeException('Backup ID must be a valid UUID.');
        }

        $settings = $this->storageSettings();
        $encryptionKey = $this->encryptionKey();
        $this->verifyBucket($settings);

        $workingDirectory = $this->createWorkingDirectory();
        $manifestPath = $workingDirectory.'/manifest.json';
        $encryptedPath = $workingDirectory.'/database.rbbackup';
        $dumpPath = $workingDirectory.'/database.dump';

        try {
            $manifestKey = 'backups/manifests/'.$backupId.'.json';
            $manifestVersionId = $this->downloadObject($settings, $manifestKey, $manifestPath);
            if (! is_string($manifestVersionId) || trim($manifestVersionId) === '') {
                throw new RuntimeException('Wasabi did not return a version ID for the backup manifest.');
            }
            $manifestSize = filesize($manifestPath);
            if (! is_int($manifestSize) || $manifestSize < 2 || $manifestSize > 1_048_576) {
                throw new RuntimeException('Backup manifest exceeds the 1 MiB validation limit.');
            }

            $manifestContents = file_get_contents($manifestPath);
            if (! is_string($manifestContents)) {
                throw new RuntimeException('Unable to read backup manifest.');
            }

            $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)
                || ($manifest['schema_version'] ?? null) !== 2
                || ($manifest['backup_id'] ?? null) !== $backupId
                || ($manifest['database_driver'] ?? null) !== 'pgsql'
                || ($manifest['object_lock_mode'] ?? null) !== 'COMPLIANCE'
                || ! is_string($manifest['object_key'] ?? null)
                || ! str_starts_with($manifest['object_key'], 'backups/')
                || ! is_string($manifest['encrypted_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $manifest['encrypted_sha256']) !== 1
                || ! is_string($manifest['plaintext_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $manifest['plaintext_sha256']) !== 1
                || ! is_string($manifest['object_version_id'] ?? null)
                || trim($manifest['object_version_id']) === ''
                || ! is_numeric($manifest['encrypted_bytes'] ?? null)
                || (int) $manifest['encrypted_bytes'] < 1
                || (int) $manifest['encrypted_bytes'] > 5_000_000_000
                || ! is_string($manifest['retention_until'] ?? null)
                || ! is_string($manifest['database_host'] ?? null)
                || trim($manifest['database_host']) === ''
                || ! is_numeric($manifest['database_port'] ?? null)
                || (int) $manifest['database_port'] < 1
                || (int) $manifest['database_port'] > 65535) {
                throw new RuntimeException('Backup manifest is invalid or unsupported.');
            }

            $manifestHash = hash_file('sha256', $manifestPath);
            if (! is_string($manifestHash)) {
                throw new RuntimeException('Unable to calculate downloaded backup manifest checksum.');
            }
            $this->verifyLockedObject(
                $settings,
                $manifestKey,
                $manifestHash,
                (int) filesize($manifestPath),
                $manifest['retention_until'],
                $manifestVersionId,
            );

            $createdAt = CarbonImmutable::parse($manifest['created_at'])->utc();
            $expectedObjectKey = 'backups/'.$createdAt->format('Y/m/d').'/'.$backupId.'.rbbackup';
            if (! hash_equals($expectedObjectKey, $manifest['object_key'])) {
                throw new RuntimeException('Backup manifest object key does not match its backup identity.');
            }

            $expectedRetention = $createdAt->addDays($settings['retention_days']);
            $manifestRetention = CarbonImmutable::parse($manifest['retention_until'])->utc();
            if ($manifestRetention->lessThan($expectedRetention->subMinute())) {
                throw new RuntimeException('Backup manifest retention is shorter than the configured immutable retention period.');
            }

            $this->verifyLockedObject(
                $settings,
                $manifest['object_key'],
                $manifest['encrypted_sha256'],
                (int) $manifest['encrypted_bytes'],
                $manifest['retention_until'],
                $manifest['object_version_id'],
            );
            $this->downloadObject(
                $settings,
                $manifest['object_key'],
                $encryptedPath,
                $manifest['object_version_id'],
            );
            $encryptedHash = hash_file('sha256', $encryptedPath);
            if (! is_string($encryptedHash) || ! hash_equals($manifest['encrypted_sha256'], $encryptedHash)) {
                throw new RuntimeException('Downloaded backup failed its encrypted checksum verification.');
            }

            $this->cipher->decryptFile($encryptedPath, $dumpPath, $encryptionKey);
            $plaintextHash = hash_file('sha256', $dumpPath);
            if (! is_string($plaintextHash) || ! hash_equals($manifest['plaintext_sha256'], $plaintextHash)) {
                throw new RuntimeException('Decrypted backup failed its plaintext checksum verification.');
            }

            $this->runProcess([
                (string) config('operations.backups.pg_restore_binary', 'pg_restore'),
                '--list',
                $dumpPath,
            ], [], 300, 'PostgreSQL backup archive validation failed');

            return [
                'manifest' => $manifest,
                'dump_path' => $dumpPath,
                'working_directory' => $workingDirectory,
            ];
        } catch (Throwable $exception) {
            $this->removeWorkingDirectory($workingDirectory);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    public function restore(array $artifact): void
    {
        $connection = $this->postgresConnection(true);
        $manifest = $artifact['manifest'] ?? null;

        if (! is_array($manifest) || ($manifest['database_driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Backup database identity does not match the configured restore target.');
        }

        $this->assertRestoreTargetMatches($manifest);

        $dumpPath = $artifact['dump_path'] ?? null;

        if (! is_string($dumpPath) || ! is_file($dumpPath)) {
            throw new RuntimeException('Verified restore archive is unavailable.');
        }

        $command = [
            (string) config('operations.backups.pg_restore_binary', 'pg_restore'),
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--exit-on-error',
            '--single-transaction',
            '--host='.(string) $connection['host'],
            '--port='.(string) $connection['port'],
            '--username='.(string) $connection['username'],
            '--dbname='.(string) $connection['database'],
            $dumpPath,
        ];

        $this->runProcess($command, [
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            'PGCONNECT_TIMEOUT' => '15',
        ], 14400, 'PostgreSQL restore failed');
    }

    /**
     * Refuse to restore a manifest into a different configured PostgreSQL endpoint.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function assertRestoreTargetMatches(array $manifest): void
    {
        $connectionName = (string) config('database.default');
        $target = config('database.connections.'.$connectionName, []);
        $targetDatabase = $target['database'] ?? null;
        $targetHost = $target['host'] ?? null;
        $targetPort = $target['port'] ?? null;
        $manifestDatabase = $manifest['database_name'] ?? null;
        $manifestHost = $manifest['database_host'] ?? null;
        $manifestPort = $manifest['database_port'] ?? null;

        if (! is_string($targetDatabase)
            || ! is_string($manifestDatabase)
            || ! hash_equals($targetDatabase, $manifestDatabase)
            || ! is_string($targetHost)
            || ! is_string($manifestHost)
            || ! hash_equals(mb_strtolower(trim($targetHost)), mb_strtolower(trim($manifestHost)))
            || ! is_numeric($targetPort)
            || ! is_numeric($manifestPort)
            || (int) $targetPort !== (int) $manifestPort) {
            throw new RuntimeException('Backup database identity does not match the configured restore target.');
        }
    }

    /**
     * Store an immutable approval record before a destructive restore begins.
     *
     * @param  array<string, mixed>  $record
     */
    public function recordRestoreApproval(array $record): void
    {
        $settings = $this->storageSettings();
        $this->verifyBucket($settings);

        $workingDirectory = $this->createWorkingDirectory();
        $path = $workingDirectory.'/restore-approval.json';

        try {
            $contents = json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $written = file_put_contents($path, $contents, LOCK_EX);
            if ($written !== strlen($contents) || ! chmod($path, 0600)) {
                throw new RuntimeException('Unable to persist the complete private restore approval record.');
            }

            $hash = hash_file('sha256', $path);
            if (! is_string($hash)) {
                throw new RuntimeException('Unable to calculate restore approval checksum.');
            }

            $createdAt = CarbonImmutable::now()->utc();
            $retentionUntil = $createdAt
                ->addDays($settings['retention_days'])
                ->format('Y-m-d\TH:i:s\Z');
            $key = 'restore-events/'.$createdAt->format('Y/m/d').'/'.Str::uuid().'.json';

            $versionId = $this->putLockedObject($settings, $key, $path, $retentionUntil, $hash, 'application/json');
            $this->verifyLockedObject($settings, $key, $hash, (int) filesize($path), $retentionUntil, $versionId);
        } finally {
            $this->removeWorkingDirectory($workingDirectory);
        }
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    public function cleanupArtifact(array $artifact): void
    {
        $directory = $artifact['working_directory'] ?? null;
        if (is_string($directory)) {
            $this->removeWorkingDirectory($directory);
        }
    }

    /**
     * @return array{bucket: string, endpoint: string, region: string, retention_days: int, aws_binary: string}
     */
    private function storageSettings(): array
    {
        $disk = config('filesystems.disks.s3', []);
        $bucket = $disk['bucket'] ?? null;
        $endpoint = $disk['endpoint'] ?? null;
        $region = $disk['region'] ?? null;
        $retentionDays = (int) config('operations.backups.object_lock_retention_days', 0);

        if (! is_string($bucket) || trim($bucket) === ''
            || ! is_string($endpoint) || parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
            || ! is_string($region) || trim($region) === ''
            || filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || $retentionDays < 1
            || ! is_string($disk['key'] ?? null) || trim($disk['key']) === ''
            || ! is_string($disk['secret'] ?? null) || trim($disk['secret']) === ''
            || ! is_string(config('operations.metrics.backup_success_marker_path'))
            || trim((string) config('operations.metrics.backup_success_marker_path')) === ''
            || trim((string) config('operations.backups.aws_cli_binary', 'aws')) === '') {
            throw new RuntimeException('Wasabi S3 endpoint, bucket, region, credentials, and positive Object Lock retention days are required.');
        }

        return [
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'region' => $region,
            'retention_days' => $retentionDays,
            'aws_binary' => (string) config('operations.backups.aws_cli_binary', 'aws'),
        ];
    }

    /**
     * @param  array{bucket: string, endpoint: string, region: string, retention_days: int, aws_binary: string}  $settings
     */
    private function verifyBucket(array $settings): void
    {
        $versioning = json_decode($this->runAws($settings, [
            's3api', 'get-bucket-versioning', '--bucket', $settings['bucket'],
        ]), true, 512, JSON_THROW_ON_ERROR);
        if (($versioning['Status'] ?? null) !== 'Enabled') {
            throw new RuntimeException('Backup bucket versioning must be enabled before use.');
        }

        $lock = json_decode($this->runAws($settings, [
            's3api', 'get-object-lock-configuration', '--bucket', $settings['bucket'],
        ]), true, 512, JSON_THROW_ON_ERROR);

        if (($lock['ObjectLockConfiguration']['ObjectLockEnabled'] ?? null) !== 'Enabled') {
            throw new RuntimeException('Backup bucket must have S3 Object Lock enabled.');
        }
    }

    /**
     * @param  array{bucket: string, endpoint: string, region: string, retention_days: int, aws_binary: string}  $settings
     */
    private function putLockedObject(
        array $settings,
        string $key,
        string $path,
        string $retentionUntil,
        string $sha256,
        string $contentType,
    ): string {
        $response = json_decode($this->runAws($settings, [
            's3api', 'put-object',
            '--bucket', $settings['bucket'],
            '--key', $key,
            '--body', $path,
            '--content-type', $contentType,
            '--server-side-encryption', 'AES256',
            '--object-lock-mode', 'COMPLIANCE',
            '--object-lock-retain-until-date', $retentionUntil,
            '--metadata', 'sha256='.$sha256,
            '--if-none-match', '*',
        ]), true, 512, JSON_THROW_ON_ERROR);

        $versionId = is_array($response) ? ($response['VersionId'] ?? null) : null;
        if (! is_string($versionId) || trim($versionId) === '') {
            throw new RuntimeException('Wasabi did not return a version ID for the immutable backup object.');
        }

        return $versionId;
    }

    /**
     * @param  array{bucket: string, endpoint: string, region: string, retention_days: int, aws_binary: string}  $settings
     */
    private function verifyLockedObject(
        array $settings,
        string $key,
        string $sha256,
        int $bytes,
        string $retentionUntil,
        string $versionId,
    ): void {
        $head = json_decode($this->runAws($settings, [
            's3api', 'head-object',
            '--bucket', $settings['bucket'],
            '--key', $key,
            '--version-id', $versionId,
        ]), true, 512, JSON_THROW_ON_ERROR);

        $actualRetention = isset($head['ObjectLockRetainUntilDate'])
            ? CarbonImmutable::parse((string) $head['ObjectLockRetainUntilDate'])->utc()
            : null;
        $expectedRetention = CarbonImmutable::parse($retentionUntil)->utc();

        if (($head['VersionId'] ?? null) !== $versionId
            || ($head['ObjectLockMode'] ?? null) !== 'COMPLIANCE'
            || $actualRetention === null
            || $actualRetention->lessThan($expectedRetention->subMinute())
            || (int) ($head['ContentLength'] ?? -1) !== $bytes
            || ! hash_equals($sha256, (string) ($head['Metadata']['sha256'] ?? ''))) {
            throw new RuntimeException('Backup object failed Object Lock, size, or checksum metadata verification.');
        }
    }

    /**
     * @param  array{bucket: string, endpoint: string, region: string, retention_days: int, aws_binary: string}  $settings
     */
    private function downloadObject(
        array $settings,
        string $key,
        string $path,
        ?string $versionId = null,
    ): ?string {
        $arguments = [
            's3api', 'get-object',
            '--bucket', $settings['bucket'],
            '--key', $key,
        ];

        if ($versionId !== null) {
            $arguments[] = '--version-id';
            $arguments[] = $versionId;
        }

        $arguments[] = $path;
        $response = json_decode($this->runAws($settings, $arguments), true, 512, JSON_THROW_ON_ERROR);
        $downloadedVersionId = is_array($response) ? ($response['VersionId'] ?? null) : null;

        if (! is_string($downloadedVersionId) || trim($downloadedVersionId) === '') {
            return null;
        }

        if ($versionId !== null && ! hash_equals($versionId, $downloadedVersionId)) {
            throw new RuntimeException('Downloaded backup object version does not match the requested immutable version.');
        }

        return $downloadedVersionId;
    }

    /**
     * @param  array{bucket: string, endpoint: string, region: string, retention_days: int, aws_binary: string}  $settings
     * @param  list<string>  $arguments
     */
    private function runAws(array $settings, array $arguments): string
    {
        $command = [
            (string) $settings['aws_binary'],
            ...$arguments,
            '--endpoint-url', (string) $settings['endpoint'],
            '--region', (string) $settings['region'],
            '--output', 'json',
        ];

        $disk = config('filesystems.disks.s3', []);
        $process = new Process($command, base_path(), [
            'AWS_ACCESS_KEY_ID' => (string) ($disk['key'] ?? ''),
            'AWS_SECRET_ACCESS_KEY' => (string) ($disk['secret'] ?? ''),
            'AWS_DEFAULT_REGION' => $settings['region'],
            'AWS_EC2_METADATA_DISABLED' => 'true',
            'AWS_PAGER' => '',
        ], null, 7200);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Wasabi S3 operation failed: '.($arguments[1] ?? 'unknown').' (exit '.$process->getExitCode().').');
        }

        return $process->getOutput();
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function dumpDatabase(array $connection, string $destination): void
    {
        $command = [
            (string) config('operations.backups.pg_dump_binary', 'pg_dump'),
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--host='.(string) ($connection['host'] ?? ''),
            '--port='.(string) ($connection['port'] ?? 5432),
            '--username='.(string) ($connection['username'] ?? ''),
            '--dbname='.(string) ($connection['database'] ?? ''),
            '--file='.$destination,
        ];

        $this->runProcess($command, [
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            'PGCONNECT_TIMEOUT' => '15',
        ], 7200, 'PostgreSQL backup command failed');
    }

    /**
     * @return array<string, mixed>
     */
    private function postgresConnection(bool $forRestore): array
    {
        $name = (string) config('database.default');
        $connection = config('database.connections.'.$name, []);

        if (! is_array($connection)
            || ($connection['driver'] ?? null) !== 'pgsql'
            || ! is_string($connection['database'] ?? null)
            || trim($connection['database']) === ''
            || ! is_string($connection['host'] ?? null)
            || trim($connection['host']) === ''
            || ! is_numeric($connection['port'] ?? null)
            || (int) $connection['port'] < 1
            || (int) $connection['port'] > 65535
            || isset($connection['url'])) {
            throw new RuntimeException('Backup tooling currently supports an explicit PostgreSQL connection only.');
        }

        $role = $forRestore ? 'restore' : 'dump';
        $username = config('operations.backups.'.$role.'_username');
        $password = config('operations.backups.'.$role.'_password');

        if (! is_string($username) || trim($username) === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Dedicated backup read-only and restore migration credentials are required.');
        }

        $connection['username'] = $username;
        $connection['password'] = $password;

        return $connection;
    }

    private function encryptionKey(): string
    {
        $encoded = config('operations.backups.encryption_key');
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;

        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be base64 for exactly 32 random bytes.');
        }

        return $key;
    }

    private function createWorkingDirectory(): string
    {
        $base = storage_path('app/private/operations-backups');
        if (! is_dir($base) && ! mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new RuntimeException('Unable to create the private backup working directory.');
        }

        $directory = $base.'/'.Str::uuid();
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create a private backup workspace.');
        }

        return $directory;
    }

    private function updateSuccessMarker(string $backupId, CarbonImmutable $createdAt): void
    {
        $path = config('operations.metrics.backup_success_marker_path');

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('OPERATIONS_BACKUP_SUCCESS_MARKER_PATH must be configured before backups can be marked successful.');
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create backup success marker directory.');
        }

        $temporary = tempnam($directory, 'rb-backup-marker-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create backup success marker temporary file.');
        }

        try {
            $contents = json_encode([
                'backup_id' => $backupId,
                'created_at' => $createdAt->toIso8601String(),
            ], JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to write the complete private backup success marker.');
            }

            if (! rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically update backup success marker.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function runProcess(array $command, array $environment, int $timeout, string $failureMessage): void
    {
        $process = new Process($command, base_path(), $environment, null, $timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($failureMessage.' (exit '.$process->getExitCode().').');
        }
    }

    private function removeWorkingDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $directory.'/'.$file;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
