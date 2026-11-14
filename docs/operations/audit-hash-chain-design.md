# Audit hash-chain design

Status: implemented and CI-verified on the release-readiness branch; production rollout remains pending
Reviewed: 2026-11-13
Scope: existing append-only `audit_events` stream only

## Objective

Add tamper-evident linkage between audit events so that a verifier can detect edits, deletions, and reordering in the retained event history. This is an integrity-detection control, not a claim that a database administrator cannot rewrite the entire chain.

The existing database triggers remain in place. The hash chain is an additional control and does not replace least-privilege database roles, independent backups, or external anchoring.

## Chosen construction

Use an unkeyed SHA-256 hash chain in version 1.

- Each event receives a monotonically increasing `chain_sequence`, a `previous_hash`, a `event_hash`, and a `hash_version`.
- The first event points to a fixed, documented genesis value: 64 zeroes.
- The event digest is SHA-256 over a domain-separated, canonical UTF-8 representation of the immutable event fields and the previous digest.
- The canonical payload includes, in fixed key order: `chain_version`, `chain_sequence`, `previous_hash`, `actor_type`, `actor_id`, `event_type`, `subject_type`, `subject_id`, `metadata`, `ip_address`, and `occurred_at`.
- Metadata objects are recursively key-sorted; list order is preserved. JSON uses stable escaping, no insignificant whitespace, and rejects invalid UTF-8 / non-JSON values. Timestamps are normalized to UTC with fixed second precision. Nullable fields remain explicit JSON nulls.
- The domain prefix is a fixed versioned string (for example, `resellerbytes.audit-event.v1\n`) prepended to the canonical payload before hashing. Changing canonicalization requires a new hash version; do not silently reinterpret old rows.

SHA-256 is selected rather than HMAC for this first chain because verification must remain possible without distributing a signing secret to every verifier. Consequently, a privileged actor able to rewrite the database can recompute a suffix; independent external anchoring is required to make such rewrites detectable outside the database.

## Concurrency and atomicity

Maintain a singleton `audit_chain_heads` row containing the current sequence and digest.

Every append must execute through one `AuditEventWriter` inside a database transaction:

1. Lock the singleton chain-head row with `SELECT ... FOR UPDATE` (SQLite uses its transaction/write-lock semantics).
2. Allocate the next sequence and use the current head digest as `previous_hash`.
3. Canonicalize the event payload and calculate its SHA-256 digest.
4. Insert the audit event including all chain columns.
5. Advance the chain-head row to the inserted sequence and digest.
6. Commit both writes atomically.

A failed event insert or head update must roll back the entire transaction. Concurrent writers must serialize on the head row; a unique constraint on `chain_sequence` is a second line of defense. Do not calculate the next sequence with `MAX(id) + 1`, timestamps, or an unlocked read.

All application audit writes must be routed through the writer. Direct `AuditEvent::create`, query-builder inserts, bulk inserts, and imports that omit the writer are prohibited. Existing transactional callers must continue to write their business change and audit event in the same outer transaction where the audit record is part of that business operation.

## Migration and historical backfill

Use a forward-only migration:

1. Add nullable chain columns and a unique index on `chain_sequence`.
2. Create and initialize the singleton chain-head row.
3. Backfill existing events in ascending immutable primary-key order, in deterministic batches, assigning sequences from 1 and chaining each row to the previous digest.
4. Verify the backfilled row count, contiguous sequence range, and final digest before making chain columns non-null.
5. Add a unique constraint for the singleton head identity and enforce non-null chain fields for new events.
6. Keep the existing append-only triggers effective. The migration owner may need a narrowly scoped, documented migration window to backfill existing rows; application runtime credentials must never receive UPDATE or DELETE privileges.

Do not run this migration against production as part of development work. Before production rollout, rehearse it on a production-like copy, measure lock duration and write downtime, and take a verified backup.

## Integrity verifier

Provide `php artisan audit:verify-integrity` as a read-only command.

The verifier must:

- Run inside a database transaction and lock the singleton chain-head row before streaming. This serializes verification with `AuditEventWriter`, preventing a concurrent append from making the cursor's event set disagree with a newer stored head. Keep the command non-mutating; the lock is coordination only.
- Stream events in ascending `chain_sequence` order without loading the full history into memory.
- Require the first previous digest to equal the genesis value.
- Require sequences to be contiguous and unique.
- Recompute each event digest using the stored hash version and canonicalization rules.
- Require every event's `previous_hash` to equal the prior verified event's digest.
- Compare the final verified sequence and digest with `audit_chain_heads`.
- Return a non-zero exit code on any mismatch and report the first failing sequence / event ID without exposing metadata or secrets.
- Report the verified count and final digest on success. It must not repair, rewrite, or auto-heal the chain.

## Required tests

