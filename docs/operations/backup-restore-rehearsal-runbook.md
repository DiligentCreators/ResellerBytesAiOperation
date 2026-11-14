# Backup and restore rehearsal runbook

Status: procedure prepared; no production backup has been accessed or restored
Scope: isolated PostgreSQL recovery rehearsal for ResellerBytes AI Operations
Owner: Shahrukh / designated database operator

## Approved recovery objectives

- Recovery point objective (RPO): 24 hours maximum data-loss window.
- Recovery time objective (RTO): 4 hours maximum from declared recovery start to validated service readiness.
- The 26-hour no-successful-backup monitoring threshold is an alert threshold, not permission to exceed the 24-hour RPO. Investigate and escalate before the RPO is breached.
- Any production restore requires Shahrukh's explicit approval before the restore begins. This runbook does not grant standing authorization; record approval in the change/recovery evidence. Rehearsals remain isolated and do not require production restore.

## Safety rules

- Rehearse only in an isolated, access-controlled recovery environment. Never restore a production backup over a live database.
- The backup operator, restore operator, and independent audit-anchor verifier should be separate identities where practical.
- Obtain backup access and credentials through the approved secret manager. Never paste passwords, connection strings containing passwords, private keys, or customer data into chat, tickets, shell history, or this repository.
- Use a dedicated restore target with network access restricted to the recovery team. Do not point the restored application at WHMCS, production queues, production mail, or production AI provider credentials.
- Keep the source backup immutable. Record its object/version identifier, checksum, encryption status, creation time, and retention lock evidence.
- Do not run production migrations, enable diagnostic cleanup, or resume application workers as part of this rehearsal.

## Required inputs (operator supplied)

- [ ] Approved isolated recovery host and PostgreSQL instance, with matching or compatible major version.
- [ ] Read-only access to the selected encrypted database backup and its immutable object/version metadata.
- [ ] Decryption access supplied through the approved secret manager or KMS; no key material copied into this repository.
- [ ] The most recent independently stored signed audit checkpoint and the trusted public verification key.
- [ ] A dedicated restore-owner identity and separate application runtime identity.
- [ ] A change/rehearsal record, recovery objective, operator names, and an approved maintenance window (if any production snapshot operation is required).

## 1. Select and verify the backup artifact

1. Select the newest backup that is within the approved recovery point and retention policy.
2. Confirm the object is in the separate security account, protected by the owner-approved Object Lock retention period (backup retention days remain an explicit pending decision), and cannot be overwritten or deleted by the application runtime identity.
3. Record object version ID, storage timestamp, lock mode/retain-until timestamp, encryption/KMS key identifier, and the independent SHA-256 digest.
4. Download to encrypted recovery storage using the approved cloud tooling. Do not use a public temporary directory or an unencrypted workstation disk.
5. Verify the downloaded bytes against the independently recorded digest before attempting restore.

Example local integrity check (the artifact path is operator-controlled and must not be printed with sensitive mount details):

```sh
sha256sum "$BACKUP_FILE"
pg_restore --list "$BACKUP_FILE" >/dev/null
```

A successful archive listing checks archive readability only; it does not prove the backup is restorable or internally consistent.

## 2. Restore into an isolated target

Use a dedicated PostgreSQL service definition and a protected password file (mode `0600`) or approved secret-manager integration. Do not put passwords in command-line arguments.

```sh
export PGSERVICEFILE="$RECOVERY_PGSERVICE_FILE"
export PGPASSFILE="$RECOVERY_PGPASS_FILE"
chmod 600 "$PGPASSFILE"

# RESTORE_PGSERVICE names a service in PGSERVICEFILE; it contains no password.
PGSERVICE="$RECOVERY_ADMIN_SERVICE" createdb "$RESTORE_DATABASE"

PGSERVICE="$RESTORE_PGSERVICE" pg_restore \
  --exit-on-error \
  --no-owner \
  --no-acl \
  --dbname="$RESTORE_DATABASE" \
  "$BACKUP_FILE"
```

Before running, the operator must confirm that the application's `DB_HOST`, `DB_PORT`, and `DB_DATABASE` configuration point only to the isolated recovery PostgreSQL target. The restore command uses that same configured host/port/database and substitutes only the dedicated `RESTORE_DB_USERNAME` and `RESTORE_DB_PASSWORD`; it rejects a backup manifest unless the database name, host, and port all match the configured target exactly. The operator must also confirm that `RECOVERY_ADMIN_SERVICE` and `RESTORE_PGSERVICE` point only to the recovery host. The service file and password file are operator-managed runtime inputs and must not be committed.

