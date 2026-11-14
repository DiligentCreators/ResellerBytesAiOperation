# Release-readiness progress — 2026-11-14

## Live branch verification

- Repository: `DiligentCreators/ResellerBytesAiOperation`
- Pull request: [#2 — Release
  readiness](https://github.com/DiligentCreators/ResellerBytesAiOperation/pull/2)
- Branch: `work/release-readiness-audit`
- Latest reviewed head: `b6a77893a3836c1ec7f8763b255a116a6413fbf2`
- PR remains open and Draft. No merge, production migration, deployment, or live WHMCS
  change has been performed.

## Previously verified CI baseline

GitHub Actions run
[43506527149](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43506527149)
passed on the earlier PR head `4d461b08a1a3f95890acbf078e88a394dd36d674`. All four jobs
were individually confirmed successful:

- General CI
- PostgreSQL AI-budget and audit-chain concurrency
- MySQL 8.4 integration-retention trigger
- WHMCS addon PHP 8.1 compatibility

This run does not verify the later SQL Server/MariaDB additions in this branch.

## Changes added after that baseline

- Added an isolated SQL Server 2022 integration script that creates a minimal test
  table, invokes the production WHMCS integration-retention migration, verifies
  query-builder DELETE rejection and row preservation, then removes its test
  trigger/table.
- Added a SQL Server 2022 GitHub Actions service job using the Microsoft SQL Server PHP
  drivers.
- Added a MariaDB 11.4 GitHub Actions service job and expanded the existing MySQL
  retention test so it also accepts the MariaDB PDO driver.
- Exposed explicit SQL Server TLS encryption and trust-server-certificate configuration
  through environment settings; CI enables encryption and trusts only the ephemeral
  self-signed service certificate.
- Updated the retention policy and production-pilot checklist to identify MySQL 8.4,
  MariaDB 11.4, and SQL Server 2022 engine-specific CI gates.
- Retention reporting now calculates its threshold from an explicit UTC clock and has an
  inclusive-cutoff boundary test. It remains report-only. No diagnostic, finding,
  recommendation, integration, or audit data is deleted by these changes.
- The standalone audit integrity verifier now locks the chain-head row inside its read
  transaction so concurrent appends cannot produce a false mismatch between the event
  cursor and the stored head. Added empty-chain verification coverage.

## Independent review preparation

- Added `docs/operations/independent-security-review-checklist.md` with explicit test
  coverage for authentication/TOTP, authorization/IDOR, WHMCS ingestion and addon
  boundaries, AI spend controls, audit integrity, database privilege separation,
  retention/backups, infrastructure, and mandatory no-go findings. It is a preparation
  checklist, not an independent security sign-off.
- Deployment and pilot documentation now require confirmed TOTP; passkeys are
  supplementary, consistent with the application access gate.
- `.env.example` now documents verified PostgreSQL TLS (`DB_SSLMODE=verify-full`) and
  secure optional SQL Server defaults (`DB_ENCRYPT=yes`,
  `DB_TRUST_SERVER_CERTIFICATE=false`). The deployment runbook requires PostgreSQL
  certificate and hostname verification.

## Verification status of this change batch

- Latest branch head: `7184d09429cc8522310cd94cfe4e30355e7c5d96`.
- Run
  [43514242797](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43514242797)
  (#495) exercised the new matrix on the earlier PR merge SHA: PostgreSQL, MySQL 8.4,
  MariaDB 11.4, and WHMCS PHP 8.1 passed; SQL Server setup failed because that job used
  PHP 8.4 while the lock file requires the project PHP 8.5 runtime; general CI failed
  Pint on one extra blank line and one missing global-class import. Those three causes
  have been corrected in subsequent commits (SQL Server now uses PHP 8.5; both Pint
  findings fixed). This is not yet a passing rerun.
- Run
  [43514522003](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43514522003)
  (#502) passed all six jobs on code head `238ba1f7f8fb4b09d0ffd8913e4e7bba308ebb42`.
  General CI: 145 passed, 3 skipped, 619 assertions; PostgreSQL AI-budget/audit
  concurrency, MySQL 8.4, MariaDB 11.4, SQL Server 2022, and WHMCS PHP 8.1 jobs all
  passed.
- A documentation-only clarification was then committed at latest head
  `f5c5e47c6365993cf6bff82dfdc44a6fdc631b64`; run
  [43514673907](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43514673907)
  (#503) is checking that exact head. Five jobs have completed successfully so far;
  general CI is still running at the time of this update.
- MariaDB 11.4 passed its dedicated test in run #495. SQL Server 2022 and the general CI
  suite need a rerun on the latest head after the fixes above; no latest-head green run
  has been confirmed yet. The latest PR SHA currently has no associated workflow run
  returned by GitHub; CI execution is still pending.
- SQL Server coverage is a focused trigger integration check, not a full Laravel
  migration-suite compatibility claim for SQL Server.
- No production credentials or external cloud account are required for these repository
  changes.

## Remaining work

1. Verify all six CI jobs on the exact latest branch SHA; fix any failures and rerun
   until green.
2. Continue release checklist, security review preparation, and negative privilege/test
   coverage without enabling destructive retention.
3. Keep only the seven owner-only inputs in the `Pending for Shahrukh` list below;
   approved operating thresholds, routing policy, recovery objectives, retention target,
   signing operator, and PostgreSQL role design are decisions already made.
4. Keep PR #2 Draft. Do not merge, deploy, or run production migrations.

## Shahrukh-approved operational decisions — 2026-11-14

The following decisions are approved and are now implementation requirements, not
owner-pending decisions:

- Monitoring: error rate greater than 2% over 5 minutes; API p95 latency greater than
  1.5 seconds; no successful backup for 26 hours; disk at 80% warning and 90% critical;
  queue jobs older than 15 minutes alert.
- Routing: owner email plus one Slack or Discord channel; critical notifications
  additionally through WhatsApp/SMS. All destinations and credentials are runtime
  environment/secret-manager configuration; no real endpoints or tokens belong in Git.
- Recovery: RPO 24 hours, RTO 4 hours; every production restore requires Shahrukh's
  explicit approval.
- External audit retention: 365 days with Object Lock COMPLIANCE mode.
- Signing and verifier operations: Shahrukh is the sole initial operator; operator
  identity model must permit a second trusted operator later.
- PostgreSQL: separate runtime (read/write, no DDL), deploy-only migration (DDL), and
  read-only reporting (SELECT only on an explicit approved allowlist) roles.

The config and runbooks record these approved values. Alert delivery, provider adapters,
storage provisioning, actual PostgreSQL production roles, and production recovery remain
unprovisioned until their external dependencies are supplied.

## Latest branch validation and implementation batch

- Exact commit `e55c0e99b0523bacd062e54474f9e189ddfcae8b` passed all six required CI
  jobs in run
  [#43578328332](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43578328332).
- Applied approved monitoring thresholds, notification destination configuration,
  recovery objectives/approval gate, 365-day COMPLIANCE audit retention, and initial
  single-operator configuration to `config/operations.php` and `.env.example`.
- Updated monitoring and backup/restore runbooks with the approved policy.
- Expanded PostgreSQL role guidance to three distinct application identities. Reporting
  access is explicitly allowlisted rather than broad schema-wide SELECT.
- Added a PostgreSQL CI regression script for the read-only reporting identity;
  exact-head CI passed on `cff03dd7264138929753101d7b446f0d3a88df1f`.
- PR #2 remains open and Draft. No merge, deploy, production migration, or live WHMCS
  change has occurred.

## Pending for Shahrukh — owner-only inputs/actions

Do not mark these complete until Shahrukh provides or authorizes them:

1. Create and fund the separate cloud/security account and approve its billing.
2. Generate and retain custody of the production Ed25519 signing/anchor keys; provide
   only non-secret public-key references/fingerprints through an approved secure
   channel.
3. Provide approved secret-manager access and access policy.
4. Verify actual AI provider/model pricing and approve the production price-card version
   and expiry.
5. Provide approved backup/restore rehearsal access and an isolated recovery
   environment.
6. Arrange and complete the independent security review against the exact reviewed SHA.
7. Provide final release authorization.

Approved monitoring, alert-routing, recovery, retention, signing-operator, and
PostgreSQL role decisions are not pending. No production resource, secret, provider
pricing approval, backup access, independent review, or release authorization is
inferred from the repository changes.

## Exact-head CI confirmation — 2026-11-14

- Commit `cff03dd7264138929753101d7b446f0d3a88df1f` passed all six required jobs in [run
  #43580604544](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43580604544).
- General CI: **190 passed, 866 assertions**.
- PostgreSQL CI verified the runtime identity's append-only audit and no-DDL
  restrictions, plus a read-only reporting identity that can SELECT only from an
  explicitly granted reporting table and is denied unapproved audit reads, writes,
  migration-ledger reads, and DDL.
- MySQL 8.4, MariaDB 11.4, SQL Server 2022, and WHMCS PHP 8.1 compatibility jobs all
  passed.
- PR #2 remains open and Draft; no merge, deployment, production migration, or live
  WHMCS operation occurred.

## Approved monitoring threshold evaluator

- Added `app/Services/Operations/AlertThresholdPolicy.php` to evaluate the approved
  error-rate, API p95, backup age, disk utilization, and queue-age thresholds from
  normalized metric inputs.
- Added boundary tests for strict error/latency/queue thresholds, disk warning/critical
  escalation, the 26-hour backup limit, and missing/invalid/future backup timestamps.
- Metric collection and alert delivery are implemented in the subsequent sections.
  Production delivery remains gated by owner-controlled destinations, credentials, and
  non-production verification.
- Exact-head run
  [#43581384457](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43581384457)
  passed all six jobs on `d8cb3620fcf36a2ffa7582aa2c74bafa1b4eb90a`; general CI reported
  **195 passed, 878 assertions**.

## Final exact-head CI — 2026-11-14

- Exact PR head `d8cb3620fcf36a2ffa7582aa2c74bafa1b4eb90a` passed all six jobs in [run
  #43581384457](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43581384457).
- General CI: **195 passed, 878 assertions**.
- PostgreSQL runtime and read-only reporting role checks passed, including explicit
  reporting allowlist enforcement.
- MySQL 8.4, MariaDB 11.4, SQL Server 2022, and WHMCS PHP 8.1 jobs passed.
- PR #2 remains open and Draft. No merge, deployment, production migration, or live
  WHMCS change has been performed.

## Metric-source integration — initial implementation

- Added API middleware that records response status and elapsed time into a dedicated
  operations_metric_samples table. It stores no request body, URL parameters, customer
  identifiers, or response content; telemetry write failures are logged by exception
  class and never fail the API request.
- Added OperationalMetricsSource to compute rolling-window 5xx error rate and p95
  latency from those samples, disk utilization from a configured filesystem path, and
  queue age from queue lifecycle tracking plus the database queue fallback.
- Successful-backup age is sourced from the modification time of an operator-provisioned
  marker file. The backup agent must update that marker atomically only after a backup
  has completed and passed its verification checks. A missing marker remains a
  missing-backup signal.
- Added php artisan operations:metrics to emit a versioned, redacted JSON snapshot and
  prune telemetry samples older than 24 hours. It is not scheduled and does not send
  alerts in this step.
- Added portable migration and feature tests for rolling-window aggregation, p95, API
  status capture without payload persistence, backup marker timestamps, and telemetry
  pruning.
- Queue age is now tracked through Laravel queue lifecycle events across Redis and other
  evented queue backends: dispatch delay is respected, processing/failure clears the
  sample, and exception retries are re-enqueued using their backoff. The database queue
  also has a direct fallback for pre-existing jobs. Only jobs enqueued after
  instrumentation is deployed are tracked on non-database backends; explicit manual
  releases without an exception are conservatively treated as immediately available.
- This is application-side metric collection, not an independent external monitor. Alert
  scheduling and backup/restore tooling are implemented; security-review readiness
  remains the next roadmap step.
- Exact-head run #43597533823 passed all six CI jobs on
  `d0fcf3f246fdd37d531068cde6cdfcb913e497d6`; general CI reported **198 passed, 3
  skipped, 891 assertions**. No production marker, external queue provider, backup
  credential, or alert destination has been configured.

## Alert scheduling and notification adapters — complete (production gated)

- Added the operations:check-alerts command to evaluate the approved metric policy and
  manage active alert state.
- Added an idempotent alert-state table with active/inactive lifecycle, delivery claim
  leases, first/last observation timestamps, last successful notification timestamp, and
  notification counts.
- Added email, Slack, and Discord delivery adapters. Critical alerts additionally use
  configured WhatsApp/SMS HTTPS webhook bridges; each configured critical route must
  acknowledge with a 2xx response.
- Delivery requires a valid owner email and exactly one configured primary team channel.
  Critical alerts additionally require at least one WhatsApp/SMS route. Missing routes
  and non-2xx provider responses remain failures and are retried.
- Added 15-minute default repeat suppression, retry after incomplete delivery, and
  one-minute scheduler evaluation with withoutOverlapping. The schedule is disabled by
  default and requires OPERATIONS_ALERTS_ENABLED=true after secret-manager configuration
  and non-production delivery tests.
- Added feature tests for standard/critical routing, provider acknowledgement,
  deduplication, retry, and alert reactivation.
- Provider-specific WhatsApp/SMS bridge provisioning and real destination credentials
  remain owner-controlled. Recovery notifications are not included in this phase.
- Exact-head run #43598332919 passed all six CI jobs on
  `703772cc8def8f45d394ef53fc80ca582c3dae68`; general CI reported **203 passed, 3
  skipped, 915 assertions**.

## Outstanding owner-controlled gates

- Wasabi account creation and billing approval; bucket provisioning and actual Object
  Lock COMPLIANCE read-back.
- Approve the backup Object Lock retention period in days; this value is mandatory and
  has no assumed default.
- Provide production Ed25519 key custody and secure public-key references/fingerprints.
- Provide secret-manager access and dedicated backup read-only / restore migration DB
  credentials, backup encryption key, and alert destination secrets.
- Provide isolated backup rehearsal access; no production restore has been performed.
- Approve AI provider pricing and the price card.
- Complete the independent security review and provide release authorization.
- Keep PR #2 Draft; do not merge or deploy without explicit separate authorization.

## Queue-age metric coverage follow-up

- Added a queue-job sample table and lifecycle event tracking for Laravel JobQueued,
  JobProcessing, JobReleasedAfterException, JobProcessed, and JobFailed events.
- Delayed dispatches are not counted until their available_at time; jobs are removed
  from the waiting metric when processing starts, and exception retries are re-tracked
  with their backoff.
- This closes the default Redis queue coverage gap for newly enqueued jobs. The database
  queue retains a direct query fallback for existing rows.
- No queue payload, job arguments, or customer data are stored; only connection name,
  queue name, job ID, and availability timestamp are recorded.
- Non-database backlog already present before this instrumentation is deployed is not
  backfilled. Explicit manual releases without exception metadata are tracked as
  immediately available.
- Validation is pending on the exact queue-tracking commit.

## Backup/restore tooling and rehearsal runbook — implementation complete, external rehearsal pending

- Implemented PostgreSQL custom-format dumps using the dedicated read-only backup role
  and restore using the dedicated migration role; runtime database credentials are not
  reused for either operation.
- Encrypted archives use libsodium XChaCha20-Poly1305 secretstream with authenticated
  chunk framing and SHA-256 digests for both plaintext and ciphertext.
- Wasabi S3-compatible upload requires HTTPS, configured credentials, bucket versioning,
  Object Lock enabled, and an explicit positive owner-approved retention period. Each
  archive and manifest upload requests per-object Object Lock COMPLIANCE retention and
  SSE-S3 AES256, then verifies lock mode, retain-until date, byte count, and SHA-256
  metadata.
- Backup creation performs a full object read-back checksum before atomically updating
  the success marker. A failed or incomplete operation does not update the marker. The
  current single-PutObject implementation fails closed above 5 GiB; larger databases
  require a multipart Object Lock adapter.
- Added verification-only restore (operations:backup:restore UUID) that checks manifest
  integrity, the archive object's Object Lock metadata, encrypted and decrypted SHA-256,
  authenticated decryption, and pg_restore --list without changing a database.
- Destructive restore requires --execute, an interactive confirmation, a fresh verified
  pre-restore backup, and an immutable approval record. Production additionally requires
  --approved-by matching OPERATIONS_OWNER_EMAIL, an approval reference, and fresh owner
  TOTP. Restore refuses a manifest database name that does not match the configured
  PostgreSQL target.
- Added cipher tests for round-trip confidentiality/integrity, tamper rejection, and
  incorrect-key rejection; added a manager test for fail-closed Wasabi configuration and
  a restore-target identity mismatch test.
- Added a detailed isolated rehearsal runbook covering Wasabi Object Lock evidence, RPO
  24h / RTO 4h measurement, restore isolation, PostgreSQL role separation, audit-chain
  and external checkpoint verification, legal holds, retention checks, and evidence/exit
  criteria.
- Daily scheduling exists but remains disabled unless OPERATIONS_BACKUPS_ENABLED=true.
  Do not enable until Wasabi account/billing, bucket configuration, approved backup
  retention days, secret-manager access, encryption key, dedicated DB credentials,
  marker path, and isolated rehearsal access are available.
- Scope limitation: this tool backs up the PostgreSQL application database only;
  WHMCS/MariaDB, uploaded files, and external provider state require separately designed
  backup coverage. The 5 GiB single-PutObject limit is a hard fail-closed boundary.
- No backup or restore was executed against a real database or Wasabi bucket. Production
  restore remains explicitly gated by Shahrukh's incident-specific approval.
- The base backup/restore implementation passed exact-head run #43599455622 (206 passed,
  3 skipped, 921 assertions); subsequent queue-tracking and restore-target identity
  changes are pending their own exact-head CI run.

## Security-review readiness and retention-policy alignment

- Created docs/operations/security-review-readiness-pack.md as an evidence index and
  reviewer test plan. It explicitly distinguishes repository implementation from real
  infrastructure evidence and independent security approval.
- Updated the independent review checklist for current provider-neutral checkpoint
  publishing, alert scheduling, Wasabi backup/restore tooling, and owner-controlled
  external provisioning gates.
- Reconciled diagnostic retention implementation with Shahrukh's explicit no-exception
  decision: active legal holds no longer extend diagnostic data retention; hold rows
  transition to expired_by_retention and are audited when their target data reaches 365
  days. Holds on already-expired targets are rejected. The legal-hold status column was
  widened to 32 characters for the explicit state.
- Corrected the backup single-PutObject maximum to 5 GB (5,000,000,000 bytes), not 5
  GiB.
- Exact-head CI is intentionally deferred until the final one-shot commit. The earlier
  run #43599729637 was triggered by a concurrent commit without [skip ci] and failed
  only the general formatting check in the external audit-anchor document; five matrix
  jobs passed. The document has since been updated. No CI run was requested for our
  skip-ci commits.

## CI-cost policy exception log and backup test correction

- Three concurrent commits without [skip ci] were observed after Shahrukh's instruction,
  triggering extra runs despite this assistant's skip-ci commits: #43599729637 on
  555f92e (general formatting failure in
  external-audit-anchor-monitoring-and-storage.md; five matrix jobs passed),
  #43600418285 on a831220 (general CI failed 30 tests after a backup test changed
  database.default to an unreachable PostgreSQL host, cascading into SQLite transaction
  errors; five matrix jobs passed), and #43600581151 on 591aead (in progress at this
  update).
- The current hardening batch adds BackupArchiveManager::assertRestoreTargetMatches as a
  pure config comparison and rewrites BackupArchiveManagerTest to keep the active test
  database connection unchanged. The external audit-anchor documentation formatting has
  also been normalized.
- No CI was requested for the assistant-authored skip-ci commits. From this point, no
  further CI should run until the one final commit without [skip ci] after all roadmap
  work is complete.

- Run #43600581151 on concurrent commit 591aead also completed with general CI failure
  caused by the same test-only `database.default` mutation to `recovery-db.internal`;
  five matrix jobs passed. The current skip-ci hardening commit removes the mutation and
  adds a pure restore-target identity assertion. No additional CI run was triggered for
  commit 3a529936c21ab93a0ae85423d11c7fd92f230c3b.

- Additional backup immutability hardening: archive and manifest uploads now require
  conditional If-None-Match creation and provider-returned VersionIds. The encrypted
  archive VersionId is recorded in the manifest; Object Lock head checks and read-back
  downloads target that exact version. Restore rejects manifests without the pinned
  archive VersionId. Providers that do not support conditional create/version IDs fail
  closed.

- Run #43600781118 on concurrent commit 466be2e completed with five matrix jobs passed
  and general CI formatting failure in the external audit-anchor, release-readiness
  progress, and security-review readiness Markdown files. Those files have since been
  reflowed. No CI run was triggered for the current version-pinning code/documentation
  changes.

## Static source review follow-up — 2026-11-14

- Backup manifest schema is now version 2 and binds each archive to the configured
  PostgreSQL database name, host, and port. Restore refuses a missing or mismatched
  endpoint identity instead of relying on database name alone.
- Backup manifest uploads now receive exact-version Object Lock metadata verification
  and a downloaded exact-VersionId SHA-256 read-back check. Restore also resolves the
  downloaded manifest VersionId and verifies its immutable metadata/checksum and
  minimum configured retention before trusting its archive reference.
- Operational alert resolution now uses metric availability explicitly. An unavailable
  error-rate, latency, disk, or queue metric cannot silently clear an active alert; a
  known healthy sample can resolve only the corresponding metric-backed alert. Added
  regression coverage for the unavailable-to-healthy transition.
- Corrected the restore rehearsal runbook so it no longer claims legal holds preserve
  diagnostic content. It now documents hold-metadata expiry and exact database
  endpoint matching. Corrected stale PR description statements: diagnostic retention
  has no exceptions and live audit events are retained indefinitely; 365-day COMPLIANCE
  retention applies to external audit-checkpoint backup objects.
- Intermediate commits for this review batch all include [skip ci]. No workflow file or
  repository setting was changed.
- Exact-head CI has intentionally not been triggered. The project requires PHP 8.5,
  while the available local CLI is PHP 8.4 and the repository checkout/dependencies are
  not mounted; do not treat static review as test execution or CI validation.
- PR #2 remains open and Draft. No merge, production migration, deployment, or live
  WHMCS operation is authorized.

## Static source review follow-up — retention and alert lifecycle

- Closed a retention-policy gap: the cleanup command no longer excludes received,
  queued, or analyzing diagnostic runs at the 365-day UTC cutoff. Active legal-hold
  metadata is expired and audited before the diagnostic content is deleted.
- Analysis jobs now refuse to start for expired diagnostics, queue recovery skips expired
  runs, and the provider pre-submit callback re-locks/rechecks the diagnostic row before
  marking an AI attempt submitted. If the retention cutoff is reached before submission,
  the reservation is released and no provider request is made.
- Updated regression coverage for inclusive cutoff deletion of active-status runs,
  expired queue-recovery suppression, analysis-start rejection, and reservation release
  at the pre-submit expiry boundary.
- The independent alert review found that missing metrics could incorrectly clear an
  existing alert. Alert resolution now requires a known/evaluated metric; unavailable
  error-rate, latency, disk, or queue data preserves the active state. Known healthy
  samples resolve the corresponding alert. The active-alert count reflects persisted
  states, and policy/state regression tests cover the behavior.
- Backup/restore hardening remains in place: manifest v2 binds database name/host/port;
  exact manifest and archive VersionIds are verified; manifest bytes receive an
  exact-version SHA-256 read-back; restore refuses endpoint mismatches.
- Retention, backup, alert, security-review, and progress documentation were reconciled.
  The PR description now correctly states absolute diagnostic expiry with no exceptions
  and indefinite live audit-event retention.
- Current exact head: b6a77893a3836c1ec7f8763b255a116a6413fbf2. All intermediate
  commits in this batch include [skip ci]. No workflow file or repository setting was
  changed. CI has not been triggered for this head by design; full tests and exact-head
  CI remain pending until the final one-shot commit.
- The current assistant environment cannot run the repository test suite: the project
  requires PHP 8.5, while only PHP 8.4 is available and the checkout/dependencies are not
  mounted. Static review is not represented as test execution.
- PR #2 remains open and Draft. No merge, production migration, deployment, or live
  WHMCS operation is authorized.

## Static source review follow-up — external audit-anchor publishing

- Completed the missing production-integration code path (without provisioning resources):
  added an AWS CLI-backed S3-compatible `AuditCheckpointObjectStore`, dependency
  binding, and manual `audit:anchor:publish` command.
- The adapter uses a dedicated audit bucket, separate audit credentials, explicit
  HTTPS endpoint, conditional `If-None-Match: *` create, returned object VersionId,
  exact-version read-back, and Object Lock COMPLIANCE metadata. It refuses to reuse the
  configured backup bucket and fails closed on missing bucket/credentials, invalid
  endpoint, unsupported conditional creation, missing VersionId, or missing retention
  metadata. Raw AWS CLI diagnostics are suppressed.
- Publisher retention deadline is now deterministic from the signed checkpoint
  creation time plus 365 days, so retrying the same checkpoint cannot silently move the
  retention target. Expired checkpoint objects cannot be newly published.
- Chained checkpoint publication and verification now require the expected predecessor
  digest; the monitor fails closed if a chained checkpoint is checked without that
  digest. Added regression tests for predecessor enforcement, stable retention, and
  dedicated-bucket/HTTPS/credential fail-closed behavior.
- Reconciled audit hash-chain design, external anchor operations guide, independent
  security checklist, and security-review pack to distinguish implemented adapter code
  from unprovisioned production cloud controls.
- Current branch head: `31d0449d01e4f80179db13deb54a5503e6ab3ea4`. All commits in this
  follow-up use [skip ci]. No workflow file or repository setting was modified in this
  follow-up. CI remains intentionally untriggered until the one final commit after all
  remaining work is complete.
- Provider-level rehearsal remains pending: no production bucket, credentials, key
  custody, Object Lock configuration, actual provider read-back, independent verifier
  schedule, or live anchor publication has been attempted. These stay in Shahrukh's
  pending list.
- Full automated tests and exact-head CI have not been run. The available environment
  still lacks PHP 8.5 and a mounted repository/dependency checkout; static review is not
  represented as test execution.
- PR #2 remains open and Draft. No merge, production migration, deployment, or live
  WHMCS operation is authorized.

## Additional audit-anchor validation hardening — 2026-11-14

- Registered `audit:anchor:publish` explicitly in Laravel's command list and added a
  command-level regression test proving it fails closed before storage access when the
  dedicated bucket is absent.
- Hardened the S3 adapter: dedicated audit credentials are separate from backup
  credentials; the audit bucket must differ from the configured backup bucket; the
  endpoint must be HTTPS and cannot embed user/password/query/fragment data. S3 metadata
  responses must be JSON objects. The adapter's create path captures metadata without a
  redundant object download; the publisher performs the single authoritative exact-
  VersionId read-back.
- Centralized strict UTC calendar timestamp validation in `AuditAnchorService`, so
  malformed but correctly signed timestamps are rejected by all verification callers,
  not only the monitor/publisher. Added regression coverage for impossible calendar
  timestamps and chained-monitor predecessor omission.
- Latest code head before this progress update: `0d8c73a13d3cdcc6ff8bc2235ddf341eda4312de`.
  All commits in this batch use [skip ci]. Workflow files and repository settings were
  not changed. No CI run has been triggered.
- Static source review and test additions are continuing; no test result is claimed.
  Full PHP 8.5 suite and final exact-head CI remain pending until the final one-shot
  commit. Production bucket, credentials, keys, provider rehearsal, and independent
  security review remain Shahrukh-controlled gates.

## Checkpoint publisher registration and final source-review notes

- Verified Laravel's explicit command registry and corrected it to include
  `PublishAuditAnchorCheckpoint`; added a command-level test for registration and
  fail-closed behavior when the dedicated audit bucket is not configured.
- The S3 adapter now rejects credential-bearing endpoint URLs and malformed metadata
  responses. Publisher output includes environment, object VersionId, retention mode,
  retain-until UTC, sequence, digest, and ambiguous-create reconciliation status.
- Publisher rejects signed checkpoint timestamps more than five minutes in the future.
  The central verifier rejects impossible calendar timestamps. Regression coverage was
  added for both timestamp paths and predecessor enforcement.
- Latest code/test head: `2f73e1428f90c32f24fff4cdef2673fa21e8bbe4`. Intermediate commits
  continue to include [skip ci]. No workflow files or repository settings were changed;
  no CI run has been triggered.
- Still pending: PHP 8.5 full test suite, one final CI run after all engineering work,
  actual non-production S3/Wasabi conditional-create and Object Lock rehearsal, production
  key/credential provisioning, independent security review, and release approval.

## Security-boundary review follow-up — 2026-11-14

- Reviewed the audit-chain append transaction, canonical hash payload, PostgreSQL runtime/read-only role tests, WHMCS addon setup/download routes, signed integration-request middleware, nonce replay handling, and integration revoke/rotate commands.
- Audit append uses a locked singleton chain-head row and a conditional sequence/hash update in the same transaction; PostgreSQL concurrency coverage exists. Runtime-role tests exercise append + chain verification while denying audit mutation, migration-ledger reads, schema creation, and integration hard delete.
- WHMCS operator routes are behind authenticated, verified, confirmed-2FA middleware. API ingestion requires a bounded JSON body, strict integration/timestamp/nonce/signature headers, HMAC verification, and unique nonce insertion; revoked integrations are excluded by the active-status lookup.
- Corrected a malformed PostgreSQL anonymous-block delimiter in the role-separation runbook (DO $$ ... $$;). No workflow file or repository setting was changed.
- Review note: no dedicated HTTP feature test for the WHMCS signed-request middleware was found in the changed test inventory; add direct authentication/replay/revocation coverage before the final validation pass.
- Latest branch head after this documentation correction: 54f3d7613f8feafa666ad3cbc46d5f677d55151e. All intermediate commits use [skip ci]. CI remains deferred until the one final non-skip-ci commit. Full PHP 8.5 suite remains pending because the current execution environment lacks PHP 8.5 and the repository checkout/dependencies.
- PR #2 remains open and Draft. No merge, deployment, production migration, or live WHMCS operation was performed.


## WHMCS request-authentication regression coverage — 2026-11-14

- Added `tests/Feature/WhmcsIntegrationAuthenticationTest.php` for successful HMAC-authenticated ingestion, duplicate nonce replay (409), revoked integration (401), and invalid HMAC (401). The tests use generated ephemeral integration secrets and do not call a live WHMCS installation or AI provider.
- Tests are committed but not executed in this environment. Validate request path canonicalization, Laravel test response assertions, and schema compatibility in the final PHP 8.5 CI run.
- Current head: `0dcc3e715fd3145ae294b4419021f03ecf927fc2`. Intermediate commits use [skip ci]. No workflow file or repository setting was modified; CI remains deferred.


## Follow-up verification checkpoint — 2026-11-14

- Corrected both PostgreSQL anonymous-block delimiters in the role-separation runbook to valid `DO $$ ... $$;` syntax; verified the final file contains no malformed single-dollar `DO $` block.
- Completed the WHMCS authentication feature-test helper wiring: the tests bind to the Pest/Laravel test case and exercise the actual API route. Source-level inspection confirms all helper invocations pass the test case.
- Compare from the prior checkpoint `fcdf05e528bf0cd4f2d2a943e16f81093df6802e` shows only the PostgreSQL role runbook, this progress log, and the new WHMCS authentication feature test changed. No workflow files are in this follow-up diff.
- Latest code head: `7bc3039d394e3cc2d5f8a8c933b86d025f9c5c4b`. Exact-head workflow runs: none (all intermediate commits use [skip ci]). No full tests have been executed; PHP 8.5 and the mounted repository/dependency environment are unavailable here.
- PR #2 remains open and Draft, not merged. No deployment, production migration, live WHMCS call, production credential use, or repository-setting change occurred.


## WHMCS nonce lifecycle coverage — 2026-11-14

- Reviewed the scheduled `whmcs:nonces:prune` command and its unique `(integration_id, nonce)` constraint. The command deletes expired records in batches of 1,000 and caps each run at 10,000 deletions; the scheduler runs it every 15 minutes with overlap protection.
- Added `tests/Feature/PruneWhmcsIntegrationNoncesTest.php` to prove expired nonces are pruned while active replay guards remain. Test is committed but not executed.
- The signed-request feature tests cover valid HMAC ingestion, replay rejection, revoked integration rejection, and invalid signatures. All test helpers are wired to the Laravel test case; execution remains pending.
- Latest head at this checkpoint: `63a0bbca7c438e571da7cb898a2fa02b121b8966`. Every intermediate commit uses [skip ci]; no workflow files or repository settings changed.



## Confirmed AI usage settlement on provider error responses — 2026-11-14

- Updated the provider-response boundary to inspect authoritative usage metadata before classifying a non-2xx HTTP response as a request failure. When input/output token usage is present, the existing transactional ledger settlement callback runs first; the diagnostic still fails and does not persist findings from the unsuccessful response.
- Responses without confirmed usage do not settle and continue through the existing submitted-to-ambiguous fail-closed path, preserving the reservation and preventing an automatic duplicate attempt.
- Added an HTTP-faked regression test proving a 500 response carrying usage invokes settlement with input, cached-input, output, and total token counts before raising the safe `provider_request_failed` code. Existing timeout coverage continues to exercise ambiguous reservation handling.
- Tests are committed but not executed in this environment: PHP 8.5 and the repository checkout/dependencies are unavailable. No live provider call or credential use occurred.
- Every commit in this follow-up includes [skip ci]. No workflow file or repository setting was changed. Exact-head CI remains deferred until all roadmap engineering work is complete.
- Latest branch head at this checkpoint: `3ef0056bb0c0cf39fb8d691fb48b6fa9578a659e`. PR #2 remains open and Draft; no merge, deployment, production migration, or live WHMCS operation is authorized.


## S3-compatible audit-anchor adapter protocol coverage — 2026-11-14

- Added an isolated fake AWS CLI executable test for the S3 adapter's successful protocol path: conditional immutable create, returned VersionId, COMPLIANCE retention metadata, exact-VersionId object read, and returned content. The fake client performs no network access and uses only temporary test files.
- This complements the existing fail-closed configuration tests for dedicated bucket separation, HTTPS, credentials, and unsafe object keys. It does not prove Wasabi service compatibility; the non-production Wasabi Object Lock rehearsal remains an owner/infrastructure gate.
- The test is committed but not executed locally because the required PHP 8.5 runtime and mounted repository/dependencies are unavailable. CI remains deferred until the final one-shot non-skip-ci commit.
- All commits continue to include [skip ci]; no workflow file or repository setting was changed. PR #2 remains open and Draft, with no merge, deployment, production migration, or live storage operation authorized.


## Operational metric sample lifecycle scheduling — 2026-11-14

- Found that `operations:metrics` already pruned samples older than one day but was not registered with the scheduler. Added a non-overlapping hourly schedule so the operational metric table has a bounded retention lifecycle; alert evaluation continues to take its own current snapshot each minute.
- Added a scheduler regression test that asserts the metric maintenance command is registered. No alert destination, schedule enablement gate, production endpoint, or repository workflow was changed; the metric maintenance task is safe housekeeping and does not send notifications.
- Tests remain committed but unexecuted pending the required PHP 8.5 environment and exact-head CI. All commits in this batch include [skip ci].
