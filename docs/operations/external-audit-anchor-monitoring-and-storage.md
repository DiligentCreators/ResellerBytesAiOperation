# External audit-anchor verification, monitoring, and immutable storage

Status: independent signature verifier, local freshness/tail monitor, provider-neutral publisher orchestration, and storage contract tests implemented; provider adapter and production alert delivery are not provisioned.

## Verification boundaries

Two checks serve different trust boundaries:

- `php tools/verify-audit-anchor.php /secure/path/checkpoint.json [EXPECTED_PREVIOUS_SHA256] [--max-age-minutes=60]` verifies the checkpoint envelope, Ed25519 signature, expected environment, strict canonical UTC timestamp, and checkpoint digest using only `AUDIT_ANCHOR_PUBLIC_KEY`. It always rejects timestamps more than five minutes in the future. Set `AUDIT_ANCHOR_EXPECTED_ENVIRONMENT` to the exact signed environment. Optionally pass the expected prior checkpoint SHA-256 to enforce continuity and/or `--max-age-minutes=N` to enforce freshness independently of the application database. Historical signature verification can omit the max-age option; production monitoring should set an approved freshness threshold.
- `php artisan audit:anchor:monitor /secure/path/checkpoint.json --max-age-minutes=60 --max-unanchored-tail=0` verifies the signature and database chain prefix, then fails if the checkpoint is stale, more than five minutes in the future, or the local chain has more than the permitted unanchored events. It requires the application database and configured public key.

The standalone verifier intentionally does not claim to validate the database chain prefix. It now strictly validates calendar timestamps, rejects timestamps more than five minutes in the future, and optionally enforces a maximum age without database access. Run it from a separate security account or verifier host with only the public key. Run the application monitor from the application trust boundary. A successful standalone signature/freshness check is not proof that the checkpoint matches the current database.

The standalone verifier and application monitor return non-zero exit codes for invalid signatures, environment or predecessor mismatch, malformed timestamps, future timestamps, and (when configured) stale checkpoints. The application monitor additionally fails for missing/unreadable checkpoints, database-chain mismatch, invalid thresholds, or an excessive unanchored tail. Its output contains checkpoint digest and sequence metrics, not event payloads.

## Monitoring and alert routing

- [ ] Select and approve a checkpoint cadence and maximum acceptable unanchored window.
- [ ] Run the monitor from an external scheduler/monitoring agent at a cadence shorter than the approved freshness threshold.
- [ ] Alert on every non-zero exit, missing checkpoint, stale checkpoint, signature failure, chain mismatch, or unanchored-tail threshold breach.
- [ ] Send alerts to the approved security/on-call route; include environment, command exit status, checkpoint digest when available, and UTC observation time only.
- [ ] Do not include private keys, checkpoint signing secrets, audit event metadata, customer data, database connection strings, or raw exception traces in notifications.
- [ ] Treat an alert as an incident until the last known-good external checkpoint and local chain prefix are independently verified.
- [ ] Preserve alert delivery receipts and incident disposition in the external audit evidence store.

The repository does not configure a scheduler or alert destination. Do not silently enable a production schedule before the owner approves the checkpoint cadence, monitoring identity, and alert route.

## Immutable object upload contract

No cloud provider, bucket, account, or credential is configured by this repository. The application now has an `AuditCheckpointObjectStore` contract and `AuditCheckpointPublisher` orchestration. The publisher first verifies the signed checkpoint against the local audit-chain prefix, derives a deterministic environment/sequence/digest key, requests conditional immutable creation, then reads back the exact returned version and requires matching bytes, version ID, COMPLIANCE mode, and at least 365 days of retention. Duplicate keys, missing/mismatched versions, checksum mismatch, insufficient retention, and unresolved ambiguous create outcomes fail closed. If create returns an error, the publisher never retries the write: it performs a deterministic-key lookup, then reads back the exact returned version and validates bytes and COMPLIANCE retention before allowing an idempotent success. An absent object or failed lookup/read-back remains a failure. No provider adapter is wired or configured; do not describe a local export or fake-store test as an immutable external anchor.

When the owner provisions the independent security account and selects the storage provider, implement the upload adapter with these mandatory properties:

1. Derive a deterministic object key from the environment and checkpoint sequence/digest; never include customer or event data in the key.
2. Use a conditional create operation (for S3-compatible storage, `If-None-Match: *`) so an existing key cannot be overwritten. A conflict is not success.
3. Upload the exact signed bytes and capture the provider's immutable object version identifier and checksum.
4. Require Object Lock in COMPLIANCE mode for the approved 365-day retention period. Governance mode or a mutable bucket policy is not equivalent.
5. Read the object back by exact version ID, compare its SHA-256 digest to the locally exported bytes, and independently read back retention mode and retain-until timestamp.
6. Record the object key, version ID, checkpoint digest, chain sequence, environment, retention mode, retain-until UTC timestamp, and read-back result in a separate evidence record.
7. Never report success until checksum, version, and retention read-back all match the expected values.
8. If the upload response is ambiguous, do not retry the write. Query the deterministic key, resolve its exact version ID, read that exact version back, and reconcile the exact bytes and retention metadata. An idempotent success is allowed only when every check passes; raise an alert if lookup or read-back remains ambiguous.
9. A duplicate object key, missing version ID, retention mismatch, failed read-back, or unsupported conditional-create capability must fail closed.

The provider-neutral publisher has automated fake-store tests for duplicate-key rejection, missing version ID, version mismatch, checksum mismatch, retention-mode mismatch, too-short retention, ambiguous create reconciliation, and unresolved create outcomes. The real provider adapter must additionally test concurrent create races, failed network read-back, actual provider version IDs, and Object Lock metadata against disposable non-production storage or a provider emulator. Production Object Lock configuration and actual retention read-back remain owner-controlled release gates.

## Recovery and chain continuity

- Keep the prior checkpoint SHA-256 outside the application database and pass it as the expected predecessor when independently verifying the next checkpoint.
- Preserve all historical checkpoint bytes and public-key fingerprints. Never replace an existing immutable object.
- If a checkpoint is missing or continuity fails, stop checkpoint advancement, preserve evidence, and investigate. Do not create a new chain root to conceal a gap.
- After restoring a database backup, verify the restored chain against the latest externally retained checkpoint and document any legitimate unanchored tail created after that checkpoint.

## Shahrukh ke liye pending

- Select and provision the independent cloud/security account, storage provider, bucket, and 365-day Object Lock COMPLIANCE retention.
- Approve the signer and read-only verifier identities, public-key distribution, secret-manager access, and monitoring/alert destination.
- Provide isolated non-production storage access for adapter integration tests and read-back rehearsal.
- Approve checkpoint cadence, maximum unanchored window, and operational alert thresholds.

No cloud resource, production signing key, credential, or production alert route was created or accessed by this implementation.