If the backup format or restore procedure requires a different invocation, record the exact reviewed command in the rehearsal record with all secrets redacted. Do not improvise against a production endpoint.

## 3. Apply isolated role separation

1. Create recovery-only migration/restore-owner and runtime roles using the PostgreSQL role-separation procedure.
2. Apply the grants and revocations from [PostgreSQL role separation](postgresql-role-separation.md) to the restored schema.
3. Confirm the runtime identity is not a superuser, schema owner, object owner, migration-ledger reader, or member of the migration role.
4. Run the automated runtime-role regression script in CI and perform the documented manual privilege probes against the isolated restore.
5. Configure only recovery-safe application settings. Disable queues, scheduler execution, outbound email, WHMCS API calls, and all external side effects.

## 4. Verify audit integrity and external checkpoint

Run the following from the restored application checkout with the isolated runtime database identity and the trusted, independently obtained verification key configured. The checkpoint verifier enforces an exact environment match, so set the restored application's `APP_ENV` to the environment identifier recorded in the checkpoint while keeping all outbound integrations, queues, scheduler tasks, and AI calls disabled:

```sh
php artisan audit:verify-integrity
php artisan audit:anchor:verify "$CHECKPOINT_FILE"
```

If the checkpoint chain has a recorded previous checkpoint digest, supply it using `--expected-previous=<trusted-prior-checkpoint-sha256>`. Obtain that digest from the independent checkpoint inventory, not from the database being restored.

Record and compare:

- Checkpoint SHA-256 digest and signature-verification result.
- Anchored event sequence and hash.
- Locally verified event sequence and current chain head.
- Unanchored tail count and checkpoint age.
- Any missing event range, mismatch, unsupported key, or verification error.

A restored database may legitimately contain events newer than the last external checkpoint. Such events are an unanchored tail, not proof of corruption; they must be quantified and investigated. A checkpoint that does not verify, a local chain-integrity failure, or a local prefix that disagrees with the signed anchor is a stop condition. Do not rewrite or reseed the chain to make verification pass.

## 5. Application and recovery validation

- [ ] Confirm migration history and schema objects are present; do not run migrations merely to make the restored schema match.
- [ ] Confirm audit append works under the runtime role and audit UPDATE/DELETE/TRUNCATE are rejected.
- [ ] Confirm the WHMCS integration hard-delete guard rejects deletion.
- [ ] Confirm active legal-hold metadata is expired with immutable audit evidence when its diagnostic target reaches 365 days; the retention report does not exclude held records, and no hold preserves diagnostic content beyond the cutoff.
- [ ] Confirm the 365-day diagnostic-retention policy, no-discretionary-exception rule, and indefinite audit-event retention are represented correctly.
- [ ] Confirm diagnostic cleanup remains disabled and no cleanup schedule is active.
- [ ] Confirm AI usage reservations/ledger relationships are consistent; do not issue real provider requests or settle charges during the rehearsal.
- [ ] Confirm the restored dashboard/API can start in a network-isolated, side-effect-disabled mode.
- [ ] Record measured restore duration against the 4-hour RTO, backup age against the 24-hour RPO, validation duration, and any data-loss window. A rehearsal exceeding either objective is a failed objective even if the database starts.
- [ ] Destroy temporary restore credentials and securely dispose of temporary decrypted artifacts according to the approved recovery policy after evidence has been retained.

## 6. Evidence and exit criteria

The rehearsal is successful only when all of the following are recorded and independently reviewed:

- [ ] Backup object/version, checksum, encryption metadata, Object Lock mode, and retain-until date.
- [ ] Restore target identity and PostgreSQL version (no production hostnames or secrets in the evidence package).
- [ ] Restore command/version, start/end timestamps, exit status, and measured RTO.
- [ ] Runtime-role privilege probe results and successful audit append.
- [ ] Audit hash-chain verification result.
- [ ] Signed external checkpoint verification result, anchor sequence/hash, unanchored tail, and checkpoint age.
- [ ] Legal-hold and retention-policy checks.
- [ ] Reviewer identity, findings, exceptions, and explicit pass/fail decision.

