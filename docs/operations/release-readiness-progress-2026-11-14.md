# Release-readiness progress — 2026-11-14

## Live branch verification

- Repository: `DiligentCreators/ResellerBytesAiOperation`
- Pull request: [#2 — Release readiness](https://github.com/DiligentCreators/ResellerBytesAiOperation/pull/2)
- Branch: `work/release-readiness-audit`
- Latest reviewed head: `cff03dd7264138929753101d7b446f0d3a88df1f`
- PR remains open and Draft. No merge, production migration, deployment, or live WHMCS change has been performed.

## Previously verified CI baseline

GitHub Actions run [43506527149](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43506527149) passed on the earlier PR head `4d461b08a1a3f95890acbf078e88a394dd36d674`. All four jobs were individually confirmed successful:

- General CI
- PostgreSQL AI-budget and audit-chain concurrency
- MySQL 8.4 integration-retention trigger
- WHMCS addon PHP 8.1 compatibility

This run does not verify the later SQL Server/MariaDB additions in this branch.

## Changes added after that baseline

- Added an isolated SQL Server 2022 integration script that creates a minimal test table, invokes the production WHMCS integration-retention migration, verifies query-builder DELETE rejection and row preservation, then removes its test trigger/table.
- Added a SQL Server 2022 GitHub Actions service job using the Microsoft SQL Server PHP drivers.
- Added a MariaDB 11.4 GitHub Actions service job and expanded the existing MySQL retention test so it also accepts the MariaDB PDO driver.
- Exposed explicit SQL Server TLS encryption and trust-server-certificate configuration through environment settings; CI enables encryption and trusts only the ephemeral self-signed service certificate.
- Updated the retention policy and production-pilot checklist to identify MySQL 8.4, MariaDB 11.4, and SQL Server 2022 engine-specific CI gates.
- Retention reporting now calculates its threshold from an explicit UTC clock and has an inclusive-cutoff boundary test. It remains report-only. No diagnostic, finding, recommendation, integration, or audit data is deleted by these changes.
- The standalone audit integrity verifier now locks the chain-head row inside its read transaction so concurrent appends cannot produce a false mismatch between the event cursor and the stored head. Added empty-chain verification coverage.

## Independent review preparation

- Added `docs/operations/independent-security-review-checklist.md` with explicit test coverage for authentication/TOTP, authorization/IDOR, WHMCS ingestion and addon boundaries, AI spend controls, audit integrity, database privilege separation, retention/backups, infrastructure, and mandatory no-go findings. It is a preparation checklist, not an independent security sign-off.
- Deployment and pilot documentation now require confirmed TOTP; passkeys are supplementary, consistent with the application access gate.
- `.env.example` now documents verified PostgreSQL TLS (`DB_SSLMODE=verify-full`) and secure optional SQL Server defaults (`DB_ENCRYPT=yes`, `DB_TRUST_SERVER_CERTIFICATE=false`). The deployment runbook requires PostgreSQL certificate and hostname verification.

## Verification status of this change batch

- Latest branch head: `7184d09429cc8522310cd94cfe4e30355e7c5d96`.
- Run [43514242797](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43514242797) (#495) exercised the new matrix on the earlier PR merge SHA: PostgreSQL, MySQL 8.4, MariaDB 11.4, and WHMCS PHP 8.1 passed; SQL Server setup failed because that job used PHP 8.4 while the lock file requires the project PHP 8.5 runtime; general CI failed Pint on one extra blank line and one missing global-class import. Those three causes have been corrected in subsequent commits (SQL Server now uses PHP 8.5; both Pint findings fixed). This is not yet a passing rerun.
- Run [43514522003](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43514522003) (#502) passed all six jobs on code head `238ba1f7f8fb4b09d0ffd8913e4e7bba308ebb42`. General CI: 145 passed, 3 skipped, 619 assertions; PostgreSQL AI-budget/audit concurrency, MySQL 8.4, MariaDB 11.4, SQL Server 2022, and WHMCS PHP 8.1 jobs all passed.
- A documentation-only clarification was then committed at latest head `f5c5e47c6365993cf6bff82dfdc44a6fdc631b64`; run [43514673907](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43514673907) (#503) is checking that exact head. Five jobs have completed successfully so far; general CI is still running at the time of this update.
- MariaDB 11.4 passed its dedicated test in run #495. SQL Server 2022 and the general CI suite need a rerun on the latest head after the fixes above; no latest-head green run has been confirmed yet. The latest PR SHA currently has no associated workflow run returned by GitHub; CI execution is still pending.
- SQL Server coverage is a focused trigger integration check, not a full Laravel migration-suite compatibility claim for SQL Server.
- No production credentials or external cloud account are required for these repository changes.

## Remaining work

1. Verify all six CI jobs on the exact latest branch SHA; fix any failures and rerun until green.
2. Continue release checklist, security review preparation, and negative privilege/test coverage without enabling destructive retention.
3. Keep only the seven owner-only inputs in the `Pending for Shahrukh` list below; approved operating thresholds, routing policy, recovery objectives, retention target, signing operator, and PostgreSQL role design are decisions already made.
4. Keep PR #2 Draft. Do not merge, deploy, or run production migrations.

## Shahrukh-approved operational decisions — 2026-11-14

The following decisions are approved and are now implementation requirements, not owner-pending decisions:

- Monitoring: error rate greater than 2% over 5 minutes; API p95 latency greater than 1.5 seconds; no successful backup for 26 hours; disk at 80% warning and 90% critical; queue jobs older than 15 minutes alert.
- Routing: owner email plus one Slack or Discord channel; critical notifications additionally through WhatsApp/SMS. All destinations and credentials are runtime environment/secret-manager configuration; no real endpoints or tokens belong in Git.
- Recovery: RPO 24 hours, RTO 4 hours; every production restore requires Shahrukh's explicit approval.
- External audit retention: 365 days with Object Lock COMPLIANCE mode.
- Signing and verifier operations: Shahrukh is the sole initial operator; operator identity model must permit a second trusted operator later.
- PostgreSQL: separate runtime (read/write, no DDL), deploy-only migration (DDL), and read-only reporting (SELECT only on an explicit approved allowlist) roles.

The config and runbooks record these approved values. Alert delivery, provider adapters, storage provisioning, actual PostgreSQL production roles, and production recovery remain unprovisioned until their external dependencies are supplied.

## Latest branch validation and implementation batch

- Exact commit `e55c0e99b0523bacd062e54474f9e189ddfcae8b` passed all six required CI jobs in run [#43578328332](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43578328332).
- Applied approved monitoring thresholds, notification destination configuration, recovery objectives/approval gate, 365-day COMPLIANCE audit retention, and initial single-operator configuration to `config/operations.php` and `.env.example`.
- Updated monitoring and backup/restore runbooks with the approved policy.
- Expanded PostgreSQL role guidance to three distinct application identities. Reporting access is explicitly allowlisted rather than broad schema-wide SELECT.
- Added a PostgreSQL CI regression script for the read-only reporting identity; exact-head CI passed on `cff03dd7264138929753101d7b446f0d3a88df1f`.
- PR #2 remains open and Draft. No merge, deploy, production migration, or live WHMCS change has occurred.

## Pending for Shahrukh — owner-only inputs/actions

Do not mark these complete until Shahrukh provides or authorizes them:

1. Create and fund the separate cloud/security account and approve its billing.
2. Generate and retain custody of the production Ed25519 signing/anchor keys; provide only non-secret public-key references/fingerprints through an approved secure channel.
3. Provide approved secret-manager access and access policy.
4. Verify actual AI provider/model pricing and approve the production price-card version and expiry.
5. Provide approved backup/restore rehearsal access and an isolated recovery environment.
6. Arrange and complete the independent security review against the exact reviewed SHA.
7. Provide final release authorization.

Approved monitoring, alert-routing, recovery, retention, signing-operator, and PostgreSQL role decisions are not pending. No production resource, secret, provider pricing approval, backup access, independent review, or release authorization is inferred from the repository changes.

## Exact-head CI confirmation — 2026-11-14

- Commit `cff03dd7264138929753101d7b446f0d3a88df1f` passed all six required jobs in [run #43580604544](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43580604544).
- General CI: **190 passed, 866 assertions**.
- PostgreSQL CI verified the runtime identity's append-only audit and no-DDL restrictions, plus a read-only reporting identity that can SELECT only from an explicitly granted reporting table and is denied unapproved audit reads, writes, migration-ledger reads, and DDL.
- MySQL 8.4, MariaDB 11.4, SQL Server 2022, and WHMCS PHP 8.1 compatibility jobs all passed.
- PR #2 remains open and Draft; no merge, deployment, production migration, or live WHMCS operation occurred.

## Approved monitoring threshold evaluator

- Added `app/Services/Operations/AlertThresholdPolicy.php` to evaluate the approved error-rate, API p95, backup age, disk utilization, and queue-age thresholds from normalized metric inputs.
- Added boundary tests for strict error/latency/queue thresholds, disk warning/critical escalation, the 26-hour backup limit, and missing/invalid/future backup timestamps.
- Metric collection and alert delivery are implemented in the subsequent sections. Production delivery remains gated by owner-controlled destinations, credentials, and non-production verification.
- Exact-head run [#43581384457](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43581384457) passed all six jobs on `d8cb3620fcf36a2ffa7582aa2c74bafa1b4eb90a`; general CI reported **195 passed, 878 assertions**.

## Final exact-head CI — 2026-11-14

- Exact PR head `d8cb3620fcf36a2ffa7582aa2c74bafa1b4eb90a` passed all six jobs in [run #43581384457](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43581384457).
- General CI: **195 passed, 878 assertions**.
- PostgreSQL runtime and read-only reporting role checks passed, including explicit reporting allowlist enforcement.
- MySQL 8.4, MariaDB 11.4, SQL Server 2022, and WHMCS PHP 8.1 jobs passed.
- PR #2 remains open and Draft. No merge, deployment, production migration, or live WHMCS change has been performed.

## Metric-source integration — initial implementation

- Added API middleware that records response status and elapsed time into a dedicated operations_metric_samples table. It stores no request body, URL parameters, customer identifiers, or response content; telemetry write failures are logged by exception class and never fail the API request.
- Added OperationalMetricsSource to compute rolling-window 5xx error rate and p95 latency from those samples, disk utilization from a configured filesystem path, and queue age from queue lifecycle tracking plus the database queue fallback.
- Successful-backup age is sourced from the modification time of an operator-provisioned marker file. The backup agent must update that marker atomically only after a backup has completed and passed its verification checks. A missing marker remains a missing-backup signal.
- Added php artisan operations:metrics to emit a versioned, redacted JSON snapshot and prune telemetry samples older than 24 hours. It is not scheduled and does not send alerts in this step.
- Added portable migration and feature tests for rolling-window aggregation, p95, API status capture without payload persistence, backup marker timestamps, and telemetry pruning.
- Queue age is now tracked through Laravel queue lifecycle events across Redis and other evented queue backends: dispatch delay is respected, processing/failure clears the sample, and exception retries are re-enqueued using their backoff. The database queue also has a direct fallback for pre-existing jobs. Only jobs enqueued after instrumentation is deployed are tracked on non-database backends; explicit manual releases without an exception are conservatively treated as immediately available.
- This is application-side metric collection, not an independent external monitor. Alert scheduling and backup/restore tooling are implemented; security-review readiness remains the next roadmap step.
- Exact-head run #43597533823 passed all six CI jobs on `d0fcf3f246fdd37d531068cde6cdfcb913e497d6`; general CI reported **198 passed, 3 skipped, 891 assertions**. No production marker, external queue provider, backup credential, or alert destination has been configured.

## Alert scheduling and notification adapters — complete (production gated)

- Added the operations:check-alerts command to evaluate the approved metric policy and manage active alert state.
- Added an idempotent alert-state table with active/inactive lifecycle, delivery claim leases, first/last observation timestamps, last successful notification timestamp, and notification counts.
- Added email, Slack, and Discord delivery adapters. Critical alerts additionally use configured WhatsApp/SMS HTTPS webhook bridges; each configured critical route must acknowledge with a 2xx response.
- Delivery requires a valid owner email and exactly one configured primary team channel. Critical alerts additionally require at least one WhatsApp/SMS route. Missing routes and non-2xx provider responses remain failures and are retried.
- Added 15-minute default repeat suppression, retry after incomplete delivery, and one-minute scheduler evaluation with withoutOverlapping. The schedule is disabled by default and requires OPERATIONS_ALERTS_ENABLED=true after secret-manager configuration and non-production delivery tests.
- Added feature tests for standard/critical routing, provider acknowledgement, deduplication, retry, and alert reactivation.
- Provider-specific WhatsApp/SMS bridge provisioning and real destination credentials remain owner-controlled. Recovery notifications are not included in this phase.
- Exact-head run #43598332919 passed all six CI jobs on `703772cc8def8f45d394ef53fc80ca582c3dae68`; general CI reported **203 passed, 3 skipped, 915 assertions**.

## Outstanding owner-controlled gates

- Wasabi account creation and billing approval; bucket provisioning and actual Object Lock COMPLIANCE read-back.
- Approve the backup Object Lock retention period in days; this value is mandatory and has no assumed default.
- Provide production Ed25519 key custody and secure public-key references/fingerprints.
- Provide secret-manager access and dedicated backup read-only / restore migration DB credentials, backup encryption key, and alert destination secrets.
- Provide isolated backup rehearsal access; no production restore has been performed.
- Approve AI provider pricing and the price card.
- Complete the independent security review and provide release authorization.
- Keep PR #2 Draft; do not merge or deploy without explicit separate authorization.

## Queue-age metric coverage follow-up

- Added a queue-job sample table and lifecycle event tracking for Laravel JobQueued, JobProcessing, JobReleasedAfterException, JobProcessed, and JobFailed events.
- Delayed dispatches are not counted until their available_at time; jobs are removed from the waiting metric when processing starts, and exception retries are re-tracked with their backoff.
- This closes the default Redis queue coverage gap for newly enqueued jobs. The database queue retains a direct query fallback for existing rows.
- No queue payload, job arguments, or customer data are stored; only connection name, queue name, job ID, and availability timestamp are recorded.
- Non-database backlog already present before this instrumentation is deployed is not backfilled. Explicit manual releases without exception metadata are tracked as immediately available.
- Validation is pending on the exact queue-tracking commit.

## Backup/restore tooling and rehearsal runbook — implementation complete, external rehearsal pending

- Implemented PostgreSQL custom-format dumps using the dedicated read-only backup role and restore using the dedicated migration role; runtime database credentials are not reused for either operation.
- Encrypted archives use libsodium XChaCha20-Poly1305 secretstream with authenticated chunk framing and SHA-256 digests for both plaintext and ciphertext.
- Wasabi S3-compatible upload requires HTTPS, configured credentials, bucket versioning, Object Lock enabled, and an explicit positive owner-approved retention period. Each archive and manifest upload requests per-object Object Lock COMPLIANCE retention and SSE-S3 AES256, then verifies lock mode, retain-until date, byte count, and SHA-256 metadata.
- Backup creation performs a full object read-back checksum before atomically updating the success marker. A failed or incomplete operation does not update the marker. The current single-PutObject implementation fails closed above 5 GiB; larger databases require a multipart Object Lock adapter.
- Added verification-only restore (operations:backup:restore UUID) that checks manifest integrity, the archive object's Object Lock metadata, encrypted and decrypted SHA-256, authenticated decryption, and pg_restore --list without changing a database.
- Destructive restore requires --execute, an interactive confirmation, a fresh verified pre-restore backup, and an immutable approval record. Production additionally requires --approved-by matching OPERATIONS_OWNER_EMAIL, an approval reference, and fresh owner TOTP. Restore refuses a manifest database name that does not match the configured PostgreSQL target.
- Added cipher tests for round-trip confidentiality/integrity, tamper rejection, and incorrect-key rejection; added a manager test for fail-closed Wasabi configuration and a restore-target identity mismatch test.
- Added a detailed isolated rehearsal runbook covering Wasabi Object Lock evidence, RPO 24h / RTO 4h measurement, restore isolation, PostgreSQL role separation, audit-chain and external checkpoint verification, legal holds, retention checks, and evidence/exit criteria.
- Daily scheduling exists but remains disabled unless OPERATIONS_BACKUPS_ENABLED=true. Do not enable until Wasabi account/billing, bucket configuration, approved backup retention days, secret-manager access, encryption key, dedicated DB credentials, marker path, and isolated rehearsal access are available.
- Scope limitation: this tool backs up the PostgreSQL application database only; WHMCS/MariaDB, uploaded files, and external provider state require separately designed backup coverage. The 5 GiB single-PutObject limit is a hard fail-closed boundary.
- No backup or restore was executed against a real database or Wasabi bucket. Production restore remains explicitly gated by Shahrukh's incident-specific approval.
- The base backup/restore implementation passed exact-head run #43599455622 (206 passed, 3 skipped, 921 assertions); subsequent queue-tracking and restore-target identity changes are pending their own exact-head CI run.
