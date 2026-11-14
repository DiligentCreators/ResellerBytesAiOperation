# Backup and restore rehearsal runbook

Status: procedure prepared; no production backup has been accessed or restored
Scope: isolated PostgreSQL recovery rehearsal for ResellerBytes AI Operations
Owner: project Owner / designated database operator

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
2. Confirm the object is in the separate security account, protected by the approved 365-day Object Lock retention, and cannot be overwritten or deleted by the application runtime identity.
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

Before running, the operator must confirm that `RESTORE_DATABASE` is the isolated recovery database and that `RECOVERY_ADMIN_SERVICE` and `RESTORE_PGSERVICE` point only to the recovery host. The service file and password file are operator-managed runtime inputs and must not be committed.

If the backup format or restore procedure requires a different invocation, record the exact reviewed command in the rehearsal record with all secrets redacted. Do not improvise against a production endpoint.

## 3. Apply isolated role separation

1. Create recovery-only migration/restore-owner and runtime roles using the PostgreSQL role-separation procedure.
2. Apply the grants and revocations from [PostgreSQL role separation](postgresql-role-separation.md) to the restored schema.
3. Confirm the runtime identity is not a superuser, schema owner, object owner, migration-ledger reader, or member of the migration role.
4. Run the automated runtime-role regression script in CI and perform the documented manual privilege probes against the isolated restore.
5. Configure only recovery-safe application settings. Disable queues, scheduler execution, outbound email, WHMCS API calls, and all external side effects.

## 4. Verify audit integrity and external checkpoint

Run the following from the restored application checkout with the isolated runtime database identity and the trusted, independently obtained verification key configured:

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
- [ ] Confirm legal holds remain active and diagnostic retention reports preserve held records.
- [ ] Confirm the 365-day diagnostic-retention policy, no-discretionary-exception rule, and indefinite audit-event retention are represented correctly.
- [ ] Confirm diagnostic cleanup remains disabled and no cleanup schedule is active.
- [ ] Confirm AI usage reservations/ledger relationships are consistent; do not issue real provider requests or settle charges during the rehearsal.
- [ ] Confirm the restored dashboard/API can start in a network-isolated, side-effect-disabled mode.
- [ ] Record measured restore duration, backup age/recovery point, validation duration, and any data-loss window.
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

## Shahrukh ke liye pending

- Provision the separate cloud/security account and backup bucket with the approved 365-day Object Lock retention; provide object-version and retention read-back evidence.
- Arrange approved backup read access and an isolated recovery PostgreSQL environment.
- Provision independent Ed25519 signing/anchor key custody and supply the trusted public verification key through the approved secure channel.
- Approve the named recovery operators, secret-manager access policy, and rehearsal window.

No production backup, password, secret, or private key is requested in chat or stored in this repository.