- Canonicalization is deterministic across associative-key insertion order and preserves list order.
- Nulls, Unicode, escaped characters, IP addresses, and microsecond timestamps produce stable digests.
- Appends create a valid genesis link and valid subsequent links.
- Concurrent independent writers produce unique contiguous sequences and a valid final head.
- Failure between event insertion and head advancement rolls back both.
- Existing events backfill deterministically and can be verified immediately after migration.
- Tampering with event fields or metadata, deleting a row, changing sequence order, breaking a previous link, or changing the chain head causes verification failure.
- The verifier succeeds on an empty chain and on a valid chain.
- All production audit-write call sites use the writer; direct model/query-builder audit inserts are rejected by tests or code-level safeguards.
- PostgreSQL concurrency behavior is exercised in CI; SQLite tests cover the portable feature path.

## Threat model and remaining controls

The chain detects accidental or unauthorized row edits, deletions, and reordering when the verifier has a trusted expected head. It does not, by itself, stop a database owner/superuser from rewriting rows and recomputing the chain, nor does it stop a compromised application process from appending false events.

A later release gate must periodically export the verified sequence and head digest to an independently controlled system (for example, object storage with retention lock or a separate audit account) and verify that anchor during integrity checks. The anchor must be outside the application database's administrative boundary. Define retention, key custody, alerting, and restore procedures before enabling production anchoring.

No production migration, external anchor, merge, or deployment is authorized by this design document.

## External anchor architecture and release gate

The production anchor is a separate trust boundary, not another table or disk on the Laravel VPS.

### Selected deployment pattern

- Store immutable checkpoint objects in a dedicated S3-compatible bucket with Object Lock enabled at bucket creation. Prefer AWS S3 Object Lock in Compliance mode for the production anchor; do not treat ordinary versioning, a lifecycle policy, or an application-level "read-only" flag as WORM retention.
- Place the bucket in a separate cloud account / security administration boundary from the Laravel Forge VPS and its PostgreSQL host. The application role may create new objects but must not overwrite, delete, shorten retention, change bucket policy, or administer Object Lock. A separate audit-reader role may list and read objects. Bucket administration and retention changes remain with a separately controlled security owner.
- Enable bucket versioning and default retention. Set the retention period through the approved compliance policy (initial recommendation: 365 days, subject to legal and contractual retention requirements). Compliance mode prevents even the bucket owner from deleting a protected object before expiry; validate provider semantics before selecting an S3-compatible alternative.
- Export a checkpoint at least every five minutes and immediately after a high-risk administrative event. A checkpoint contains schema/version, environment identifier, UTC creation time, highest verified sequence, chain head SHA-256, and previous external checkpoint object digest. Do not include event metadata or customer data.
- Sign the exact canonical checkpoint bytes with a dedicated Ed25519 audit-anchor key. This key must be distinct from the WHMCS addon package signing key, application APP_KEY, API HMAC secrets, and any database-stored value. Keep the private key in a secret manager with narrowly scoped access; the public key/fingerprint is provisioned independently to the verifier. Rotate only through a versioned key transition that itself is externally anchored.
- Use immutable, content-addressed object names, for example `audit-anchors/{environment}/{YYYY}/{MM}/{DD}/{sequence}-{checkpoint-sha256}.json`. Upload with conditional create semantics; an existing key with different bytes is a hard failure. Preserve the exact signed bytes.
- The verifier must compare the live database chain head to the latest externally retained checkpoint and verify the detached signature against the independently provisioned public key. A database-only rewrite that changes any event at or before the latest checkpoint must fail verification. Events appended after the latest checkpoint are an explicitly unanchored tail and must be reported as such, not silently treated as anchored.
- Alert if a scheduled checkpoint is late by more than two intervals, upload fails, signature verification fails, the external sequence regresses, a checkpoint is missing, or the database head does not match the latest anchor. Alert delivery must not depend solely on the application database or its local logs.

### Operational controls

1. Provision the bucket, Object Lock, versioning, retention, cross-account IAM roles, independent signing key, and public-key distribution outside the application deployment.
2. Run a dry-run against a non-production bucket and verify create-only behavior, retention lock, read-back, signature verification, duplicate upload rejection, and restore from an independent environment.
3. Configure the application with only the write role and signing capability required to create checkpoints. Do not grant bucket administration or object deletion.
4. Configure an independent verifier with read-only bucket access and the trusted public key. Run it from a separate security boundary at least daily.
5. Document incident response for a missed anchor, key compromise, provider outage, restore to an older database backup, and key rotation. After restoring a database, compare its head against the newest external anchor before accepting writes.
6. Retain the external objects for the approved retention period and test retrieval before expiry. Do not automatically prune the source audit stream merely because an anchor exists.

### Current implementation boundary

