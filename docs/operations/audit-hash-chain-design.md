# Audit hash-chain design

Status: implementation in progress on the release-readiness branch
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
- Metadata objects are recursively key-sorted; list order is preserved. JSON uses stable escaping, no insignificant whitespace, and rejects invalid UTF-8 / non-JSON values. Timestamps are normalized to UTC with fixed microsecond precision. Nullable fields remain explicit JSON nulls.
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
