<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Operations\Backups\BackupArchiveManager;
use Illuminate\Console\Command;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Throwable;

final class RestoreOperationalBackup extends Command
{
    protected $signature = 'operations:backup:restore
        {backupId : UUID of a verified backup manifest}
        {--execute : Perform the destructive restore after all checks}
        {--approved-by= : Dedicated owner email authorizing a production restore}
        {--approval-reference= : Incident/change ticket or signed approval reference}';

    protected $description = 'Verify a backup and restore PostgreSQL only with explicit production-owner approval';

    public function handle(
        BackupArchiveManager $backups,
        TwoFactorAuthenticationProvider $twoFactorProvider,
    ): int {
        $backupId = (string) $this->argument('backupId');
        $artifact = null;

        try {
            $artifact = $backups->downloadAndVerify($backupId);
            $manifest = $artifact['manifest'];

            $this->info('Backup archive, encryption authentication, and SHA-256 checksums verified.');
            $this->line('Backup ID: '.$backupId);
            $this->line('Created at: '.(string) ($manifest['created_at'] ?? 'unknown'));
            $this->line('Database: '.(string) ($manifest['database_name'] ?? 'unknown'));

            if (! $this->option('execute')) {
                $this->comment('Verification only. Rerun with --execute to restore; no database changes were made.');

                return self::SUCCESS;
            }

            $approvedBy = trim((string) $this->option('approved-by'));
            $approvalReference = trim((string) $this->option('approval-reference'));
            $ownerEmail = config('operations.owner_email');
            $production = app()->isProduction();

            if ($production) {
                if (! is_string($ownerEmail)
                    || $ownerEmail === ''
                    || mb_strtolower($approvedBy) !== mb_strtolower($ownerEmail)
                    || $approvalReference === ''
                    || mb_strlen($approvalReference) > 255) {
                    $this->error('Production restore requires --approved-by matching OPERATIONS_OWNER_EMAIL and a valid --approval-reference.');

                    return self::FAILURE;
                }

            }

            if (! $this->confirm('This will replace data in the configured PostgreSQL database. Continue?', false)) {
                $this->comment('Restore cancelled. No database changes were made.');

                return self::SUCCESS;
            }

            // Create the safety snapshot before requesting a fresh TOTP immediately
            // ahead of the destructive restore and immutable approval record.
            $preRestore = $backups->create();

            $backups->recordRestoreApproval([
                'schema_version' => 1,
                'action' => 'postgresql_restore',
                'backup_id' => $backupId,
                'pre_restore_backup_id' => $preRestore['backup_id'],
                'approved_by' => $production ? $ownerEmail : ($approvedBy !== '' ? $approvedBy : 'non-production-operator'),
                'approval_reference' => $production ? $approvalReference : 'non-production-rehearsal',
                'approved_at' => now()->toImmutable()->utc()->toIso8601String(),
                'environment' => app()->environment(),
            ]);

            // Verify the second factor only after remote approval evidence is durable,
            // immediately before the destructive database operation.
            if ($production && ! $this->verifyOwnerTotp((string) $ownerEmail, $twoFactorProvider)) {
                $this->error('Fresh owner TOTP verification failed. The pre-restore backup and approval evidence are preserved; no restore was performed.');

                return self::FAILURE;
            }

            $backups->restore($artifact);

            $this->info('PostgreSQL restore completed. Verify application health and data integrity before reopening traffic.');
            $this->line('Pre-restore backup ID: '.$preRestore['backup_id']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Restore operation failed: '.$exception::class);

            return self::FAILURE;
        } finally {
            if ($artifact !== null) {
                $backups->cleanupArtifact($artifact);
            }
        }
    }

    private function verifyOwnerTotp(
        string $ownerEmail,
        TwoFactorAuthenticationProvider $twoFactorProvider,
    ): bool {
        $owner = User::query()
            ->where('email', $ownerEmail)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('two_factor_confirmed_at')
            ->whereNotNull('two_factor_secret')
            ->first();

        if ($owner === null) {
            return false;
        }

        $code = $this->secret('Enter the current six-digit TOTP code for the production restore owner');
        if (! is_string($code) || preg_match('/^\d{6}$/D', trim($code)) !== 1) {
            return false;
        }

        try {
            $secret = Fortify::currentEncrypter()->decrypt($owner->two_factor_secret);
        } catch (Throwable) {
            return false;
        }

        return $twoFactorProvider->verify($secret, trim($code));
    }
}
