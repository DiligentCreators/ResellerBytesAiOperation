# Release-readiness progress — 2026-11-14

## Live branch verification

- Repository: `DiligentCreators/ResellerBytesAiOperation`
- Pull request: [#2 — Release readiness](https://github.com/DiligentCreators/ResellerBytesAiOperation/pull/2)
- Branch: `work/release-readiness-audit`
- Latest reviewed head: `b6a77893a3836c1ec7f8763b255a116a6413fbf2`
- PR remains open and Draft. No merge, production migration, deployment, or live
  WHMCSchange has been performed.

## Previously verified CI baseline

GitHub Actions run [43506527149](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43506527149) passed on the earlier PR head `4d461b08a1a3f95890acbf078e88a394dd36d674`. All four jobs were
individually confirmed successful:

- General CI
- PostgreSQL AI-budget and audit-chain concurrency
- MySQL 8.4 integration-retention trigger
- WHMCS addon PHP 8.1 compatibility

This run does not verify the later SQL Server/MariaDB additions in this branch.

## Changes added after that baseline

- Added an isolated SQL Server 2022 integration script that creates a minimal
  testtable, invokes the production WHMCS integration-retention migration,
  verifiesquery-builder DELETE rejection and row preservation, then removes its
  testtrigger/table.
- Added a SQL Server 2022 GitHub Actions service job using the Microsoft SQL
  ServerPHP drivers.
- Added a MariaDB 11.4 GitHub Actions service job and expanded the existing
  MySQLretention test so it also accepts the MariaDB PDO driver.
- Exposed explicit SQL Server TLS encryption and trust-server-certificate
  configurationthrough environment settings; CI enables encryption and trusts
  onlythe ephemeral self-signed service certificate.
- Updated the retention policy and production-pilot checklist to identify MySQL
  8.4,MariaDB 11.4, and SQL Server 2022 engine-specific CI gates.
- Retention reporting now calculates its threshold from an explicit UTC clock
  andhas an inclusive-cutoff boundary test. It remains report-only. No
  diagnostic,finding, recommendation, integration, or audit data is deleted by
  thesechanges.
- The standalone audit integrity verifier now locks the chain-head row inside
  itsread transaction so concurrent appends cannot produce a false mismatch
  betweenthe event cursor and the stored head. Added empty-chain verification
  coverage.

## Independent review preparation

- Added `docs/operations/independent-security-review-checklist.md` with explicit test coverage for authentication/TOTP,
  authorization/IDOR,WHMCS ingestion and addon boundaries, AI spend controls,
  auditintegrity, database privilege separation, retention/backups,
  infrastructure,and mandatory no-go findings. It is a preparation checklist,
  notan independent security sign-off.
- Deployment and pilot documentation now require confirmed TOTP; passkeys are
  supplementary,consistent with the application access gate.
- `.env.example` now documents verified PostgreSQL TLS (`DB_SSLMODE=verify-full`) and secure optional SQL Server
  defaults(`DB_ENCRYPT=yes`, `DB_TRUST_SERVER_CERTIFICATE=false`). The deployment runbook requires PostgreSQL certificate and
  hostnameverification.

## Verification status of this change batch

- Latest branch head: `7184d09429cc8522310cd94cfe4e30355e7c5d96`.
- Run [43514242797](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43514242797) (#495) exercised the new matrix on the earlier PR merge SHA:
  PostgreSQL,MySQL 8.4, MariaDB 11.4, and WHMCS PHP 8.1 passed; SQL Server setup
  failedbecause that job used PHP 8.4 while the lock file requires the project
  PHP8.5 runtime; general CI failed Pint on one extra blank line and one missing
  global-classimport. Those three causes have been corrected in subsequent
  commits(SQL Server now uses PHP 8.5; both Pint findings fixed). This is not
  yeta passing rerun.
- Run [43514522003](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43514522003) (#502) passed all six jobs on code head `238ba1f7f8fb4b09d0ffd8913e4e7bba308ebb42`. General CI: 145 passed, 3
  skipped,619 assertions; PostgreSQL AI-budget/audit concurrency, MySQL 8.4,
  MariaDB11.4, SQL Server 2022, and WHMCS PHP 8.1 jobs all passed.
- A documentation-only clarification was then committed at latest head `f5c5e47c6365993cf6bff82dfdc44a6fdc631b64`; run
  [43514673907](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43514673907)(#503) is checking that exact head. Five jobs have completed successfully
  sofar; general CI is still running at the time of this update.
- MariaDB 11.4 passed its dedicated test in run #495. SQL Server 2022 and the
  generalCI suite need a rerun on the latest head after the fixes above; no
  latest-headgreen run has been confirmed yet. The latest PR SHA currently has
  noassociated workflow run returned by GitHub; CI execution is still pending.
- SQL Server coverage is a focused trigger integration check, not a full Laravel
  migration-suitecompatibility claim for SQL Server.
- No production credentials or external cloud account are required for these
  repositorychanges.

## Remaining work

1. Verify all six CI jobs on the exact latest branch SHA; fix any failures and
   rerununtil green.
2. Continue release checklist, security review preparation, and negative
   privilege/testcoverage without enabling destructive retention.
3. Keep only the seven owner-only inputs in the `Pending for Shahrukh` list below; approved
   operatingthresholds, routing policy, recovery objectives, retention target,
   signingoperator, and PostgreSQL role design are decisions already made.
4. Keep PR #2 Draft. Do not merge, deploy, or run production migrations.

## Shahrukh-approved operational decisions — 2026-11-14

The following decisions are approved and are now implementation requirements,
not owner-pending decisions:

- Monitoring: error rate greater than 2% over 5 minutes; API p95 latency greater
  than1.5 seconds; no successful backup for 26 hours; disk at 80% warning and
  90%critical; queue jobs older than 15 minutes alert.
- Routing: owner email plus one Slack or Discord channel; critical notifications
  additionallythrough WhatsApp/SMS. All destinations and credentials are runtime
  environment/secret-managerconfiguration; no real endpoints or tokens belong in
  Git.
- Recovery: RPO 24 hours, RTO 4 hours; every production restore requires
  Shahrukh'sexplicit approval.
- External audit retention: 365 days with Object Lock COMPLIANCE mode.
- Signing and verifier operations: Shahrukh is the sole initial operator;
  operatoridentity model must permit a second trusted operator later.
- PostgreSQL: separate runtime (read/write, no DDL), deploy-only migration
  (DDL),and read-only reporting (SELECT only on an explicit approved allowlist)
  roles.

The config and runbooks record these approved values. Alert delivery, provider
adapters, storage provisioning, actual PostgreSQL production roles, and
production recovery remain unprovisioned until their external dependencies are
supplied.

## Latest branch validation and implementation batch

- Exact commit `e55c0e99b0523bacd062e54474f9e189ddfcae8b` passed all six required CI jobs in run [#43578328332](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43578328332).
- Applied approved monitoring thresholds, notification destination
  configuration,recovery objectives/approval gate, 365-day COMPLIANCE audit
  retention,and initial single-operator configuration to `config/operations.php` and `.env.example`.
- Updated monitoring and backup/restore runbooks with the approved policy.
- Expanded PostgreSQL role guidance to three distinct application identities.
  Reportingaccess is explicitly allowlisted rather than broad schema-wide
  SELECT.
- Added a PostgreSQL CI regression script for the read-only reporting identity;
  exact-headCI passed on `cff03dd7264138929753101d7b446f0d3a88df1f`.
- PR #2 remains open and Draft. No merge, deploy, production migration, or live
  WHMCSchange has occurred.

## Pending for Shahrukh — owner-only inputs/actions

Do not mark these complete until Shahrukh provides or authorizes them:

1. Create and fund the separate cloud/security account and approve its billing.
2. Generate and retain custody of the production Ed25519 signing/anchor keys;
   provideonly non-secret public-key references/fingerprints through an approved
   securechannel.
3. Provide approved secret-manager access and access policy.
4. Verify actual AI provider/model pricing and approve the production price-card
   versionand expiry.
5. Provide approved backup/restore rehearsal access and an isolated recovery
   environment.
6. Arrange and complete the independent security review against the exact
   reviewedSHA.
7. Provide final release authorization.

Approved monitoring, alert-routing, recovery, retention, signing-operator, and
PostgreSQL role decisions are not pending. No production resource, secret,
provider pricing approval, backup access, independent review, or release
authorization is inferred from the repository changes.

## Exact-head CI confirmation — 2026-11-14

- Commit `cff03dd7264138929753101d7b446f0d3a88df1f` passed all six required jobs in [run #43580604544](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43580604544).
- General CI: **190 passed, 866 assertions**.
- PostgreSQL CI verified the runtime identity's append-only audit and no-DDL
  restrictions,plus a read-only reporting identity that can SELECT only from an
  explicitlygranted reporting table and is denied unapproved audit reads,
  writes,migration-ledger reads, and DDL.
- MySQL 8.4, MariaDB 11.4, SQL Server 2022, and WHMCS PHP 8.1 compatibility jobs
  allpassed.
- PR #2 remains open and Draft; no merge, deployment, production migration, or
  liveWHMCS operation occurred.

## Approved monitoring threshold evaluator

- Added `app/Services/Operations/AlertThresholdPolicy.php` to evaluate the approved error-rate, API p95, backup age, disk
  utilization,and queue-age thresholds from normalized metric inputs.
- Added boundary tests for strict error/latency/queue thresholds, disk
  warning/criticalescalation, the 26-hour backup limit, and
  missing/invalid/futurebackup timestamps.
- Metric collection and alert delivery are implemented in the subsequent
  sections.Production delivery remains gated by owner-controlled destinations,
  credentials,and non-production verification.
- Exact-head run [#43581384457](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43581384457) passed all six jobs on `d8cb3620fcf36a2ffa7582aa2c74bafa1b4eb90a`; general CI reported **195
  passed,878 assertions**.

## Final exact-head CI — 2026-11-14

- Exact PR head `d8cb3620fcf36a2ffa7582aa2c74bafa1b4eb90a` passed all six jobs in [run #43581384457](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43581384457).
- General CI: **195 passed, 878 assertions**.
- PostgreSQL runtime and read-only reporting role checks passed, including
  explicitreporting allowlist enforcement.
- MySQL 8.4, MariaDB 11.4, SQL Server 2022, and WHMCS PHP 8.1 jobs passed.
- PR #2 remains open and Draft. No merge, deployment, production migration, or
  liveWHMCS change has been performed.

## Metric-source integration — initial implementation

- Added API middleware that records response status and elapsed time into a
  dedicatedoperations_metric_samples table. It stores no request body, URL
  parameters,customer identifiers, or response content; telemetry write failures
  arelogged by exception class and never fail the API request.
- Added OperationalMetricsSource to compute rolling-window 5xx error rate and
  p95latency from those samples, disk utilization from a configured filesystem
  path,and queue age from queue lifecycle tracking plus the database queue
  fallback.
- Successful-backup age is sourced from the modification time of an
  operator-provisionedmarker file. The backup agent must update that marker
  atomicallyonly after a backup has completed and passed its verification
  checks.A missing marker remains a missing-backup signal.
- Added php artisan operations:metrics to emit a versioned, redacted JSON
  snapshotand prune telemetry samples older than 24 hours. It is not scheduled
  anddoes not send alerts in this step.
- Added portable migration and feature tests for rolling-window aggregation,
  p95,API status capture without payload persistence, backup marker timestamps,
  andtelemetry pruning.
- Queue age is now tracked through Laravel queue lifecycle events across Redis
  andother evented queue backends: dispatch delay is respected,
  processing/failureclears the sample, and exception retries are re-enqueued
  usingtheir backoff. The database queue also has a direct fallback for
  pre-existingjobs. Only jobs enqueued after instrumentation is deployed are
  trackedon non-database backends; explicit manual releases without an exception
  areconservatively treated as immediately available.
- This is application-side metric collection, not an independent external
  monitor.Alert scheduling and backup/restore tooling are implemented;
  security-reviewreadiness remains the next roadmap step.
- Exact-head run #43597533823 passed all six CI jobs on `d0fcf3f246fdd37d531068cde6cdfcb913e497d6`; general CI reported
  **198passed, 3 skipped, 891 assertions**. No production marker, external queue
  provider,backup credential, or alert destination has been configured.

## Alert scheduling and notification adapters — complete (production gated)

- Added the operations:check-alerts command to evaluate the approved metric
  policyand manage active alert state.
- Added an idempotent alert-state table with active/inactive lifecycle, delivery
  claimleases, first/last observation timestamps, last successful notification
  timestamp,and notification counts.
- Added email, Slack, and Discord delivery adapters. Critical alerts
  additionallyuse configured WhatsApp/SMS HTTPS webhook bridges; each configured
  criticalroute must acknowledge with a 2xx response.
- Delivery requires a valid owner email and exactly one configured primary team
  channel.Critical alerts additionally require at least one WhatsApp/SMS route.
  Missingroutes and non-2xx provider responses remain failures and are retried.
- Added 15-minute default repeat suppression, retry after incomplete delivery,
  andone-minute scheduler evaluation with withoutOverlapping. The schedule is
  disabledby default and requires OPERATIONS_ALERTS_ENABLED=true after
  secret-managerconfiguration and non-production delivery tests.
- Added feature tests for standard/critical routing, provider acknowledgement,
  deduplication,retry, and alert reactivation.
- Provider-specific WhatsApp/SMS bridge provisioning and real destination
  credentialsremain owner-controlled. Recovery notifications are not included in
  thisphase.
- Exact-head run #43598332919 passed all six CI jobs on `703772cc8def8f45d394ef53fc80ca582c3dae68`; general CI reported
  **203passed, 3 skipped, 915 assertions**.

## Outstanding owner-controlled gates

- Wasabi account creation and billing approval; bucket provisioning and actual
  ObjectLock COMPLIANCE read-back.
- Approve the backup Object Lock retention period in days; this value is
  mandatoryand has no assumed default.
- Provide production Ed25519 key custody and secure public-key
  references/fingerprints.
- Provide secret-manager access and dedicated backup read-only / restore
  migrationDB credentials, backup encryption key, and alert destination secrets.
- Provide isolated backup rehearsal access; no production restore has been
  performed.
- Approve AI provider pricing and the price card.
- Complete the independent security review and provide release authorization.
- Keep PR #2 Draft; do not merge or deploy without explicit separate
  authorization.

## Queue-age metric coverage follow-up

- Added a queue-job sample table and lifecycle event tracking for Laravel
  JobQueued,JobProcessing, JobReleasedAfterException, JobProcessed, and
  JobFailedevents.
- Delayed dispatches are not counted until their available_at time; jobs are
  removedfrom the waiting metric when processing starts, and exception retries
  arere-tracked with their backoff.
- This closes the default Redis queue coverage gap for newly enqueued jobs. The
  databasequeue retains a direct query fallback for existing rows.
- No queue payload, job arguments, or customer data are stored; only connection
  name,queue name, job ID, and availability timestamp are recorded.
- Non-database backlog already present before this instrumentation is deployed
  isnot backfilled. Explicit manual releases without exception metadata are
  trackedas immediately available.
- Validation is pending on the exact queue-tracking commit.

## Backup/restore tooling and rehearsal runbook — implementation complete, external rehearsal pending

- Implemented PostgreSQL custom-format dumps using the dedicated read-only
  backuprole and restore using the dedicated migration role; runtime database
  credentialsare not reused for either operation.
- Encrypted archives use libsodium XChaCha20-Poly1305 secretstream with
  authenticatedchunk framing and SHA-256 digests for both plaintext and
  ciphertext.
- Wasabi S3-compatible upload requires HTTPS, configured credentials, bucket
  versioning,Object Lock enabled, and an explicit positive owner-approved
  retentionperiod. Each archive and manifest upload requests per-object Object
  LockCOMPLIANCE retention and SSE-S3 AES256, then verifies lock mode,
  retain-untildate, byte count, and SHA-256 metadata.
- Backup creation performs a full object read-back checksum before atomically
  updatingthe success marker. A failed or incomplete operation does not update
  themarker. The current single-PutObject implementation fails closed above 5
  GiB;larger databases require a multipart Object Lock adapter.
- Added verification-only restore (operations:backup:restore UUID) that checks
  manifestintegrity, the archive object's Object Lock metadata, encrypted and
  decryptedSHA-256, authenticated decryption, and pg_restore --list without
  changinga database.
- Destructive restore requires --execute, an interactive confirmation, a fresh
  verifiedpre-restore backup, and an immutable approval record. Production
  additionallyrequires --approved-by matching OPERATIONS_OWNER_EMAIL, an
  approvalreference, and fresh owner TOTP. Restore refuses a manifest database
  namethat does not match the configured PostgreSQL target.
- Added cipher tests for round-trip confidentiality/integrity, tamper rejection,
  andincorrect-key rejection; added a manager test for fail-closed Wasabi
  configurationand a restore-target identity mismatch test.
- Added a detailed isolated rehearsal runbook covering Wasabi Object Lock
  evidence,RPO 24h / RTO 4h measurement, restore isolation, PostgreSQL role
  separation,audit-chain and external checkpoint verification, legal holds,
  retentionchecks, and evidence/exit criteria.
- Daily scheduling exists but remains disabled unless
  OPERATIONS_BACKUPS_ENABLED=true.Do not enable until Wasabi account/billing,
  bucketconfiguration, approved backup retention days, secret-manager access,
  encryptionkey, dedicated DB credentials, marker path, and isolated rehearsal
  accessare available.
- Scope limitation: this tool backs up the PostgreSQL application database only;
  WHMCS/MariaDB,uploaded files, and external provider state require separately
  designedbackup coverage. The 5 GiB single-PutObject limit is a hard
  fail-closedboundary.
- No backup or restore was executed against a real database or Wasabi bucket.
  Productionrestore remains explicitly gated by Shahrukh's incident-specific
  approval.
- The base backup/restore implementation passed exact-head run #43599455622 (206
  passed,3 skipped, 921 assertions); subsequent queue-tracking and
  restore-targetidentity changes are pending their own exact-head CI run.

## Security-review readiness and retention-policy alignment

- Created docs/operations/security-review-readiness-pack.md as an evidence index
  andreviewer test plan. It explicitly distinguishes repository implementation
  fromreal infrastructure evidence and independent security approval.
- Updated the independent review checklist for current provider-neutral
  checkpointpublishing, alert scheduling, Wasabi backup/restore tooling, and
  owner-controlledexternal provisioning gates.
- Reconciled diagnostic retention implementation with Shahrukh's explicit
  no-exceptiondecision: active legal holds no longer extend diagnostic data
  retention;hold rows transition to expired_by_retention and are audited when
  theirtarget data reaches 365 days. Holds on already-expired targets are
  rejected.The legal-hold status column was widened to 32 characters for the
  explicitstate.
- Corrected the backup single-PutObject maximum to 5 GB (5,000,000,000 bytes),
  not5 GiB.
- Exact-head CI is intentionally deferred until the final one-shot commit. The
  earlierrun #43599729637 was triggered by a concurrent commit without [skip ci]
  andfailed only the general formatting check in the external audit-anchor
  document;five matrix jobs passed. The document has since been updated. No CI
  runwas requested for our skip-ci commits.

## CI-cost policy exception log and backup test correction

- Three concurrent commits without [skip ci] were observed after Shahrukh's
  instruction,triggering extra runs despite this assistant's skip-ci commits:
  #43599729637on 555f92e (general formatting failure in
  external-audit-anchor-monitoring-and-storage.md;five matrix jobs passed),
  #43600418285on a831220 (general CI failed 30 tests after a backup test changed
  database.defaultto an unreachable PostgreSQL host, cascading into SQLite
  transactionerrors; five matrix jobs passed), and #43600581151 on 591aead (in
  progressat this update).
- The current hardening batch adds
  BackupArchiveManager::assertRestoreTargetMatchesas a pure config comparison
  andrewrites BackupArchiveManagerTest to keep the active test database
  connectionunchanged. The external audit-anchor documentation formatting has
  alsobeen normalized.
- No CI was requested for the assistant-authored skip-ci commits. From this
  point,no further CI should run until the one final commit without [skip ci]
  afterall roadmap work is complete.

- Run #43600581151 on concurrent commit 591aead also completed with general CI
  failurecaused by the same test-only `database.default` mutation to `recovery-db.internal`; five matrix jobs
  passed.The current skip-ci hardening commit removes the mutation and adds a
  purerestore-target identity assertion. No additional CI run was triggered for
  commit3a529936c21ab93a0ae85423d11c7fd92f230c3b.

- Additional backup immutability hardening: archive and manifest uploads now
  requireconditional If-None-Match creation and provider-returned VersionIds.
  Theencrypted archive VersionId is recorded in the manifest; Object Lock head
  checksand read-back downloads target that exact version. Restore rejects
  manifestswithout the pinned archive VersionId. Providers that do not support
  conditionalcreate/version IDs fail closed.

- Run #43600781118 on concurrent commit 466be2e completed with five matrix jobs
  passedand general CI formatting failure in the external audit-anchor,
  release-readinessprogress, and security-review readiness Markdown files. Those
  fileshave since been reflowed. No CI run was triggered for the current
  version-pinningcode/documentation changes.

## Static source review follow-up — 2026-11-14

- Backup manifest schema is now version 2 and binds each archive to the
  configuredPostgreSQL database name, host, and port. Restore refuses a missing
  ormismatched endpoint identity instead of relying on database name alone.
- Backup manifest uploads now receive exact-version Object Lock metadata
  verificationand a downloaded exact-VersionId SHA-256 read-back check. Restore
  alsoresolves the downloaded manifest VersionId and verifies its immutable
  metadata/checksumand minimum configured retention before trusting its archive
  reference.
- Operational alert resolution now uses metric availability explicitly. An
  unavailableerror-rate, latency, disk, or queue metric cannot silently clear an
  activealert; a known healthy sample can resolve only the corresponding
  metric-backedalert. Added regression coverage for the unavailable-to-healthy
  transition.
- Corrected the restore rehearsal runbook so it no longer claims legal holds
  preservediagnostic content. It now documents hold-metadata expiry and exact
  databaseendpoint matching. Corrected stale PR description statements:
  diagnosticretention has no exceptions and live audit events are retained
  indefinitely;365-day COMPLIANCE retention applies to external audit-checkpoint
  backupobjects.
- Intermediate commits for this review batch all include [skip ci]. No workflow
  fileor repository setting was changed.
- Exact-head CI has intentionally not been triggered. The project requires PHP
  8.5,while the available local CLI is PHP 8.4 and the repository
  checkout/dependenciesare not mounted; do not treat static review as test
  executionor CI validation.
- PR #2 remains open and Draft. No merge, production migration, deployment, or
  liveWHMCS operation is authorized.

## Static source review follow-up — retention and alert lifecycle

- Closed a retention-policy gap: the cleanup command no longer excludes
  received,queued, or analyzing diagnostic runs at the 365-day UTC cutoff.
  Activelegal-hold metadata is expired and audited before the diagnostic content
  isdeleted.
- Analysis jobs now refuse to start for expired diagnostics, queue recovery
  skipsexpired runs, and the provider pre-submit callback re-locks/rechecks the
  diagnosticrow before marking an AI attempt submitted. If the retention cutoff
  isreached before submission, the reservation is released and no provider
  requestis made.
- Updated regression coverage for inclusive cutoff deletion of active-status
  runs,expired queue-recovery suppression, analysis-start rejection, and
  reservationrelease at the pre-submit expiry boundary.
- The independent alert review found that missing metrics could incorrectly
  clearan existing alert. Alert resolution now requires a known/evaluated
  metric;unavailable error-rate, latency, disk, or queue data preserves the
  activestate. Known healthy samples resolve the corresponding alert. The
  active-alertcount reflects persisted states, and policy/state regression tests
  coverthe behavior.
- Backup/restore hardening remains in place: manifest v2 binds database
  name/host/port;exact manifest and archive VersionIds are verified; manifest
  bytesreceive an exact-version SHA-256 read-back; restore refuses endpoint
  mismatches.
- Retention, backup, alert, security-review, and progress documentation were
  reconciled.The PR description now correctly states absolute diagnostic expiry
  withno exceptions and indefinite live audit-event retention.
- Current exact head: b6a77893a3836c1ec7f8763b255a116a6413fbf2. All intermediate
  commitsin this batch include [skip ci]. No workflow file or repository setting
  waschanged. CI has not been triggered for this head by design; full tests and
  exact-headCI remain pending until the final one-shot commit.
- The current assistant environment cannot run the repository test suite: the
  projectrequires PHP 8.5, while only PHP 8.4 is available and the
  checkout/dependenciesare not mounted. Static review is not represented as test
  execution.
- PR #2 remains open and Draft. No merge, production migration, deployment, or
  liveWHMCS operation is authorized.

## Static source review follow-up — external audit-anchor publishing

- Completed the missing production-integration code path (without provisioning
  resources):added an AWS CLI-backed S3-compatible `AuditCheckpointObjectStore`, dependency binding, and
  manual`audit:anchor:publish` command.
- The adapter uses a dedicated audit bucket, separate audit credentials,
  explicitHTTPS endpoint, conditional `If-None-Match: *` create, returned object VersionId,
  exact-versionread-back, and Object Lock COMPLIANCE metadata. It refuses to
  reusethe configured backup bucket and fails closed on missing
  bucket/credentials,invalid endpoint, unsupported conditional creation, missing
  VersionId,or missing retention metadata. Raw AWS CLI diagnostics are
  suppressed.
- Publisher retention deadline is now deterministic from the signed checkpoint
  creationtime plus 365 days, so retrying the same checkpoint cannot silently
  movethe retention target. Expired checkpoint objects cannot be newly
  published.
- Chained checkpoint publication and verification now require the expected
  predecessordigest; the monitor fails closed if a chained checkpoint is checked
  withoutthat digest. Added regression tests for predecessor enforcement, stable
  retention,and dedicated-bucket/HTTPS/credential fail-closed behavior.
- Reconciled audit hash-chain design, external anchor operations guide,
  independentsecurity checklist, and security-review pack to distinguish
  implementedadapter code from unprovisioned production cloud controls.
- Current branch head: `31d0449d01e4f80179db13deb54a5503e6ab3ea4`. All commits in this follow-up use [skip ci]. No
  workflowfile or repository setting was modified in this follow-up. CI remains
  intentionallyuntriggered until the one final commit after all remaining work
  iscomplete.
- Provider-level rehearsal remains pending: no production bucket, credentials,
  keycustody, Object Lock configuration, actual provider read-back, independent
  verifierschedule, or live anchor publication has been attempted. These stay in
  Shahrukh'spending list.
- Full automated tests and exact-head CI have not been run. The available
  environmentstill lacks PHP 8.5 and a mounted repository/dependency checkout;
  staticreview is not represented as test execution.
- PR #2 remains open and Draft. No merge, production migration, deployment, or
  liveWHMCS operation is authorized.

## Additional audit-anchor validation hardening — 2026-11-14

- Registered `audit:anchor:publish` explicitly in Laravel's command list and added a command-level
  regressiontest proving it fails closed before storage access when the
  dedicatedbucket is absent.
- Hardened the S3 adapter: dedicated audit credentials are separate from backup
  credentials;the audit bucket must differ from the configured backup bucket;
  theendpoint must be HTTPS and cannot embed user/password/query/fragment data.
  S3metadata responses must be JSON objects. The adapter's create path captures
  metadatawithout a redundant object download; the publisher performs the single
  authoritativeexact- VersionId read-back.
- Centralized strict UTC calendar timestamp validation in `AuditAnchorService`, so malformed but
  correctlysigned timestamps are rejected by all verification callers, not only
  themonitor/publisher. Added regression coverage for impossible calendar
  timestampsand chained-monitor predecessor omission.
- Latest code head before this progress update: `0d8c73a13d3cdcc6ff8bc2235ddf341eda4312de`. All commits in this batch
  use[skip ci]. Workflow files and repository settings were not changed. No CI
  runhas been triggered.
- Static source review and test additions are continuing; no test result is
  claimed.Full PHP 8.5 suite and final exact-head CI remain pending until the
  finalone-shot commit. Production bucket, credentials, keys, provider
  rehearsal,and independent security review remain Shahrukh-controlled gates.

## Checkpoint publisher registration and final source-review notes

- Verified Laravel's explicit command registry and corrected it to include `PublishAuditAnchorCheckpoint`;
  addeda command-level test for registration and fail-closed behavior when the
  dedicatedaudit bucket is not configured.
- The S3 adapter now rejects credential-bearing endpoint URLs and malformed
  metadataresponses. Publisher output includes environment, object VersionId,
  retentionmode, retain-until UTC, sequence, digest, and ambiguous-create
  reconciliationstatus.
- Publisher rejects signed checkpoint timestamps more than five minutes in the
  future.The central verifier rejects impossible calendar timestamps. Regression
  coveragewas added for both timestamp paths and predecessor enforcement.
- Latest code/test head: `2f73e1428f90c32f24fff4cdef2673fa21e8bbe4`. Intermediate commits continue to include [skip
  ci].No workflow files or repository settings were changed; no CI run has been
  triggered.
- Still pending: PHP 8.5 full test suite, one final CI run after all engineering
  work,actual non-production S3/Wasabi conditional-create and Object Lock
  rehearsal,production key/credential provisioning, independent security review,
  andrelease approval.

## Security-boundary review follow-up — 2026-11-14

- Reviewed the audit-chain append transaction, canonical hash payload,
  PostgreSQLruntime/read-only role tests, WHMCS addon setup/download routes,
  signedintegration-request middleware, nonce replay handling, and integration
  revoke/rotatecommands.
- Audit append uses a locked singleton chain-head row and a conditional
  sequence/hashupdate in the same transaction; PostgreSQL concurrency coverage
  exists.Runtime-role tests exercise append + chain verification while denying
  auditmutation, migration-ledger reads, schema creation, and integration hard
  delete.
- WHMCS operator routes are behind authenticated, verified, confirmed-2FA
  middleware.API ingestion requires a bounded JSON body, strict
  integration/timestamp/nonce/signatureheaders, HMAC verification, and unique
  nonceinsertion; revoked integrations are excluded by the active-status lookup.
- Corrected a malformed PostgreSQL anonymous-block delimiter in the
  role-separationrunbook (DO $$ ... $$;). No workflow file or repository setting
  waschanged.
- Review note: no dedicated HTTP feature test for the WHMCS signed-request
  middlewarewas found in the changed test inventory; add direct
  authentication/replay/revocationcoverage before the final validation pass.
- Latest branch head after this documentation correction:
  54f3d7613f8feafa666ad3cbc46d5f677d55151e.All intermediate commits use [skip
  ci].CI remains deferred until the one final non-skip-ci commit. Full PHP 8.5
  suiteremains pending because the current execution environment lacks PHP 8.5
  andthe repository checkout/dependencies.
- PR #2 remains open and Draft. No merge, deployment, production migration, or
  liveWHMCS operation was performed.

## WHMCS request-authentication regression coverage — 2026-11-14

- Added `tests/Feature/WhmcsIntegrationAuthenticationTest.php` for successful HMAC-authenticated ingestion, duplicate nonce replay
  (409),revoked integration (401), and invalid HMAC (401). The tests use
  generatedephemeral integration secrets and do not call a live WHMCS
  installationor AI provider.
- Tests are committed but not executed in this environment. Validate request
  pathcanonicalization, Laravel test response assertions, and schema
  compatibilityin the final PHP 8.5 CI run.
- Current head: `0dcc3e715fd3145ae294b4419021f03ecf927fc2`. Intermediate commits use [skip ci]. No workflow file or
  repositorysetting was modified; CI remains deferred.

## Follow-up verification checkpoint — 2026-11-14

- Corrected both PostgreSQL anonymous-block delimiters in the role-separation
  runbookto valid `DO $ ... $;` syntax; verified the final file contains no malformed
  single-dollar`DO single-dollar block.
- Completed the WHMCS authentication feature-test helper wiring: the tests bind
  tothe Pest/Laravel test case and exercise the actual API route. Source-level
  inspectionconfirms all helper invocations pass the test case.
- Compare from the prior checkpoint `fcdf05e528bf0cd4f2d2a943e16f81093df6802e` shows only the PostgreSQL role runbook,
  thisprogress log, and the new WHMCS authentication feature test changed. No
  workflowfiles are in this follow-up diff.
- Latest code head: `7bc3039d394e3cc2d5f8a8c933b86d025f9c5c4b`. Exact-head workflow runs: none (all intermediate
  commitsuse [skip ci]). No full tests have been executed; PHP 8.5 and the
  mountedrepository/dependency environment are unavailable here.
- PR #2 remains open and Draft, not merged. No deployment, production migration,
  liveWHMCS call, production credential use, or repository-setting change
  occurred.

## WHMCS nonce lifecycle coverage — 2026-11-14

- Reviewed the scheduled `whmcs:nonces:prune` command and its unique `(integration_id, nonce)` constraint. The command
  deletesexpired records in batches of 1,000 and caps each run at 10,000
  deletions;the scheduler runs it every 15 minutes with overlap protection.
- Added `tests/Feature/PruneWhmcsIntegrationNoncesTest.php` to prove expired nonces are pruned while active replay guards
  remain.Test is committed but not executed.
- The signed-request feature tests cover valid HMAC ingestion, replay rejection,
  revokedintegration rejection, and invalid signatures. All test helpers are
  wiredto the Laravel test case; execution remains pending.
- Latest head at this checkpoint: `63a0bbca7c438e571da7cb898a2fa02b121b8966`. Every intermediate commit uses [skip ci];
  noworkflow files or repository settings changed.

## Confirmed AI usage settlement on provider error responses — 2026-11-14

- Updated the provider-response boundary to inspect authoritative usage metadata
  beforeclassifying a non-2xx HTTP response as a request failure. When
  input/outputtoken usage is present, the existing transactional ledger
  settlementcallback runs first; the diagnostic still fails and does not persist
  findingsfrom the unsuccessful response.
- Responses without confirmed usage do not settle and continue through the
  existingsubmitted-to-ambiguous fail-closed path, preserving the reservation
  andpreventing an automatic duplicate attempt.
- Added an HTTP-faked regression test proving a 500 response carrying usage
  invokessettlement with input, cached-input, output, and total token counts
  beforeraising the safe `provider_request_failed` code. Existing timeout coverage continues to
  exerciseambiguous reservation handling.
- Tests are committed but not executed in this environment: PHP 8.5 and the
  repositorycheckout/dependencies are unavailable. No live provider call or
  credentialuse occurred.
- Every commit in this follow-up includes [skip ci]. No workflow file or
  repositorysetting was changed. Exact-head CI remains deferred until all
  roadmapengineering work is complete.
- Latest branch head at this checkpoint: `3ef0056bb0c0cf39fb8d691fb48b6fa9578a659e`. PR #2 remains open and Draft; no
  merge,deployment, production migration, or live WHMCS operation is authorized.

## S3-compatible audit-anchor adapter protocol coverage — 2026-11-14

- Added an isolated fake AWS CLI executable test for the S3 adapter's successful
  protocolpath: conditional immutable create, returned VersionId, COMPLIANCE
  retentionmetadata, exact-VersionId object read, and returned content. The fake
  clientperforms no network access and uses only temporary test files.
- This complements the existing fail-closed configuration tests for dedicated
  bucketseparation, HTTPS, credentials, and unsafe object keys. It does not
  proveWasabi service compatibility; the non-production Wasabi Object Lock
  rehearsalremains an owner/infrastructure gate.
- The test is committed but not executed locally because the required PHP 8.5
  runtimeand mounted repository/dependencies are unavailable. CI remains
  deferreduntil the final one-shot non-skip-ci commit.
- All commits continue to include [skip ci]; no workflow file or repository
  settingwas changed. PR #2 remains open and Draft, with no merge, deployment,
  productionmigration, or live storage operation authorized.

## Operational metric sample lifecycle scheduling — 2026-11-14

- Found that `operations:metrics` already pruned samples older than one day but was not
  registeredwith the scheduler. Added a non-overlapping hourly schedule so the
  operationalmetric table has a bounded retention lifecycle; alert evaluation
  continuesto take its own current snapshot each minute.
- Added a scheduler regression test that asserts the metric maintenance command
  isregistered. No alert destination, schedule enablement gate, production
  endpoint,or repository workflow was changed; the metric maintenance task is
  safehousekeeping and does not send notifications.
- Tests remain committed but unexecuted pending the required PHP 8.5 environment
  andexact-head CI. All commits in this batch include [skip ci].

## Combined follow-up checkpoint — 2026-11-14

- Code/test changes in this continuation: confirmed AI usage settlement on
  unsuccessfulprovider responses (`611276e`, `3ef0056`); fake-CLI exact-VersionId S3 adapter
  test(`ebc5944`); hourly metric sample maintenance schedule and scheduler regression
  test(`e6e15eb`, `58bd6ff`).
- Latest code/test commit at this checkpoint: `58bd6ff7570af5c73438accc19a7ba3b9bd85e38`. The current progress-log
  updateis a separate documentation commit. Every commit message includes [skip
  ci].
- Local PHP CLI is 8.4.24, while this repository requires PHP 8.5; repository
  checkoutand Composer dependencies are not mounted. No local PHP tests or lint
  areclaimed. Exact-head CI remains intentionally deferred until all remaining
  engineeringwork is complete.
- Owner-only pending items remain unchanged: Wasabi account/bucket and
  non-productionObject Lock rehearsal, signing keys, secret manager and three
  PostgreSQLroles provisioning, backup rehearsal access, AI price-card approval,
  independentsecurity review, and final release authorization.
- PR #2 remains open and Draft. No merge to `main`, deployment, production
  migration,live WHMCS operation, or production storage operation occurred.

## Backup freshness telemetry integrity — 2026-11-14

- Static review found that backup-age monitoring used the marker file's
  filesystemmodification time rather than the `created_at` timestamp written by the
  verifiedbackup operation. Copying/restoring the marker file could therefore
  makean old backup appear newer than it is.
- Updated `OperationalMetricsSource` to parse the atomic JSON marker and require a valid UUID backup
  ID,canonical UTC timestamp, real calendar value, and timestamp no more than
  fiveminutes ahead of the observation time. Invalid, malformed, unreadable, or
  implausiblyfuture markers now return unavailable, which keeps the backup-age
  alertfail-closed.
- Added regression coverage proving marker content—not mtime—determines backup
  freshness,and malformed UUID, impossible calendar date, and future timestamp
  aretreated as unavailable. Existing hourly metric pruning schedule coverage
  remainsintact.
- Commits for this change: `9ef3526`, `f676b07`, `8f38006`, `f2a8e01`. All include [skip ci]. No
  workflowfile or repository setting was changed. Tests are committed but not
  executedhere; PHP 8.5 and the mounted project/dependencies are still required
  forfull validation.
- PR #2 remains open and Draft. No merge, deployment, production migration, live
  backup,or WHMCS operation was performed.

## Provider usage settlement input hardening — 2026-11-14

- Static review found provider-reported token counts were cast/clamped rather
  thanstrictly validated before integer cost calculation. Malformed fractional,
  negative,string, or extreme counts could cause inconsistent telemetry or
  arithmeticoverflow during settlement.
- Settlement now accepts only non-negative integer usage counts, rejects
  cached-inputcounts above total input, validates aggregate token totals, and
  usesoverflow-checked integer multiplication/addition for micro-USD cost
  calculation.
- Invalid usage fails closed and leaves the submitted reservation held; it does
  notrelease budget or mark the provider attempt settled. Operator
  reconciliationremains the only route to release ambiguous no-charge
  reservations.
- Added regression coverage for negative, fractional, string, inconsistent
  cached-input,and overflow usage metadata.
- Intermediate commits all include [skip ci]. No workflow file or repository
  settingchanged. Tests have not been executed in this environment; exact-head
  CIis deferred to the single final non-[skip ci] commit after all engineering
  workis complete.

## Operational webhook redirect hardening — 2026-11-14

- Static review found outbound alert webhooks validated the initial URL as HTTPS
  butdid not explicitly disable HTTP-client redirect following. Redirect
  forwardingcould send alert payloads to an unintended second destination.
- Webhook requests now refuse redirects and treat 3xx responses as failed
  delivery,preserving the existing retry behavior and avoiding forwarding
  payloadsto redirect targets.
- Added a regression test asserting that a redirect is not followed and the
  alertis not marked delivered; updated the operational delivery contract.
- Tests remain unexecuted in this environment. Commit messages continue to
  include[skip ci]; no workflow or repository settings were changed.

## Restore approval evidence write integrity — 2026-11-14

- Static review of the destructive restore path found the immutable
  approval-evidencewriter did not check the byte count returned by
  file_put_contentsor explicitly restrict the temporary record file to
  owner-onlypermissions.
- It now requires the complete JSON payload to be written under an exclusive
  filelock and applies mode 0600 before hashing and uploading the evidence. A
  short/failedwrite or permission failure aborts before the restore proceeds.
- This is a fail-closed integrity hardening; no production restore or live
  storageoperation was performed.

## Queue and retention completion-boundary review — 2026-11-14

- Static review confirmed queue recovery already uses bounded batches,
  stale-workerleases, and AI-ledger reconciliation: pre-submit reservations are
  released,submitted-but-unresolved attempts become ambiguous, and settled
  attemptsare not charged again.
- Found a remaining retention race: a provider call could begin before the
  365-daycutoff, return after expiry, and still persist analysis output because
  thefinal persistence transaction did not recheck the retention boundary.
- Added a row-locked completion-time retention check. Expired runs are marked
  withthe safe `diagnostic_retention_expired` code and provider output/findings are not persisted. Added a
  regressiontest that advances the source record past expiry after provider
  usagesettlement and verifies the result is discarded.
- Tenant-isolation review also identified a release blocker: integration records
  haveno owner/tenant foreign key, and dashboard, integration, diagnostic-run,
  andfinding queries are global across authenticated users. Ownership cannot be
  safelyassigned to existing integrations until the authorized owner account and
  tenantmodel are provisioned; this is tracked for architectural implementation
  ratherthan silently exposing or guessing ownership.
- All engineering commits in this continuation use [skip ci]. No workflow file
  orrepository setting was changed. Tests remain unexecuted here; PHP 8.5 and
  themounted project/dependencies are required. Exact-head CI remains deferred
  untilthe single final non-skip-ci commit.
- PR #2 remains open and Draft. No merge, deployment, production migration, or
  liveWHMCS operation was performed.

## Account ownership and tenant-isolation foundation — 2026-11-15

- Added nullable `whmcs_integrations.owner_user_id` FK with owner/status index. Existing integrations are
  intentionallyleft unassigned; no owner was guessed or bulk-backfilled.
- Integration provisioning now requires an explicit owner email for an existing
  email-verifieduser with confirmed TOTP MFA. Added audited `whmcs:integration:assign-owner` for one-time
  assignmentof legacy unowned integrations; it refuses to reassign an
  already-ownedintegration.
- Scoped dashboard counts/latest runs, integration lists, diagnostic
  lists/details/reanalysis,and finding lists/details to the authenticated
  integrationowner. Cross-owner detail requests return 404.
- Added regression tests for cross-owner list isolation, dashboard aggregate
  isolation,detail/IDOR rejection, reanalysis rejection, explicit provisioning
  ownership,and legacy assignment/no-reassignment.
- This is an account-ownership baseline, not completion of approved
  organization-levelSaaS tenancy. Still required: tenant/workspace and
  membershipmodel, roles/permission matrix, tenant attribution across AI
  usage/billing/audit/notifications,queued-job tenant context and revalidation,
  exports/downloads,tenant-aware uniqueness/constraints, safe existing-data
  mapping,and evaluation of PostgreSQL RLS. External onboarding remains
  disabled.
- Legacy integration ownership requires Shahrukh's explicit mapping decision.
  Theyremain unassigned and invisible in user-scoped dashboards until assigned
  throughthe audited command.
- All commits carry [skip ci]. No workflow/repository setting changes. Tests
  havenot been executed; PHP 8.5 and project dependencies are required. No
  merge,deployment, production migration, or live WHMCS operation performed.

## Owner-bound diagnostic queue execution — 2026-11-15

- `AnalyzeDiagnosticRun` now requires a positive captured `ownerUserId` alongside the run UUID. A worker will
  notstart analysis unless the run's integration still has that exact owner; it
  rechecksownership before provider submission and before persisting results.
- Exception and queue-failure handlers update a diagnostic run only while the
  integrationremains owned by the captured user. Reassignment during a
  queued/runningjob therefore cannot let a stale job mutate the new owner's
  record.
- Snapshot ingestion queues AI analysis only for integrations with an assigned
  owner.Manual reanalysis passes the authenticated user ID. Stale-queue recovery
  joinsand locks the integration ownership record, excludes unassigned legacy
  integrations,and passes the captured owner into the job.
- Added regression coverage for owner changes between enqueue and execution and
  forrecovery ignoring unassigned integrations; updated direct job fixtures to
  carryowners.
- Tests are authored but not executed. PHP 8.5 and project dependencies are
  unavailablein this environment. All commits retain [skip ci]; no workflow
  filesor repository settings were changed. PR #2 remains open and Draft; no
  merge,deployment, migration, or live WHMCS operation was performed.

## Shared-database tenant schema and backfill design — 2026-11-15

- Completed a table-by-table schema and relationship design in `docs/architecture/tenant-schema-and-backfill-design.md` before adding
  tenantmigrations, as required by the approved roadmap.
- Defined organization/workspace tenants, explicit user memberships and
  owner/admin/operator/viewer/billingroles, tenant ownership propagation through
  integrations,diagnostic runs/findings/recommendations, AI usage attempts and
  budgetperiods, audit events, and legal holds.
- Classified users and operational telemetry as platform-scoped; kept the audit
  hashchain global/append-only and indefinite; retained 365-day diagnostic
  expiryand the no-exceptions policy; preserved a platform-wide AI budget cap
  alongsidefuture tenant caps.
- Specified tenant-aware authorization, job context and revalidation, composite
  integrityconstraints, transaction-local PostgreSQL RLS context,
  export/download/notificationboundaries, isolation test matrix, and restartable
  expand/backfill/verify/contractsequence.
- No tenant migration or backfill was run. First tenant slug and explicit
  mappingof legacy integrations are held for Shahrukh's approval; no
  owner/tenantis inferred. External onboarding and payment collection remain
  disabled.
- All commits use [skip ci]. No workflow/repository settings changed. Tests were
  notexecuted. PR #2 remains open and Draft; no merge or deployment.

## Tenant schema implementation foundation — 2026-11-15

- Authored additive migration `2026_11_15_000002_create_tenants_and_memberships.php`: UUID tenants, unique slug,
  provisioning/active/suspended/closedlifecycle, creator FK, membership table
  withtenant/user/inviter restrictive FKs, closed role/status conventions, one
  rowper tenant/user, and supporting indexes.
- Added nullable `whmcs_integrations.tenant_id` FK/index. Existing integrations remain unassigned;
  migrationcontains no default tenant and no guessed backfill.
- Added `Tenant` and `TenantMembership` Eloquent models, `User` tenant/membership relationships, `WhmcsIntegration`
  tenantrelationship, and `TenantAccess::requireMembership` which denies missing/inactive tenants,
  missing/inactivememberships, and insufficient roles by default.
- Added feature tests for explicit membership versus outsider, role
  restrictions,suspended/revoked membership, and nullable legacy integration
  tenantassignment.
- Migration is authored only; it has not been executed against any database. No
  tenantor membership records have been provisioned. Tests have not been
  executedbecause the required PHP 8.5 runtime/dependencies are unavailable.
- Remaining: tenant-scope all domain queries and nested resources; enforce role
  policyacross controllers; tenant attribution and atomic caps for AI
  usage/billing;tenant attribution/hash versioning for immutable audit events;
  tenant-boundjobs, exports, downloads, notifications; DB integrity/RLS;
  migration/backfillrehearsal and verification.
- All commits retain [skip ci]. No workflows/settings changed. PR #2 stays open
  andDraft; no merge or deployment.

## Tenant application authorization and queue context — 2026-11-15

- Added centralized `TenantRecordQuery`: legacy records with null tenant_id remain visible only
  totheir recorded owner; tenant-assigned records require an active tenant and
  activerecognized membership. Tenant ownership overrides the historical
  integrationowner field.
- Applied membership-aware query scopes to integration lists, diagnostic-run
  lists/details,finding lists/details, and dashboard statistics/latest runs.
  Detailauthorization is checked before nested diagnostic evidence and
  recommendationsare loaded.
- Kept manual reanalysis owner-only because the complete tenant-aware user
  action,role policy, and audit actor/tenant context are not yet complete.
- Analysis jobs now serialize the captured tenant UUID and verify the same
  integrationowner + tenant assignment + active tenant at initial execution,
  immediatelybefore provider submission, and before persisting results. Failure
  updatesare scoped to the captured tenant context.
- Updated stale queue recovery to select active tenant records, carry tenant
  UUIDinto redispatched jobs, and ignore tenant-owned runs for inactive tenants.
  Legacyunassigned recovery remains owner-bound.
- Added feature regression coverage for tenant-member access through
  controllers,former-owner and outsider denial, revoked membership, tenant
  reassignmentduring queue execution, suspended-tenant job rejection, and
  recoverycarrying the correct tenant ID.
- Tests remain unexecuted (PHP 8.5/runtime dependencies unavailable). No
  migrationwas executed and no tenant mapping/backfill was performed.
- Remaining tenant roadmap work: tenant attribution for AI usage/budget periods
  andbilling; audit event tenant attribution with hash-version-safe global
  chain;role-specific writes; tenant-aware reanalysis;
  exports/downloads/webhooks/notifications;composite constraints; PostgreSQL RLS
  andmigration/backfill rehearsal.
- All commits include [skip ci]. No workflows or repo settings changed. PR #2
  remainsopen and Draft; no merge or deployment.

## Tenant AI budgets and audit hash-version checkpoint — 2026-11-15

- Added migration `2026_11_15_000003_add_tenant_scoped_ai_budgets.php`: nullable tenant attribution on AI usage attempts and
  tenantdaily/monthly reserved/committed budget periods.
- AI budget reservation now atomically checks both platform-wide caps and each
  tenant'sdaily/monthly caps. Tenant allowance defaults to the platform cap and
  canbe configured independently through `AI_TENANT_DAILY_BUDGET_MICRO_USD` / `AI_TENANT_MONTHLY_BUDGET_MICRO_USD`. A tenant cannot spend
  anothertenant's allowance; the platform cap remains global.
- Tenant UUID is captured at reservation, stored on the usage attempt and
  immutablethrough model updates. Settlement, release-before-submit and
  dual-approvedaudited no-charge reconciliation update both global and tenant
  ledgersatomically against original UTC period keys.
- Suspended tenant reservations fail closed. Provider-accepted requests still
  settleagainst their captured tenant even if suspension happens after
  submission.
- Added migration `2026_11_15_000004_add_tenant_attribution_to_audit_events.php`. New audit writes use hash version 2 and a separate v2
  domainprefix with tenant UUID in the canonical payload. Existing v1 event
  hashesremain byte-compatible and verifiable; audit chain and external
  checkpointverification accept both versions in the same sequence.
- Tenant context is included in AI reconciliation and integration owner
  assignment,secret rotation and revocation audit events. No existing audit
  eventor usage attempt was backfilled; historical tenant attribution stays null
  pendingexplicit mapping approval.
- Added regression tests for per-tenant daily/monthly budgets, shared global
  cap,cross-tenant allowance isolation, suspended tenant denial, tenant
  immutableusage attribution, tenant-aware audit hashing and v1 hash
  compatibility.Tests remain unexecuted because PHP 8.5/runtime dependencies are
  unavailable.
- Both migrations are authored only; no database migration was executed. No
  tenantdata or historical ledger backfill was performed.
- Remaining roadmap: tenant-aware billing/entitlements; role-specific writes and
  manualreanalysis; tenant-scoped exports/downloads/webhooks/notifications;
  completeaudit-event tenant attribution across all tenant workflows; composite
  constraintsand PostgreSQL RLS; operational migration/backfill rehearsal and
  fulltest/CI verification.
- All commits include [skip ci]. PR #2 remains open and Draft. No
  workflow/repositorysetting changes, merge, CI run, or deployment.

## Tenant subscriptions, entitlements, role gates and billing ledger — 2026-11-15

- Added migration `2026_11_15_000005_create_tenant_billing_entitlements.php` for tenant subscription snapshots, invoices and payments.
  Subscriptionrecords include plan code, manual/automated billing mode, provider
  references,currency/price snapshot, paid period and grace/cancellation
  timestamps.
- Added `TenantEntitlementService`: tenant operations fail closed without an active tenant subscription
  andrequested feature/limit. Tenant AI analysis requires `diagnostics.analysis` before budget
  reservation/providersubmission; tenant manual reanalysis requires `diagnostics.reanalyze`.
- Added capability policy: owner/admin can manage integrations and memberships;
  owner/admin/operatorcan request reanalysis; viewer is read-only; billing role
  canread/manage billing; tenant closure is owner-only.
- Manual tenant reanalysis now rechecks active membership role and subscription
  entitlementinside the request transaction, emits tenant-attributed audit
  events,and dispatches with the authenticated actor ID and immutable tenant
  UUID.Legacy unassigned integrations remain owner-only.
- Added idempotent confirmed-payment ledger for manual and automated payment
  records.It validates tenant/invoice/subscription alignment, currency, invoice
  amountand overpayment, uses a unique idempotency key, and atomically updates
  invoicetotals. Manual entries require a locked active billing-capable
  membership.Payment records emit tenant-attributed audit events. No charges are
  initiatedand no unverified callback is trusted.
- Added tests for active/expired/grace subscriptions, missing feature/limit,
  rolecapability matrix, reanalysis authorization, AI entitlement denial before
  reservation,payment idempotency, cross-tenant invoice access and overpayment
  prevention.Tests authored only, not run.
- Migration is authored only; no migration executed and no subscription,
  invoice,payment, or plan records provisioned. Provider selection, pricing,
  signedwebhook integration, external registration and payment collection remain
  disabledpending Shahrukh approval.
- Remaining: plan catalogue/pricing approval and subscription provisioning
  lifecycle;signed provider webhook verification and reconciliation; entitlement
  limitsacross all resources; role-gated integration/member/settings write
  endpoints;tenant exports/downloads/webhooks/notifications; full audit
  attribution;composite tenant constraints and PostgreSQL RLS;
  migration/backfillrehearsal and full test/CI verification.
- All commits retain [skip ci]. PR #2 remains open and Draft; no
  workflow/settingschanges, CI run, merge or deployment.

## Tenant audit exports and internal notification inbox — 2026-11-15

- Added owner/admin-only tenant audit CSV export with tenant-scoped query,
  optionalUTC date bounds, immutable chain sequence/hash columns, chunked
  streaming,CSV formula-injection neutralization, private no-store headers, rate
  limiting,and an immutable tenant-attributed audit event recording each export
  request.
- Added tenant notification inbox storage and JSON endpoints. Reads and
  mark-readupdates are scoped simultaneously by tenant UUID and authenticated
  recipientuser ID; notification publishing requires an active tenant and active
  membership,bounds payload size, accepts scalar payload values only, and uses a
  uniquededuplication key.
- Successful tenant diagnostic analysis now creates deduplicated in-app
  completionnotifications for active owner/admin/operator/viewer members in the
  samedatabase transaction as the analyzed status. Billing-only members do not
  receivediagnostic notifications. Legacy owner-only diagnostics remain
  unchanged.
- Added tests for audit-export role restrictions/cache headers, notification
  recipientisolation, cross-user mark-read denial, inactive/non-member
  publicationdenial, and notification idempotency. Tests are authored, not
  executed.
- External tenant webhooks and outbound email/SMS/push notifications remain
  disabledpending endpoint ownership verification, destination allowlisting/SSRF
  controls,signed payload key custody, retry/dead-letter policy and Shahrukh's
  provider/secretconfiguration. The new inbox is internal only.
- New migration `2026_11_15_000006_create_tenant_notifications.php` is authored only and has not been executed. Audit export
  usesthe existing tenant-attributed append-only hash chain; no retention
  changeswere made.

## Composite tenant integrity and PostgreSQL RLS checkpoint — 2026-11-15

- Added migration `2026_11_15_000007_enforce_tenant_composite_constraints_and_rls.php`. It adds tenant attribution to diagnostic runs, findings
  andrecommendations, backfills only from already explicit integration-to-tenant
  assignments,and adds composite foreign keys that prevent a diagnostic child,
  billinginvoice/payment, or notification recipient from crossing tenant
  boundaries.
- New diagnostic ingestion persists the integration's captured tenant UUID; AI
  analysispersists the same UUID to findings and recommendations. Existing
  unassignedintegrations and their historical diagnostics remain `tenant_id = NULL` and retain
  owner-scopedbehavior.
- Added PostgreSQL-only RLS policies for tenants, memberships, integrations,
  diagnostics,AI usage, tenant budgets, audit events, subscriptions, invoices,
  paymentsand notifications. Policies use transaction/request-scoped application
  settings`app.current_tenant_id` and `app.current_user_id`; tenant membership is verified before a web route
  activatesexplicit tenant scope. Signed WHMCS API requests resolve the
  integrationthrough a narrowly scoped lookup setting, verify HMAC/nonce first,
  thenexecute under the integration's tenant/legacy-owner context. Queue
  analysisjobs carry tenant and owner context and clear settings in a `finally` block.
- Legacy null-tenant diagnostics are not globally exposed by RLS: their policy
  resolvesownership through the integration's `owner_user_id`. Legacy audit rows are visible
  onlyto their recorded user/integration actor. Tenant notification
  SELECT/UPDATEis recipient-only; inserts require the recipient's active
  membershipin the current tenant and use the unique notification key for
  idempotency.
- Composite database regression tests cover cross-tenant diagnostic findings,
  notificationrecipients, subscription/invoice links and payment/invoice links.
  Testsare authored, not executed. SQLite retains composite foreign-key
  enforcement;PostgreSQL RLS policies are created only when the migration runs
  onPostgreSQL.
- RLS is enabled but deliberately not forced: the schema-owner/migration role
  canperform controlled schema and recovery work. The production application
  loginmust be a separate non-owner role without `BYPASSRLS`; platform-wide retention,
  audit-integrity,backup and restore operations require a separately controlled
  privilegedoperations role. The three logical roles are schema owner/migrator,
  restrictedapplication runtime, and platform operations/audit. Actual role
  creation,credentials, grants, ownership transfer and PostgreSQL rehearsal
  remainpending Shahrukh's database access; no repository settings or database
  statewere changed.
- Migration is authored only and has not been executed. PostgreSQL policy
  behavior,grants, query plans and migration rollback still require a
  non-productionPostgreSQL rehearsal before release.

- Added SQLite and PostgreSQL database triggers to enforce exact
  tenant-attributionparity for integration → diagnostic run → finding →
  recommendation.This closes the nullable-composite-FK loophole where a
  tenant-ownedchild could otherwise omit `tenant_id` and skip a MATCH SIMPLE composite
  foreignkey. Regression tests now cover both mismatched and null-attribution
  bypassattempts.
- Extended tenant-parity triggers to AI usage attempts and immutable audit
  subjects.A usage attempt must match its diagnostic run's tenant when the run
  referenceexists; audit events for tenant, diagnostic and billing subjects must
  carrythe same tenant as the referenced record. Legacy owner attribution and
  post-retentionAI ledger rows with a null diagnostic reference remain
  supported.
- Added `whmcs:integration:assign-tenant`, a one-way transactional mapping command for an explicitly approved
  tenantUUID. It assigns only currently unassigned integrations, then backfills
  diagnosticruns/findings/recommendations and AI usage attempts under database
  parityguards; it appends a new tenant-attributed audit event and never
  rewriteshistorical audit-chain entries. It does not create tenant memberships
  orinfer a role. Command execution requires the separately controlled platform
  operations/schema-ownerdatabase role after Shahrukh provisions access.
