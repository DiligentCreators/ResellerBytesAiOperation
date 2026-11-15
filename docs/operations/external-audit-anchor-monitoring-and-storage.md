# External audit-anchor verification, monitoring, and immutable storage

Status: Ed25519 signer/verifier, local freshness/tail monitor, redacted JSON
alert
evidence, provider-neutral publisher, S3-compatible conditional Object Lock
adapter,
manual publish command, and fake-store tests implemented. Production storage and
credentials remain unprovisioned.

## Verification boundaries

Two checks serve different trust boundaries:

- `php tools/verify-audit-anchor.php /secure/path/checkpoint.json
[EXPECTED_PREVIOUS_SHA256] [--max-age-minutes=60]` verifies the checkpoint
envelope,
  Ed25519 signature, expected environment, strict canonical UTC timestamp, and
checkpoint digest using only `AUDIT_ANCHOR_PUBLIC_KEY`. It always rejects timestamps
more than five minutes in the future. Set `AUDIT_ANCHOR_EXPECTED_ENVIRONMENT` to the
exact signed environment. Optionally pass the expected prior checkpoint SHA-256
to
enforce continuity and/or `--max-age-minutes=N` to enforce freshness independently of
the application database. Historical signature verification can omit the max-age
  option; production monitoring should set an approved freshness threshold.
-  `php artisan audit:anchor:monitor /secure/path/checkpoint.json
  --max-age-minutes=60
--max-unanchored-tail=0` verifies the signature and database chain prefix, then
fails
if the checkpoint is stale, more than five minutes in the future, or the local
chain
has more than the permitted unanchored events. It requires the application
database
  and configured public key.

The standalone verifier intentionally does not claim to validate the database
chain
prefix. It now strictly validates calendar timestamps, rejects timestamps more
than five
minutes in the future, and optionally enforces a maximum age without database
access.
Run it from a separate security account or verifier host with only the public
key. Run
the application monitor from the application trust boundary. A successful
standalone
signature/freshness check is not proof that the checkpoint matches the current
database.

The standalone verifier and application monitor return non-zero exit codes for
invalid
signatures, environment or predecessor mismatch, malformed timestamps, future
timestamps, and (when configured) stale checkpoints. The application monitor
additionally fails for missing/unreadable checkpoints, database-chain mismatch,
invalid
thresholds, or an excessive unanchored tail. Its normal output contains
checkpoint
digest and sequence metrics, not event payloads. Use `--json` for one
machine-readable
evidence record with schema version, healthy/failed status, an allowlisted
reason code,
UTC observation time, validated environment (when available), checkpoint
digest/sequence
metrics (when verified), age, and exit code. The command deliberately suppresses
raw
exception messages in both output modes; malformed checkpoint bytes,
SQL/provider
exception text, credentials, and customer-controlled payloads are never copied
into the
evidence record. The evidence record contract is versioned at
`docs/operations/audit-anchor-alert-evidence.schema.json` (JSON Schema 2020-12, schema
version 1); consumers should reject unknown schema versions and unexpected
fields rather
than silently accepting drift. The evidence record is suitable for an external
alerting
agent to capture, but this change does not configure delivery, storage, or a
production
schedule.

## Monitoring and alert routing

Shahrukh approved the following operational thresholds on 2026-11-14; treat
these as the
release baseline, not as values awaiting approval:

- Error rate: alert when greater than 2% over a rolling 5-minute window.
- API latency: alert when p95 exceeds 1,500 ms.
- Successful backup: alert when no successful backup has completed for 26 hours.
- Disk utilization: warning at 80%; critical at 90%.
- Queue age: alert when any queued job is older than 15 minutes.

Route standard alerts to the configured owner email and one configured team
channel
(Slack or Discord). Critical alerts must additionally be sent through configured
WhatsApp and/or SMS delivery adapters. Destinations and provider credentials are
runtime
environment/secret-manager configuration only; repository templates contain
blank values
and no real addresses, webhook URLs, phone numbers, or tokens. Delivery adapters
must
redact secrets and must not treat an HTTP request being queued as confirmed
delivery
without provider acknowledgement.

- [ ]  Configure the owner email and exactly one primary team channel (Slack or
      Discord)
  in the deployment secret manager.
- [ ]  Configure and test critical WhatsApp/SMS provider adapters and delivery
      receipts.
- [ ]  Run the monitor from an external scheduler/monitoring agent at a cadence
      shorter
  than the configured checkpoint freshness threshold.
- [ ]  Alert on every non-zero exit, missing checkpoint, stale checkpoint,
      signature
  failure, chain mismatch, or unanchored-tail threshold breach.
- [ ] Capture `php artisan audit:anchor:monitor /secure/path/checkpoint.json
--max-age-minutes=N --max-unanchored-tail=N --json` stdout as the structured
alert
evidence; send only the allowlisted fields to the approved security/on-call
route.
- [ ]  Do not include private keys, checkpoint signing secrets, audit event
      metadata,
