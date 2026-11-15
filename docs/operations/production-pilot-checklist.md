# Production pilot safety checklist

This checklist is mandatory before installing or running the WHMCS addon against the live ResellerBytes installation.

## Laravel application Redis preflight

- [ ] PHP 8.5 CLI and FPM both have Redis and ZIP extensions enabled. Redis is required for FPM sessions/cache and CLI queues; ZIP is required for the authenticated addon download route.
- [ ] Redis is reachable locally (`redis-cli ping` returns `PONG`) and is not publicly exposed.
- [ ] Redis authentication, memory limit, `noeviction` policy, persistence, and monitoring are configured and reviewed.
- [ ] Laravel `.env` uses `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, and `QUEUE_CONNECTION=redis` with distinct cache/default logical database indexes.
- [ ] A supervised queue worker is configured with a timeout shorter than Redis `retry_after`.
- [ ] Redis restart/recovery behavior has been tested before relying on it for sessions and background jobs.
- [ ] Forge's Laravel scheduler is active (`schedule:run` every minute), so expired HMAC nonces are pruned every 15 minutes.

## Application access and AI analysis

- [ ] Public self-registration is disabled; no public account-creation route is available.
- [ ] The first verified dashboard operator was created through php artisan ops:user:create "Operations Admin" operator@example.com; the password was entered through hidden terminal prompts.
- [ ] Confirmed TOTP-based two-factor authentication is enabled for every production operator before operational access; passkeys are additional factors, not substitutes.
- [ ] OPENAI_API_KEY is configured only in the Forge environment, never in source control or WHMCS.
- [ ] The configured OpenAI model is available to the API project and the project has an appropriate spend limit.
- [ ] The Redis queue worker is running and supervised; the scheduler is active.
- [ ] A test snapshot has completed AI analysis, its evidence paths match the stored sanitized payload, and recommendations are clearly marked manual-only.
- [ ] A provider failure leaves the original diagnostic snapshot intact and records only a sanitized error code.
- [ ] The dashboard shows no findings as not yet analyzed until analysis has completed; an empty findings list is not treated as proof of health.

## Preconditions

- [ ] The Laravel application and ingestion API have passed automated tests and security review.
- [ ] The exact addon release and SHA-256 checksum have been reviewed.
- [ ] The addon has been statically inspected; no shell execution, SQL writes, WHMCS business API calls, or outbound destinations other than the configured operations API are present.
- [ ] A current, restorable backup of the WHMCS files and database has been confirmed by the operator.
- [ ] A maintenance window and rollback owner have been identified.
- [ ] The integration was created using `php artisan whmcs:integration:create` from an authorized server shell.
- [ ] The one-time integration secret is stored only in the external credentials JSON file; WHMCS addon settings contain only that file's path.
- [ ] The HTTPS certificate and destination hostname have been independently verified.
- [ ] The collector schema and redaction rules have been reviewed against the exact payload that will be transmitted.
- [ ] The WHMCS addon passed its PHP 8.1 syntax and credential-file tests.
- [ ] The HMAC credentials JSON file is outside both WHMCS and the document root, has mode `0600`, and is readable by the WHMCS PHP runtime user.
- [ ] WHMCS addon settings contain only the credentials-file path; the HMAC secret is not stored in `tbladdonmodules`.
- [ ] The addon has no enabled cron task or hook, and its only outbound destination is the verified HTTPS operations API.

## First-run restrictions

- [ ] Install only the reviewed addon version.
- [ ] Do not enable a cron or scheduled run.
- [ ] Run the collector manually once.
- [ ] Confirm the addon makes only the signed HTTPS POST to the operations API.
- [ ] Confirm the snapshot is accepted and visible in the operations dashboard.
- [ ] Confirm no customer, invoice, transaction, credential, API token, or raw configuration data is present in the payload.
- [ ] Confirm the existing 20i hosting module and ResellerClub registrar module remain unchanged.
- [ ] Review all findings with an operator before considering any manual remediation.

## Immediate rollback triggers

Stop the pilot and remove/disable the addon if any of the following occurs:

- Unexpected WHMCS database writes or business-state changes
- Unexpected outbound network requests
- Any customer, billing, domain, hosting, or payment data is collected
- PHP errors, elevated resource use, slow requests, or cron degradation attributable to the addon
- Invalid signatures, unexplained retries, duplicate snapshot corruption, or unexpected API responses
- Any discrepancy between reviewed addon code and the installed package

After stopping, preserve relevant sanitized logs, revoke the integration secret, remove the addon using the reviewed rollback procedure, and verify normal WHMCS operation.

## Not permitted during the pilot

Do not change PHP versions, cron commands, file permissions, OPcache, WHMCS directory paths, module-debugging settings, 20i settings, ResellerClub settings, billing, customer accounts, domains, or hosting services based on an AI recommendation.

The first pilot validates collection and transport only. It does not authorize repairs.

## Tenant billing and recovery release gates

The provider-neutral billing core is implemented, but no payment provider is activated and no public tenant billing webhook route or charge-initiation path is enabled. Treat these as separate release gates:

- [ ] Select the payment provider, verify merchant-account ownership, approve the provider's webhook signature contract/event mapping, and provision provider credentials through the secret manager. No provider secret belongs in Git or chat.
- [ ] Implement and independently review the selected provider adapter; verify signatures against the exact raw request body, enforce the five-minute timestamp window, and prove tenant/provider/account-scoped reference resolution.
- [ ] Confirm invoices, subscriptions, automated payments, refunds/disputes, and provider-account bindings remain tenant/provider/account consistent; verify payment and adjustment ledgers remain append-only and refund/lost-dispute totals cannot exceed the original payment.
- [ ] Provision the separate PostgreSQL billing-operations login and complete the RLS rehearsal. The operations login must be same-database, non-superuser, BYPASSRLS, non-owner, non-inheriting, and SELECT-only on the exact provider-account, webhook-inbox, and outbox allowlist.
- [ ] Rehearse webhook/outbox delivery under duplicate events, changed-payload replay, concurrent delivery, worker crash, transient failure, retry exhaustion, and dead-letter recovery. Outbox consumers must deduplicate by stable outbox ID because delivery is at-least-once.
- [ ] Verify manual dead-letter recovery requires tenant `billing.manage`, a constrained reason code, explicit `--confirm`, a still-active/verified provider mapping for webhook replay, and a maximum of three manual replays. Confirm every replay is transactionally audit-recorded without payload or secret disclosure.
- [ ] Run `php artisan billing:operations:report --json` and verify aggregate-only output, dead-letter/retry/backlog metrics, and fail-closed behavior when the operations role or tables are unavailable.
- [ ] Verify billing dead letters, processing leases older than five minutes, and due pending backlog older than 15 minutes trigger the existing alert evaluator. Configure and delivery-test approved destinations before enabling production notifications.
- [ ] Approve the final payment-failure grace policy, provider pricing, price-card version, and production activation plan. The current seven-day grace default is provisional and must not be treated as a final commercial approval.
- [ ] Do not enable live charges, public webhook ingress, tenant billing, or provider credentials until the preceding checks and independent review are complete.

## Audit, database, and retention release gates

See [`legal-hold-and-diagnostic-retention-runbook.md`](legal-hold-and-diagnostic-retention-runbook.md) for Owner CLI procedures, TOTP requirements, dry-run/execute gates, and fail-closed behavior.

- [ ] The exact PR head SHA has all six required CI jobs green: general CI, PostgreSQL AI-budget/audit concurrency, MySQL 8.4 retention, MariaDB 11.4 retention, SQL Server 2022 retention, and WHMCS PHP 8.1 compatibility.
- [ ] PostgreSQL migration, runtime, and read-only reporting principals are separate; runtime has DML only and no DDL, migration-ledger read, or audit-event mutation; read-only is SELECT-only on an explicit approved view/table allowlist; migration credentials are deploy-only.
- [ ] Runtime-role negative privilege tests prove audit UPDATE/DELETE/TRUNCATE, migration-ledger reads, schema CREATE, and integration hard DELETE are rejected; normal audit append and chain verification still work under the runtime login. The read-only role must prove SELECT only on an explicit reporting allowlist and deny unapproved sensitive-table SELECT plus all writes and DDL. PostgreSQL CI runs `tests/Postgres/RuntimeRolePrivilegesTest.php` and `tests/Postgres/ReadOnlyRolePrivilegesTest.php` under disposable roles.
- [ ] The WHMCS integration retention trigger has passed PostgreSQL and MySQL 8.4 tests; MariaDB 11.4 and SQL Server 2022 dedicated CI jobs must also pass on the exact release SHA. The SQL Server job directly exercises the migration against an isolated SQL Server table.
- [ ] The report-only diagnostic retention command `php artisan diagnostics:retention:report --days=365` has been run against a restored non-production copy and its candidate/dependent counts reviewed. It does not delete records; legal holds do not change the absolute 365-day cutoff.
- [x] Legal-hold authority is approved: project Owner authorizes placement and release; every action requires an append-only audit event with owner identity, UTC timestamp, affected scope, reason, and controlled evidence/case reference.
- [x] Legal-hold schema and Owner-only CLI placement/release commands implemented; `OPERATIONS_OWNER_EMAIL` selects the sole Owner, with verified email, enrolled MFA, and fresh TOTP per action.
- [x] Placement/release audit events are transactionally coupled to hold-state changes; audit-write failure rolls back the hold change.
- [x] Diagnostic cleanup is bounded, dry-run by default, disabled by default, requires explicit `--execute`, and marks active direct/descendant holds `expired_by_retention` with immutable audit events before deleting diagnostic content at 365 days.
- [x] Each diagnostic deletion appends an immutable audit event in the same transaction; audit-write failure rolls back deletion.
- [ ] Exact PR-head CI, production backup/restore rehearsal, and operational approval must pass before setting `DIAGNOSTICS_RETENTION_CLEANUP_ENABLED=true`.
- [ ] The external audit anchor is provisioned in a separate security account with **365-day immutable retention**, independent Ed25519 signing key, read-only verifier, alert routing, and a successful restore/read-back drill.
- [ ] The latest database head matches the newest external signed checkpoint; any unanchored tail and checkpoint age are documented.
- [x] A standalone public-key-only checkpoint verifier is available at `tools/verify-audit-anchor.php`; it validates the signed envelope, expected environment, optional predecessor digest, strict UTC calendar timestamp, future-clock bound, optional maximum age, and checkpoint SHA-256 without database or private-key access. Tests cover tampering, environment/predecessor mismatch, malformed calendar dates, future timestamps, and stale checkpoints.
- [x] `php artisan audit:anchor:monitor` fails closed for missing/invalid/stale checkpoints, database-prefix mismatch, future timestamps, and unanchored-tail threshold breaches; automated tests cover healthy, stale, missing, and excessive-tail cases.
- [x] Provider-neutral `AuditCheckpointPublisher` verifies the signed checkpoint against the local chain, uses a deterministic environment/sequence/digest key, and reports success only after exact-version checksum and 365-day COMPLIANCE retention read-back; after a failed create response it performs lookup-only reconciliation (never retries the write) and accepts success only after exact-version read-back. Fake-store tests cover duplicate, ambiguous, unresolved, version, checksum, and retention failures.
- [ ] Approve the checkpoint cadence, freshness/tail thresholds, external scheduler identity, and security alert destination; production scheduling and alert delivery are not enabled by this repository.
- [ ] Complete the provider-specific Wasabi/S3-compatible rehearsal after the independent storage provider and non-production integration-test account are provisioned; prove conditional-create race handling, exact-version checksum read-back, and Object Lock COMPLIANCE retention metadata against the real service before reporting production anchoring ready.
- [ ] A database backup has been restored in isolation and compared against the newest external checkpoint before any production migration or pilot.
- [ ] Complete the isolated PostgreSQL backup/restore rehearsal in [`backup-restore-rehearsal-runbook.md`](backup-restore-rehearsal-runbook.md), including Object Lock read-back, runtime-role probes, audit-chain verification, and signed external checkpoint comparison.
- [x] External immutable audit-backup retention period: **365 days**, approved by the project owner on 2026-11-14.
- [x] Diagnostic retention policy: absolute 365-day expiry with no exceptions; legal holds remain audited governance metadata and do not extend diagnostic content retention.
- [x] Legal-hold enforcement and guarded 365-day diagnostic cleanup safeguards are implemented with automated tests. Cleanup remains disabled by default; no schedule is registered and explicit deployment approval is still required.
- [x] Audit events are explicitly excluded from all automated cleanup under the approved indefinite-retention policy.

**Pending for Shahrukh before pilot:** create/fund the separate cloud-security account and approve Wasabi bucket/Object Lock configuration; provide approved secret-manager access and production Ed25519 key custody/public-key distribution; provision production PostgreSQL runtime, migration, and billing-operations roles; provide the isolated backup/restore rehearsal environment (including WHMCS/MariaDB and uploaded files); select/approve the payment provider, merchant-account ownership, final grace policy, and production price card; provide production SMTP configuration through the secret manager (SMTP first, Postmark at launch, Log remains current default); configure and delivery-test approved alert destinations; provide PHP 8.5 validation infrastructure; arrange independent security review against the exact final SHA; and give final release authorization. The approved thresholds, alert-routing policy, recovery objectives, retention period, operator model, and three-role PostgreSQL design are decisions already made and are not pending. Live audit events are retained indefinitely and are never automatically deleted; the separate external backup objects retain the approved 365-day Object Lock period. Do not share private keys or production database passwords in this repository or chat.