The repository currently implements the local SHA-256 chain and read-only chain verifier only. It does **not** yet upload external checkpoints, sign them with an independent key, verify remote anchors, or alert on missed checkpoints. These are explicit implementation and production-provisioning gates. No bucket, cloud credentials, signing key, retention period approval, or independent verifier credentials have been supplied, so no external storage integration is being falsely marked active.

### Acceptance criteria

- A database administrator cannot delete or replace a retained external checkpoint before its retention expiry.
- A separately hosted verifier can validate the signature and chain head without database write access or access to the signing private key.
- Rewriting the local database chain through the latest anchored sequence causes verification failure.
- The verifier clearly reports the unanchored tail and maximum anchor age.
- Upload outage, key mismatch, sequence regression, missing object, and restore-to-old-backup scenarios have automated tests and actionable alerts.

## Legal-hold governance decision — 2026-11-14

- The project Owner is the approval authority for both placing and releasing a legal hold.
- Each placement/release must append an immutable audit event recording the owner identity, UTC timestamp, affected record/scope, action, reason, controlled evidence/case reference, and resulting state. Sensitive case documents and customer payloads must not be copied into audit metadata.
- Future retention cleanup must exclude held records and re-check hold state inside the deletion transaction. If hold state cannot be read or the audit event cannot be committed, cleanup must fail closed and delete nothing.
- A legal hold overrides ordinary retention expiry, including the 365-day external-backup retention setting, until the Owner explicitly approves and audits release.
- Governance is approved; the legal-hold schema/API/command and cleanup enforcement are **not yet implemented**. No automated diagnostic or audit-history deletion is permitted until implementation and negative-path tests pass.

## Signed external checkpoint tooling

The branch now includes an Ed25519 checkpoint envelope generator and verifier. These commands are usable without cloud credentials, but they do not upload or lock objects in external storage.

### Key configuration

- `AUDIT_ANCHOR_PRIVATE_KEY`: base64-encoded 64-byte Ed25519 secret key.
- `AUDIT_ANCHOR_PUBLIC_KEY`: base64-encoded 32-byte Ed25519 public key.
- Generate the pair with libsodium outside the application environment. Store the private key in a dedicated secret manager; provision the public key independently to the verifier.
- These keys must be distinct from `WHMCS_ADDON_SIGNING_SECRET_KEY`, `APP_KEY`, and all WHMCS HMAC secrets. The application must not generate or silently fall back to another key.
- The example environment leaves both values empty. Export and verification fail closed until the appropriate key is provisioned.

### Export and upload

First checkpoint:

```sh
php artisan audit:anchor:export > audit-checkpoint.json
sha256sum audit-checkpoint.json
```

Subsequent checkpoint (supply the exact SHA-256 digest of the previously retained checkpoint):

```sh
php artisan audit:anchor:export --previous=PREVIOUS_CHECKPOINT_SHA256 > audit-checkpoint.json
sha256sum audit-checkpoint.json
```

The export command locks the chain-head row while it verifies the full local chain and signs the checkpoint, preventing normal writer appends from racing the exported head. This can briefly serialize audit appends; run it with an appropriate operational cadence and measure lock duration on production-sized history before scheduling it. The output is a signed JSON envelope; it contains only environment, timestamp, sequence, head digest, and prior checkpoint digest—not event metadata.

Upload the exact output bytes to the independently administered Object Lock bucket using the security-controlled uploader role. Do not rewrite, reformat, or re-serialize the JSON after signing. Record the returned object version and the SHA-256 digest in the external evidence register.

### Verification

Download the retained object without changing its bytes, then run:

```sh
php artisan audit:anchor:verify /secure/path/audit-checkpoint.json
```

For a checkpoint after the first, also supply the previously verified digest:

```sh
php artisan audit:anchor:verify /secure/path/audit-checkpoint.json --expected-previous=PREVIOUS_CHECKPOINT_SHA256
```

The verifier validates the detached signature, environment, checkpoint structure, complete local chain, and database prefix through the anchored sequence. It reports the local unanchored tail explicitly. A database restored behind the checkpoint or a rewritten prefix fails verification.

### Current boundary

- The signing and verification code is implemented and covered by feature tests, including tampered payload rejection, predecessor-digest mismatch, and unanchored-tail reporting.
- The export command emits a signed artifact only. It does not automatically upload to S3, set Object Lock retention, create cloud IAM roles, or send alerts.
- **Shahrukh ke liye:** provision the separate cloud account/bucket, choose and approve Object Lock retention, generate/store the independent Ed25519 keypair, and provide the non-secret bucket/role configuration. Never commit the private key.
- Before production scheduling, add an upload adapter with conditional-create semantics, remote read-back verification, immutable version capture, missed-checkpoint alerts, and a separate read-only verifier job. These remain release gates.