customer data, database connection strings, or raw exception traces in
notifications.
- [ ]  Treat an alert as an incident until the last known-good external
      checkpoint and
  local chain prefix are independently verified.
- [ ]  Preserve alert delivery receipts and incident disposition in the external
      audit
  evidence store.

The approved alert thresholds and destination policy are recorded in
`config/operations.php` and `.env.example`. The application now includes the
operations:check-alerts command, an alert-state/deduplication store, email and
Slack/Discord delivery, and provider-neutral critical WhatsApp/SMS HTTPS webhook
adapters. Laravel Scheduler evaluates every minute with withoutOverlapping, but
the
schedule is disabled by default and requires OPERATIONS_ALERTS_ENABLED=true
after the
owner email, exactly one team route, at least one critical route, secret-manager
configuration, and non-production delivery tests are complete. A webhook counts
as
delivered only on a 2xx acknowledgement. Provider-specific WhatsApp/SMS bridge
provisioning and production credentials remain external owner-controlled gates;
do not
claim alerts are live until delivery evidence exists. Checkpoint cadence and
maximum
unanchored window remain explicit operational configuration decisions.

## Threshold evaluation

The pure policy evaluator at `app/Services/Operations/AlertThresholdPolicy.php` applies
the approved thresholds to already-aggregated metric inputs: `error_rate_percent`
(five-minute window), `api_p95_latency_ms`, `last_successful_backup_at`,
`disk_usage_percent`, and `oldest_queue_job_age_minutes`. It emits stable reason codes,
severity, observed value, and threshold without including customer or secret
data.
Boundary tests cover strict greater-than checks, 80%/90% disk escalation, the
26-hour
backup threshold, missing/invalid/future backup timestamps, and the five-minute
error-rate window.

The application metric source aggregates API status/duration samples captured by
middleware without storing request/response payloads; computes rolling-window
5xx rate
and p95 latency; reads disk utilization from the configured filesystem path;
reads
backup freshness from an operator-managed success-marker file; and obtains queue
age
from QueueJobMetricsTracker event instrumentation for Laravel queue backends,
with a
database-queue fallback. The tracker stores only connection name, queue name,
job ID,
and available-at time; it does not store job payloads. Run php artisan
operations:metrics for a redacted JSON snapshot; the command also prunes metric
samples
older than 24 hours. The backup marker must be updated atomically only after a
backup is
verified. Missing metrics remain null rather than being fabricated. This is not
an
independent external monitor. Alert scheduling and delivery adapters are
implemented,
but the scheduler is disabled by default; configure the owner email and one
Slack/Discord destination plus critical WhatsApp/SMS bridges, then verify
delivery
receipts in an isolated environment before enabling it.

## Immutable object upload contract

The application includes an `AuditCheckpointObjectStore` contract, publisher orchestration,
and an AWS CLI-backed S3-compatible adapter. The adapter requires a separately
named
audit-anchor bucket, endpoint, region, and AWS credentials from the configured
S3
credential source. It never falls back to the operational-backup bucket. The
publish
command is manual and does not schedule itself:

```sh
php artisan audit:anchor:export > /secure/path/audit-checkpoint.json
sha256sum /secure/path/audit-checkpoint.json
php artisan audit:anchor:publish /secure/path/audit-checkpoint.json
```

For every checkpoint after the first, export and publish must both receive the
exact
SHA-256 digest of the previously retained checkpoint:

```sh
php artisan audit:anchor:export --previous=PREVIOUS_CHECKPOINT_SHA256 > /secure/path/audit-checkpoint.json
php artisan audit:anchor:publish /secure/path/audit-checkpoint.json --expected-previous=PREVIOUS_CHECKPOINT_SHA256
```

The publisher verifies the detached signature and local chain prefix, requires
the
expected predecessor when the signed payload declares one, derives a
deterministic
environment/sequence/digest key, and requests conditional creation with
`If-None-Match: *`. The S3 adapter requests Object Lock COMPLIANCE retention, captures
the exact VersionId, and reads that version back. The publisher compares exact
bytes,
version ID, retention mode, and retain-until timestamp before reporting success.
The
retention deadline is derived from the signed checkpoint creation time plus 365
days,
so retrying the same checkpoint uses the same immutable deadline. Expired
checkpoints
cannot be newly published.

If create returns an error, the publisher never retries the write: it looks up
the
deterministic key, resolves its exact version, reads that version back, and
validates
the bytes and retention metadata. A confirmed absent key re-throws the create
failure;
authorization/transport ambiguity and failed read-back remain failures. The
adapter
suppresses raw AWS CLI diagnostics and fails closed when configuration, version
IDs,
Object Lock metadata, or conditional-create support is missing. Exact-version
downloads
are read with a strict 64 KiB + 1 byte bound before content is returned to the
publisher,
matching the checkpoint verifier's 64 KiB input ceiling and preventing an
unexpectedly
large remote object from being loaded without limit.

