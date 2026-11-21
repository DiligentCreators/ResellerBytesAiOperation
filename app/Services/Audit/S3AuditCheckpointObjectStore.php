<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\AuditCheckpointObjectStore;
use App\Data\AuditCheckpointObjectVersion;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class S3AuditCheckpointObjectStore implements AuditCheckpointObjectStore
{
    private const MAX_CHECKPOINT_BYTES = 65536;

    public function createImmutableIfAbsent(
        string $objectKey,
        string $contents,
        DateTimeImmutable $retainUntil
    ): AuditCheckpointObjectVersion {
        $settings = $this->settings();
        $this->assertObjectKey($objectKey);
        $path = $this->temporaryFile($contents);

        try {
            $response = $this->run($settings, [
                's3api', 'put-object',
                '--bucket', $settings['bucket'],
                '--key', $objectKey,
                '--body', $path,
                '--content-type', 'application/json',
                '--if-none-match', '*',
                '--object-lock-mode', 'COMPLIANCE',
                '--object-lock-retain-until-date', $retainUntil->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ]);

            $version = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            $versionId = is_array($version) ? ($version['VersionId'] ?? null) : null;
            if (! is_string($versionId) || trim($versionId) === '') {
                throw new RuntimeException('audit_anchor_object_version_missing');
            }

            $head = $this->head($settings, $objectKey, $versionId);
            $headVersion = $head['VersionId'] ?? null;
            $retentionMode = $head['ObjectLockMode'] ?? null;
            $retainUntil = $head['ObjectLockRetainUntilDate'] ?? null;
            if (! is_string($headVersion) || ! hash_equals($versionId, $headVersion)
                || ! is_string($retentionMode)
                || ! is_string($retainUntil)) {
                throw new RuntimeException('audit_anchor_object_create_metadata_incomplete');
            }

            return new AuditCheckpointObjectVersion(
                '',
                $versionId,
                $retentionMode,
                new DateTimeImmutable($retainUntil),
            );
        } finally {
            $this->removeTemporaryFile($path);
        }
    }

    public function findImmutableObject(string $objectKey): ?AuditCheckpointObjectVersion
    {
        $settings = $this->settings();
        $this->assertObjectKey($objectKey);

        try {
            $head = $this->head($settings, $objectKey);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'audit_anchor_object_not_found') {
                return null;
            }

            throw $exception;
        }

        $versionId = $head['VersionId'] ?? null;
        $retentionMode = $head['ObjectLockMode'] ?? null;
        $retainUntil = $head['ObjectLockRetainUntilDate'] ?? null;
        if (! is_string($versionId) || $versionId === ''
            || ! is_string($retentionMode)
            || ! is_string($retainUntil)) {
            throw new RuntimeException('audit_anchor_object_metadata_incomplete');
        }

        return new AuditCheckpointObjectVersion(
            '',
            $versionId,
            $retentionMode,
            new DateTimeImmutable($retainUntil),
        );
    }

    public function readImmutableVersion(string $objectKey, string $versionId): AuditCheckpointObjectVersion
    {
        $settings = $this->settings();
        $this->assertObjectKey($objectKey);
        if (trim($versionId) === '') {
            throw new RuntimeException('audit_anchor_object_version_missing');
        }

        $path = $this->temporaryFile('');
        try {
            $response = $this->run($settings, [
                's3api', 'get-object',
                '--bucket', $settings['bucket'],
                '--key', $objectKey,
                '--version-id', $versionId,
                $path,
            ]);
            $download = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            $downloadVersion = is_array($download) ? ($download['VersionId'] ?? null) : null;
            if (! is_string($downloadVersion) || ! hash_equals($versionId, $downloadVersion)) {
                throw new RuntimeException('audit_anchor_object_version_mismatch');
            }

            $head = $this->head($settings, $objectKey, $versionId);
            $headVersion = $head['VersionId'] ?? null;
            $retentionMode = $head['ObjectLockMode'] ?? null;
            $retainUntil = $head['ObjectLockRetainUntilDate'] ?? null;
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('audit_anchor_object_readback_incomplete');
            }

            try {
                $contents = fread($handle, self::MAX_CHECKPOINT_BYTES + 1);
            } finally {
                fclose($handle);
            }

            if (! is_string($headVersion) || ! hash_equals($versionId, $headVersion)
                || ! is_string($retentionMode)
                || ! is_string($retainUntil)
                || ! is_string($contents)) {
                throw new RuntimeException('audit_anchor_object_readback_incomplete');
            }
            if (strlen($contents) > self::MAX_CHECKPOINT_BYTES) {
                throw new RuntimeException('audit_anchor_object_readback_oversized');
            }

            return new AuditCheckpointObjectVersion(
                $contents,
                $versionId,
                $retentionMode,
                new DateTimeImmutable($retainUntil),
            );
        } finally {
            $this->removeTemporaryFile($path);
        }
    }

    /**
     * @return array{bucket: string, endpoint: string, region: string, aws_binary: string, access_key: string, secret_key: string}
     */
    private function settings(): array
    {
        $settings = [
            'bucket' => config('audit.anchor.storage.bucket'),
            'endpoint' => config('audit.anchor.storage.endpoint'),
            'region' => config('audit.anchor.storage.region'),
            'aws_binary' => config('audit.anchor.storage.aws_cli_binary'),
            'access_key' => config('audit.anchor.storage.access_key'),
            'secret_key' => config('audit.anchor.storage.secret_key'),
        ];

        foreach ($settings as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException('audit_anchor_storage_'.$name.'_not_configured');
            }
        }

        if (preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/i', $settings['bucket']) !== 1) {
            throw new RuntimeException('audit_anchor_storage_bucket_invalid');
        }

        $endpointParts = parse_url($settings['endpoint']);
        if (filter_var($settings['endpoint'], FILTER_VALIDATE_URL) === false
            || ! is_array($endpointParts)
            || strtolower((string) ($endpointParts['scheme'] ?? '')) !== 'https'
            || ! is_string($endpointParts['host'] ?? null)
            || isset($endpointParts['user'])
            || isset($endpointParts['pass'])
            || isset($endpointParts['query'])
            || isset($endpointParts['fragment'])) {
            throw new RuntimeException('audit_anchor_storage_endpoint_invalid');
        }

        $backupBucket = config('filesystems.disks.s3.bucket');
        if (is_string($backupBucket) && $backupBucket !== ''
            && hash_equals($backupBucket, $settings['bucket'])) {
            throw new RuntimeException('audit_anchor_storage_bucket_must_be_dedicated');
        }

        return $settings;
    }

    /**
     * @param  array{bucket: string, endpoint: string, region: string, aws_binary: string, access_key: string, secret_key: string}  $settings
     * @return array<string, mixed>
     */
    private function head(array $settings, string $objectKey, ?string $versionId = null): array
    {
        $arguments = ['s3api', 'head-object', '--bucket', $settings['bucket'], '--key', $objectKey];
        if ($versionId !== null) {
            array_push($arguments, '--version-id', $versionId);
        }

        try {
            $response = json_decode($this->run($settings, $arguments), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($response)) {
                throw new RuntimeException('audit_anchor_storage_invalid_response');
            }

            return $response;
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'NoSuchKey')
                || str_contains($exception->getMessage(), 'Not Found')
                || str_contains($exception->getMessage(), '404')) {
                throw new RuntimeException('audit_anchor_object_not_found', 0, $exception);
            }

            throw $exception;
        }
    }

    /**
     * @param  array{bucket: string, endpoint: string, region: string, aws_binary: string, access_key: string, secret_key: string}  $settings
     * @param  list<string>  $arguments
     */
    private function run(array $settings, array $arguments): string
    {
        $command = [
            $settings['aws_binary'],
            ...$arguments,
            '--endpoint-url', $settings['endpoint'],
            '--region', $settings['region'],
            '--output', 'json',
        ];
        $process = new Process($command, base_path(), [
            'AWS_ACCESS_KEY_ID' => $settings['access_key'],
            'AWS_SECRET_ACCESS_KEY' => $settings['secret_key'],
            'AWS_DEFAULT_REGION' => $settings['region'],
            'AWS_EC2_METADATA_DISABLED' => 'true',
            'AWS_PAGER' => '',
        ], null, 180);

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new RuntimeException('audit_anchor_storage_process_failed', 0, $exception);
        }

        if (! $process->isSuccessful()) {
            $diagnostic = $process->getErrorOutput().' '.$process->getOutput();
            if (preg_match('/NoSuchKey|Not Found|404/i', $diagnostic) === 1) {
                throw new RuntimeException('audit_anchor_object_not_found');
            }

            // Never expose CLI diagnostics; they can include endpoint or credential context.
            throw new RuntimeException('audit_anchor_storage_operation_failed_'.$process->getExitCode());
        }

        return $process->getOutput();
    }

    private function assertObjectKey(string $objectKey): void
    {
        if ($objectKey === '' || str_starts_with($objectKey, '/') || str_contains($objectKey, '..')
            || preg_match('/\A[a-zA-Z0-9!_.*\'()-\/]+\z/', $objectKey) !== 1) {
            throw new RuntimeException('audit_anchor_object_key_invalid');
        }
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rb-audit-anchor-');
        if (! is_string($path)) {
            throw new RuntimeException('audit_anchor_temporary_file_failed');
        }

        @chmod($path, 0600);
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            $this->removeTemporaryFile($path);
            throw new RuntimeException('audit_anchor_temporary_file_write_failed');
        }

        return $path;
    }

    private function removeTemporaryFile(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