Any failed check, unverifiable checkpoint, missing backup object lock, privilege escalation, or unexplained audit-tail gap is a failed rehearsal. Preserve sanitized evidence, stop the recovery exercise, and escalate to the project Owner and independent security reviewer.

## Pending for Shahrukh

- Create and fund the separate cloud/security account and provide approved backup/restore rehearsal access with an isolated recovery PostgreSQL environment.
- Provide secret-manager access for backup decryption and the trusted production public verification key. Never send passwords or private keys through chat.
- Any production restore remains gated by explicit, incident-specific Shahrukh approval; the approved RPO/RTO do not constitute standing restore authorization.

No production backup, password, secret, or private key is requested in chat or stored in this repository.

## 7. Automated Wasabi PostgreSQL backup tooling

The release-readiness branch adds an automated implementation for the PostgreSQL application database. It does not replace the isolated restore, audit-chain, role-separation, or application validation steps above.

Required runtime dependencies are AWS CLI v2, pg_dump, pg_restore, and PHP ext-sodium. Configure through the deployment secret manager only:

- AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION, AWS_ENDPOINT, and AWS_BUCKET for the Wasabi S3-compatible bucket.
- BACKUP_DB_USERNAME and BACKUP_DB_PASSWORD for the dedicated PostgreSQL read-only role used by pg_dump.
- RESTORE_DB_USERNAME and RESTORE_DB_PASSWORD for the dedicated PostgreSQL migration role used by pg_restore.
- BACKUP_ENCRYPTION_KEY as base64-encoded 32-byte random key material. Generate with: php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)), PHP_EOL;"
- BACKUP_OBJECT_LOCK_RETENTION_DAYS as a positive, owner-approved retention period. The application intentionally has no default for this value.
- OPERATIONS_BACKUP_SUCCESS_MARKER_PATH as a private writable marker path consumed by the backup-age metric.

The tool fails closed unless the bucket already has versioning and Object Lock enabled and supports conditional If-None-Match create semantics with provider-returned VersionIds. The current single-PutObject implementation also fails closed above 5 GB; larger databases require a multipart Object Lock adapter before use. It encrypts the pg_dump archive with authenticated libsodium secretstream, conditionally creates each archive and manifest with explicit Object Lock COMPLIANCE retention and SSE-S3 AES256, captures the exact archive VersionId in the manifest, verifies lock mode/retain-until date/size/checksum metadata against that exact version, and downloads that exact version again for a full SHA-256 read-back check. The manifest itself is conditionally created and its returned version is verified. Providers that do not support conditional creation or return a VersionId fail closed. Only then is the backup-success marker atomically updated.

Run a backup manually:

```sh
php artisan operations:backup:create
```

The command emits a verified backup UUID and manifest metadata. The daily scheduler defaults to 00:00 UTC but remains disabled until OPERATIONS_BACKUPS_ENABLED=true. Do not enable it until the Wasabi account/billing, bucket configuration, retention decision, credentials, encryption key, database role credentials, and marker path have all been verified. The separate WHMCS/MariaDB database, uploaded local files, and external provider state are not included in this initial PostgreSQL backup tool.

## 8. Automated restore verification and execution

A restore command without --execute is verification-only. It downloads the manifest and encrypted archive, validates the backup object's COMPLIANCE lock mode, retain-until date, size and checksum metadata, validates SHA-256 checksums, authenticates/decrypts the archive, and runs pg_restore --list without changing the database:

```sh
php artisan operations:backup:restore BACKUP_UUID
```

A non-production rehearsal requires a matching isolated PostgreSQL target and explicit interactive confirmation:

```sh
php artisan operations:backup:restore BACKUP_UUID --execute
```

In production, the command additionally requires --approved-by matching OPERATIONS_OWNER_EMAIL, a non-empty --approval-reference, fresh six-digit TOTP from the dedicated owner account, and the separate interactive confirmation. Before pg_restore, it creates and verifies a fresh pre-restore backup and stores an immutable approval record in the Object-Locked bucket. The restore uses --clean, --if-exists, --exit-on-error, and --single-transaction. If any approval, pre-restore backup, Object Lock, checksum, or restore step fails, stop and escalate; do not bypass the owner gate.

No production backup or restore has been executed. RPO remains 24 hours and RTO remains 4 hours.