The provider-neutral publisher has fake-store tests for duplicate-key rejection,
missing version ID, version mismatch, checksum mismatch, retention-mode
mismatch,
too-short retention, ambiguous create reconciliation, unresolved create
outcomes,
required predecessor digest, and deterministic retention deadline. The real S3
adapter
still requires a disposable non-production bucket or provider emulator test for
conditional-create races, actual VersionIds, 404 versus authorization behavior,
COMPLIANCE metadata, and exact-version download. Do not mark production
anchoring active
until those tests and remote read-back evidence are complete.

Required environment configuration:

-  `AUDIT_ANCHOR_BUCKET`: dedicated Object Lock bucket; do not reuse the backup bucket.
- `AUDIT_ANCHOR_ENDPOINT`, `AUDIT_ANCHOR_REGION`, and
`AUDIT_ANCHOR_AWS_CLI_BINARY`: S3-compatible endpoint and client configuration.
- `AUDIT_ANCHOR_AWS_ACCESS_KEY_ID` and `AUDIT_ANCHOR_AWS_SECRET_ACCESS_KEY`:
dedicated least-privilege credentials from the deployment secret manager. They
are
intentionally separate from the backup bucket credentials and never stored in
Git.
- `AUDIT_ANCHOR_PRIVATE_KEY` and `AUDIT_ANCHOR_PUBLIC_KEY`: independent Ed25519
  keys provisioned outside the application environment.

The adapter requires HTTPS, dedicated credentials, and a bucket distinct from
the
configured backup bucket. It does not create buckets, enable versioning/Object
Lock,
configure IAM, or create credentials. No upload is attempted unless the explicit
audit bucket, endpoint,
region, credentials, and signing keys are configured. Production Object Lock
setup,
provider compatibility, key custody, and isolated rehearsal remain
owner-controlled
release gates.

## Recovery and chain continuity

-  Keep the prior checkpoint SHA-256 outside the application database and pass
  it as the
  expected predecessor when independently verifying the next checkpoint.
-  Preserve all historical checkpoint bytes and public-key fingerprints. Never
  replace an
  existing immutable object.
-  If a checkpoint is missing or continuity fails, stop checkpoint advancement,
  preserve
  evidence, and investigate. Do not create a new chain root to conceal a gap.
-  After restoring a database backup, verify the restored chain against the
  latest
externally retained checkpoint and document any legitimate unanchored tail
created
  after that checkpoint.

## Pending for Shahrukh

-  Create and fund the independent cloud/security account and approve its
  billing; then
  provision the 365-day Object Lock COMPLIANCE bucket and provide actual
  configuration/read-back evidence.
-  Generate and retain custody of the production Ed25519 signing/anchor keys.
  Shahrukh is
the sole initial signing/verifier operator; keep future second-operator
enrollment
possible. Provide only non-secret public-key references/fingerprints through the
  approved secure channel.
-  Provide approved secret-manager access and access policy so alert
  destinations and
  production key references can be configured securely.

No cloud resource, production signing key, credential, or production alert route
was
created or accessed by this implementation.

## Application database backup and restore

The application backup tooling is implemented in the release-readiness branch
and
documented in docs/operations/backup-restore-rehearsal-runbook.md. It currently
covers
the PostgreSQL application database only. The tool creates a custom-format
pg_dump,
encrypts it with authenticated libsodium secretstream, uploads it to the Wasabi
S3-compatible endpoint with explicit Object Lock COMPLIANCE retention and SSE-S3
AES256,
then verifies bucket versioning/Object Lock, object metadata, and a full
read-back
SHA-256 before atomically updating the backup success marker.

Backup scheduling defaults to daily at 00:00 UTC but remains disabled until
OPERATIONS_BACKUPS_ENABLED=true. The backup retention duration is intentionally
a
required owner decision and is not inferred from the separate 365-day audit-log
retention. Dedicated read-only dump and migration restore credentials are
required.
Production restore requires the dedicated owner email, fresh TOTP, an approval
reference, explicit interactive confirmation, a verified pre-restore backup, and
an
immutable approval record in the Object-Locked bucket. RPO is 24 hours and RTO
is 4
hours.

The separate WHMCS/MariaDB database, uploaded local files, and external provider
state
are not included in this initial PostgreSQL tool. Wasabi account/billing,
production
credentials, backup retention approval, secret-manager access, and isolated
rehearsal
access remain external release gates. No production backup or restore has been
executed.

## Queue age tracking

Queue-age telemetry is collected through Laravel queue lifecycle events across
Redis and
other evented backends, including dispatch delay and exception retry backoff.
Only
connection name, queue name, job ID, and available-at timestamp are stored; job
payloads
are not persisted. Existing database-queue rows have a direct fallback.
Non-database
backlog already present before instrumentation is deployed is not backfilled,
and
explicit manual releases without exception metadata are treated as immediately
available.
