# Backup, restore, and rehearsal runbook

## Scope and objectives

- Recovery Point Objective: 24 hours.
- Recovery Time Objective: 4 hours.
- Storage: Wasabi S3-compatible object storage.
- Object protection: S3 Object Lock in COMPLIANCE mode.
- Backup payload: PostgreSQL application database custom-format dump, encrypted client-side with libsodium secretstream and server-side with S3 AES256.
- This initial tool does not back up the separate WHMCS/MariaDB database, uploaded local files, or external provider state. Those require separately scoped backup adapters before production release.

## Required dependencies and configuration

The backup runtime needs the AWS CLI v2, pg_dump, pg_restore, PHP ext-sodium, and a PostgreSQL client compatible with the server major version.

Configure these values only through the deployment secret manager:

- AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY: least-privilege Wasabi credentials.
- BACKUP_DB_USERNAME and BACKUP_DB_PASSWORD: dedicated PostgreSQL read-only role for pg_dump.
- RESTORE_DB_USERNAME and RESTORE_DB_PASSWORD: dedicated PostgreSQL migration role for pg_restore. Do not reuse the application runtime role.
- AWS_DEFAULT_REGION, AWS_ENDPOINT, and AWS_BUCKET: exact Wasabi region, S3 endpoint, and pre-created bucket.
- BACKUP_ENCRYPTION_KEY: base64 encoding of 32 cryptographically random bytes. Generate with: php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)), PHP_EOL;"
- BACKUP_OBJECT_LOCK_RETENTION_DAYS: positive retention period approved by the owner. It is deliberately not defaulted because no backup retention duration has been approved.
- OPERATIONS_BACKUP_SUCCESS_MARKER_PATH: writable local path used by monitoring only after upload and read-back verification succeed.

The bucket must have versioning enabled and Object Lock enabled before the command will write. Each backup and manifest is uploaded with an explicit COMPLIANCE retention date and SSE-S3 AES256. The tool verifies the lock mode, retain-until date, size, checksum metadata, and a full object read-back checksum before updating the success marker.

Do not enable OPERATIONS_BACKUPS_ENABLED until the Wasabi account/billing, bucket configuration, credentials, encryption key, retention period, and marker path have been verified. The schedule defaults to 00:00 UTC daily and is disabled by default. Daily cadence is aligned to the 24-hour RPO; confirm operational capacity and the intended schedule before enabling.

## Create and verify a backup

1. Confirm the configured default database is PostgreSQL and the database credentials have least privilege for a consistent dump.
2. Run php artisan operations:backup:create.
3. Confirm the output status is verified and record the backup UUID, object key, plaintext/encrypted checksums, and retention date.
4. Confirm the success marker modification time advanced only after the upload, S3 Object Lock HEAD checks, and full read-back checksum completed.
5. Check Wasabi inventory/versioning and retain the immutable manifest alongside the object.

The command writes temporary dump and encrypted files under storage/app/private/operations-backups with restrictive directory permissions and removes them in a finally block. The archive key is never written to disk or logged.

## Non-production restore rehearsal

1. Provision an isolated PostgreSQL instance matching the source major version and required extensions. Do not point rehearsal commands at production.
2. Configure the application to use the isolated database and the same read-only backup bucket credentials.
3. Run php artisan operations:backup:restore BACKUP_UUID to download, authenticate, decrypt, checksum, and inspect the archive. Without --execute, this is verification-only and makes no database changes.
4. Run the same command with --execute and explicitly confirm the destructive restore.
5. Verify schema, row counts, application login, core API paths, audit-chain verification, retention jobs, and WHMCS integration connectivity.
6. Record actual elapsed restore time, data-loss window, validation results, and any remediation. The target is RTO <= 4 hours and RPO <= 24 hours.
7. Preserve the rehearsal evidence and do not use production credentials or production databases in this exercise.

## Production restore approval

A production restore is destructive and must be approved by Shahrukh:

- OPERATIONS_OWNER_EMAIL must be configured to the dedicated owner email.
- The operator must supply --approved-by matching that exact owner email and a non-empty --approval-reference (incident/change ticket).
- The owner account must have a verified email and confirmed TOTP. The command prompts for a fresh six-digit TOTP and verifies it against the owner account.
- The command requires --execute and a separate interactive confirmation. Without --execute it performs verification only.
- Before restoring, the tool creates and verifies a fresh pre-restore backup, then stores an immutable approval record in the Object-Locked bucket.
- Only then does pg_restore run with --clean, --if-exists, --exit-on-error, and --single-transaction.

If any approval, backup, Object Lock, checksum, pre-restore snapshot, or restore step fails, stop and escalate. Never bypass the owner approval gate. After restoration, keep traffic closed until application health, audit integrity, data consistency, and integration checks pass.

## Current release gates

Wasabi account and billing, production credentials, approved backup retention days, secret manager access, and isolated rehearsal access remain external owner-controlled prerequisites. No production backup or restore has been executed by this implementation.
