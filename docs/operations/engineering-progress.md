# Engineering progress — release readiness

Last reviewed: 2026-11-15

## Verified repository baseline

- Repository: `DiligentCreators/ResellerBytesAiOperation`
- Main baseline: `757e2bf75907e1d6d970c921faadee24c7445b47`
- Review branch: `work/release-readiness-audit`
- The foundation provides a read-only WHMCS diagnostics addon, signed API ingestion, queued AI analysis, dashboard, and versioned addon package.
- The application targets Laravel 13 / PHP 8.5 with PostgreSQL and Redis; the WHMCS addon targets PHP 8.1.
- The addon is manual-first and read-only. It must not alter WHMCS business records, call provisioning/registrar APIs, or change existing 20i and ResellerClub modules.
- No deployment or live WHMCS installation change has been performed.

## CI evidence

- GitHub Actions run [43207784623](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43207784623) completed successfully for an earlier commit; both `whmcs-addon-php81` and `ci` passed.
- Main-branch run [43126184415](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43126184415) also completed successfully for its commit.
- Latest reviewed run [43228161983](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43228161983) is **not green**: `whmcs-addon-php81` passed, while `ci` failed at Pint's `fully_qualified_strict_types` formatting check for `whmcs-addon/tests/signed_api_client_test.php`.
- The frontend build and earlier formatting/lint steps completed in that failed run; the workflow stopped at the Pint test before the remaining test suite could establish a pass.
- The strict-types formatting issue was still present in the PR merge-check run [43230275243](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43230275243): `whmcs-addon-php81` passed, while `ci` failed at the same Pint rule in `whmcs-addon/tests/signed_api_client_test.php`. The test now removes redundant leading backslashes from global `RuntimeException` references and imports `SignedApiClient` in the final namespace block. This correction is pending CI verification; no new CI has been intentionally triggered.
- Runs [43245822574](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43245822574), [43246446818](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43246446818), and [43246879200](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43246879200) passed the WHMCS PHP 8.1 addon job but failed main CI at frontend formatting. Run [43247959545](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43247959545) passed frontend formatting, lint, and TypeScript checks, then failed Pint's ordered-imports rule in `routes/web.php`. Run [43248233467](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43248233467) passed formatting, lint, TypeScript, and Pint, then PHPStan reported that the validated Sodium secret-key string needed an explicit non-empty guard. The previous non-empty guard was insufficient for PHPStan's Sodium stub; an explicit validated non-empty-string assertion is now added. Pest has not yet run on this change batch.
- These results apply only to the commits and checks identified above; they do not certify production readiness.

## Source review observations

- HMAC request signing uses a fixed HTTPS endpoint, a canonical method/path/timestamp/nonce/body-digest message, a random nonce, TLS peer/host verification, disabled redirects, and a bounded response body.
- Server middleware validates timestamp skew, signature, integration status, request size, query-string absence, and one-time nonce use.
- The addon credential loader requires an absolute path outside the WHMCS/document roots, rejects symlinks, enforces exact mode `0600`, bounds file size, and validates the JSON structure.
- The collector uses an allowlist of technical signals and does not transmit the configured system URL or customer/billing records.
- The AI analyzer treats the snapshot as untrusted evidence, requests strict structured output, validates finding fields, and rejects evidence paths that do not resolve to observed snapshot values.
- The nonce-pruning command deletes expired request nonces in bounded batches. No diagnostic-run, finding, recommendation, or audit-event retention/deletion policy or scheduled pruning mechanism was found.
- Diagnostic runs are configured to cascade-delete with their parent integration. This destructive lifecycle behavior needs explicit product/operator confirmation and test coverage before exposing integration deletion as a routine operation.

### Authentication and operator access review

- Dashboard, integrations, diagnostic runs, findings, addon download, and addon setup routes require authentication, verified email, and confirmed TOTP-based two-factor authentication through `EnsureTwoFactorEnabled`.
- Operators without confirmed TOTP are redirected to security settings; the security page remains reachable so they can enroll and confirm the authenticator before operational access. Password confirmation remains required by the security route.
- Fortify public registration is not enabled in the inspected feature configuration. The explicit operator-provisioning command creates a verified account and applies a strong password policy.
- Passkeys remain available as an additional authentication option, but are not treated as a substitute for confirmed TOTP because the application does not yet track authentication assurance per session. The last-operator deletion safeguard remains pending.
- A self-service profile deletion route is available to authenticated, verified users. It now locks the operator rows and blocks deletion when only one account remains; focused feature coverage protects the last account while allowing deletion when another operator exists.
- The trusted-operator model may be acceptable if all accounts are provisioned through a controlled process, but this assumption needs an explicit production control and verification of all account-creation paths.

### Audit-event integrity review

- `AuditEvent` now rejects Eloquent model update and delete operations; creation remains allowed. A focused Pest feature test covers append, update rejection, and delete rejection.
- This is an application-layer guard only. Query-builder updates/deletes, raw SQL, privileged database access, and database administrators can bypass it; no database trigger/privilege boundary or separately controlled append-only export has been implemented or verified. Do not describe the audit store as tamper-proof.
- Stronger production assurance still requires a separately approved database-level control and/or export to separately controlled append-only storage.

### Queue and package review

- `AnalyzeDiagnosticRun` uses a database row lock, a 100-second analysis lease, a 90-second job timeout, three attempts, and bounded backoff. Provider exceptions set a safe error code and are rethrown for queue retry; the failed hook records terminal failure.
- The Redis queue uses a 120-second `retry_after`, which is longer than the job timeout and lease. This is a coherent baseline, but crash/recovery behavior for jobs left in `queued` state should be covered by an explicit reconciliation test or mechanism.
- Snapshot ingestion records the accepted snapshot and its audit event transactionally, then queues analysis. Dispatch failures are handled in the inspected controller paths, but a process interruption between persisting `queued` and successful enqueue remains a recovery scenario to validate.
- Manual reanalysis now locks the diagnostic-run row and performs the allowed-status check, `queued` transition, and audit-event creation in one database transaction. Queue dispatch occurs after commit; a concurrent request cannot independently claim the same initial status.
- Ingestion and manual reanalysis can still be interrupted after persisting `queued` but before queue publication. A bounded `diagnostics:recover-queued` scheduler command now atomically claims stale queued rows and re-dispatches them every five minutes. This is bounded reconciliation, not a transactional outbox; a delayed queue can still produce duplicate queue entries, while job-level locking protects analysis execution.
- The authenticated addon download controller builds the ZIP from the addon source directory, skips symlink files, and signs the version plus sorted SHA-256 checksums of every addon source file and the installation guide using Ed25519. The public-key fingerprint is exposed separately by an authenticated, MFA-protected plain-text endpoint; downloads fail closed if Sodium or a matching key pair is unavailable.
- No confirmed production-blocking defect was established in this focused source pass. The review is not a substitute for a full independent penetration test.

## Work completed on this branch

- Expanded the standalone signed API client test to exercise HTTP 500 responses, malformed JSON, empty responses, and cURL transport failures in addition to successful signing, TLS/redirect settings, and oversized response handling.
- Corrected the Pint strict-types formatting failure in the signed API client test. This correction is pending CI verification.
- Completed a focused source review of route authorization, operator provisioning, audit-event model behavior, queue execution/retry handling, and addon ZIP/checksum generation. Findings above are documented for remediation or operator disposition.
- Added an Eloquent-level append-only guard to `AuditEvent` and a focused Pest feature test. Execution of this new test and the full suite is pending; this change does not provide database-level immutability.
- Added mandatory confirmed TOTP enforcement to operational dashboard, integration, diagnostic, findings, and addon routes, with a clear redirect to the security setup page and focused access-control tests. The security enrollment page remains accessible before enrollment.
- Confirmed the manual reanalysis transaction/row lock and added bounded stale-queue recovery. Runtime tests remain pending.
- Added Ed25519 package signing, a separate authenticated fingerprint endpoint, deployment key configuration, archive verification instructions, and focused package-signature tests. A local Sodium sign/verify/tamper smoke check and a simulated archive-verifier check passed; Laravel ZIP generation and full CI remain pending.
- Added a row-locked safeguard that prevents self-deletion of the last remaining operator account, with feature tests for both blocked last-account deletion and permitted deletion when another account remains. Successful operator deletion is now recorded in the append-only audit event stream.
- Added queue-recovery feature tests for stale/fresh queued-run selection and bounded batch processing.
- Pull request: [#2 — release-readiness review](https://github.com/DiligentCreators/ResellerBytesAiOperation/pull/2).

## Remaining release gates

1. Approve and implement the stronger audit-integrity boundary: database-level controls and/or export to separately controlled append-only storage. The current Eloquent guard is defense-in-depth only.
2. Confirm the trusted-operator account model and verify every account-creation path. Mandatory confirmed-TOTP enforcement and last-operator deletion protection are implemented; validate the login/enrollment and deletion flows in runtime tests.
3. Test the bounded stale-queue recovery against dispatch failures, process interruption, duplicate dispatch, delayed queue workers, and stale leases; assess whether a transactional outbox is justified.
4. Configure the production Ed25519 key pair in the deployment secret manager, record the trusted fingerprint returned by the authenticated fingerprint endpoint through the approved release channel, and verify a downloaded ZIP end-to-end including altered-file rejection.
5. Define and approve data-retention periods for diagnostic snapshots/findings/recommendations and audit events. Do not enable destructive automated pruning until an operator-approved policy exists; implement dry-run, bounded batches, opt-in configuration, and tests as part of that approved change.
6. Review and test the integration deletion lifecycle, including the cascade effect on diagnostic history.
7. Perform static/local checks on the complete change batch, then run one consolidated CI workflow and resolve any failures before another run.
8. Confirm production infrastructure settings: PHP extensions, PostgreSQL/Redis connectivity, queue worker, scheduler, SMTP, operator MFA/passkey, and spend limits.
9. Require an operator-approved restorable backup, maintenance window, rollback owner, exact addon ZIP/checksum review, external credentials file with mode `0600`, and one-shot read-only WHMCS pilot before any deployment.

## Status vocabulary

- **Verified** means directly confirmed from repository or execution evidence.
- **Pending** means not yet independently verified.
- **Blocked** means a required safety precondition or external operator action is outstanding.

This document records review evidence; it does not certify the application as
production-ready.

## Operator-approved budget and retention policy — 2026-11-12

- Global AI budget targets approved: USD 5.00 per UTC day and USD 50.00 per UTC calendar month.
- Diagnostic snapshot retention target approved: 365 days, including dependent findings and recommendations.
- Earlier provisional audit-event retention target: 2,555 days (approximately seven years); superseded by the later 2026-11-14 Owner decision for indefinite audit-event retention.
- These are policy targets only. No budget enforcement is active solely because these values were approved.
- The design calls for atomic daily/monthly reservations, integer micro-USD accounting, versioned model pricing, idempotent usage settlement, and conservative handling of ambiguous provider outcomes.
- No budget-ledger or retention migration has been added in this step. Schema changes remain reserved for the final consolidated migration batch.
- No destructive migration or pruning has been run or authorized by this work.

## Additional queue recovery hardening

- The bounded `diagnostics:recover-queued` command now also identifies abandoned `analyzing` rows whose analysis lease is older than the effective stale threshold.
- The threshold is never shorter than the configured queue retry visibility window, the 90-second job timeout, and the 100-second analysis lease, plus a safety margin.
- A stale row is atomically returned to `queued`, its old lease timestamp is cleared, and a replacement job is dispatched. Fresh `analyzing` rows remain untouched.
- Focused feature coverage was added for stale versus fresh `analyzing` recovery. Runtime CI verification is pending.
- This remains bounded reconciliation, not a transactional outbox; duplicate queue entries remain possible, while the job-level row lock and lease protect execution.

## Approved hybrid SaaS direction — 2026-11-12

The operator selected the hybrid business model: validate ResellerBytes AI
Operations internally first, then commercialize the same platform for external
hosting providers.

Approved architecture decisions:

- Hosting: one shared application and shared database with strict tenant isolation. ResellerBytes is a normal tenant, not a privileged bypass.
- Billing: automated recurring payments and manual invoices/payments through one subscription, billing-event, and entitlement model.
- Branding: launch under the ResellerBytes brand while designing tenant-configurable branding and a later white-label path.
- Architecture style: modular monolith with queue-based diagnostic processing; no microservices split for the first commercial release.
- External customer onboarding is blocked until tenant ownership is backfilled, cross-tenant access tests pass, and server-side entitlements are enforced.
- Automated billing requires verified, idempotent provider webhooks; manual payments require auditable recording and adjustment history.
- Custom domains and advanced white-label features are deferred until domain verification, TLS, routing, and tenant-isolation controls are implemented.

The approved target architecture and phased delivery plan are documented in
hybrid-saas-architecture-and-roadmap.md. The overall first-commercial-release
planning estimate is approximately 5–9 weeks, subject to Phase 1 verification,
schema complexity, payment-provider access, and beta feedback.

### Current CI status correction

- Latest branch-head run 43255558196 completed with the main ci job failed and whmcs-addon-php81 passed.
- The main CI failure is confirmed as Markdown formatting in docs/operations/ai-budget-and-retention-policy.md and docs/operations/engineering-progress.md; the frontend build completed successfully in that run.
- Do not treat the branch as green or release-ready until formatting is corrected and a new consolidated CI run passes.
- No merge, deployment, migration, or destructive pruning is authorized by this architecture decision.

## Current branch-head verification — 2026-11-12

This section supersedes earlier CI-status statements above where they conflict
with the latest verified repository evidence.

- Latest reviewed PR check run: [43277780912](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43277780912) — both `ci` and `whmcs-addon-php81` completed successfully.
- Verified CI summary for that run: formatting, lint, frontend build, TypeScript, Pint, PHPStan, and Pest passed; Pest reported 109 passed and 2 skipped (457 assertions).
- PR #2 remains a draft. No review submissions or inline review threads were present at the time of this check.
- The AI budget ledger migration `2026_11_12_000008_create_ai_budget_ledger_tables.php` is present on the review branch. It has not been applied to production.
- The branch includes global UTC daily/monthly budget reservations, per-attempt usage accounting, idempotent settlement, conservative holds for ambiguous submitted requests, and tests for budget limits and failure states.
- The global budget is **not active in production** until the migration is applied under an approved deployment plan and PostgreSQL concurrency, worker-crash, provider-timeout, and deployment verification are completed.
- At the time of this historical entry, authenticated operator reconciliation was not yet implemented. The later reconciliation implementation and its current limitations are recorded below.
- Retention cleanup remains design-only. No pruning, destructive migration, or production migration has been run.
- Production Ed25519 signing keys still require generation and storage in the deployment secret manager, followed by ZIP signature verification against the approved fingerprint.
- Database-level audit immutability and the integration-deletion/history lifecycle remain release gates.
- The approved hybrid SaaS architecture remains a target design. Tenant isolation, commercial billing, entitlement enforcement, and white-label capabilities are not represented as completed implementation.

## Next engineering sequence

1. Add true concurrent PostgreSQL reservation tests and worker-crash/provider-timeout failure-mode tests; current PostgreSQL CI validates database integration but not simultaneous race behaviour.
2. Security-review the reconciliation command, including whether confirmed TOTP configuration is sufficient or a fresh MFA challenge/two-person approval is required for operational use.
3. Decide and implement the database-level audit-integrity boundary, then test privileged and query-builder bypass paths.
4. Confirm integration deletion semantics and preserve required diagnostic, usage, and audit history.
5. Generate and verify production signing keys through the approved secret-management process.
6. Complete retention dry-run controls, production pilot checklist, backup/restore rehearsal, and one-shot read-only WHMCS pilot only after explicit deployment approval.

A green CI run is necessary but does not close these release gates or authorize
merge, migration, or deployment.

## Ambiguous AI-attempt reconciliation implementation — 2026-11-12

- Added a narrowly scoped `ai-budget:reconcile-no-charge` Artisan command for the only safe automated resolution currently supported: an operator has verified provider-side evidence that the ambiguous request was not accepted and incurred no charge.
- The command requires a verified operator account with confirmed TOTP, an evidence reference, a substantive reason, and an interactive second confirmation. It refuses non-ambiguous attempts and bounds evidence-reference and note lengths.
- The reconciliation service locks the attempt and both original UTC budget periods, verifies the held reservation invariant, releases the reservation, records operator/evidence metadata, and appends an audit event in the same database transaction.
- Added a feature test for successful reconciliation, budget reservation release, audit creation, and rejection of repeated reconciliation.
- Documented the evidence standard and operational command in `docs/operations/ai-budget-and-retention-policy.md`.
- This was source implementation only at the time of the entry. Subsequent CI execution, rollback tests, CLI tests, and PostgreSQL integration results are recorded in the current branch-head verification below. No ambiguous production reservation has been changed.
- This command deliberately does not resolve a confirmed-charge outcome. Such attempts remain held until a separately reviewed provider-usage settlement workflow exists.

## Current branch-head verification — 2026-11-12 (supersedes earlier status entries)

Verified PR #2 head: `41d939164a2c787d5feced121e4dfc24b823068b`.

- Workflow run [43286897952](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43286897952) completed successfully on this exact head. The main CI job and WHMCS PHP 8.1 compatibility job passed.
- Main CI checks: all 84 frontend files formatted; lint found no warnings/errors in 70 files; TypeScript check passed; Pint passed across 119 files; PHPStan completed successfully; Pest reported 118 passed, 2 skipped, and 501 assertions.
- Added a PostgreSQL 16 service job. Both AI budget ledger and reconciliation command feature suites passed against PostgreSQL: 16 tests, 68 assertions.
- Added command-level tests for missing operator identity, unconfirmed MFA, unverified email, operator cancellation, successful reconciliation, and rejection of already-resolved attempts.
- Added a transaction rollback test proving that an audit-event write failure leaves the ambiguous attempt and both budget reservations unchanged.
- Added reservation-invariant checks to settlement and pre-submit release; the service now fails closed if either period does not hold the full reservation.
- The PostgreSQL job validates real PostgreSQL migrations, JSONB storage, transaction behaviour, and row-lock code paths. It does **not** yet prove simultaneous concurrent reservations under load; a dedicated multi-process concurrency test remains a release gate.
- The reconciliation command checks verified email and confirmed TOTP enrollment, then requires an interactive approval. It does not validate a fresh one-time code at command execution; security review must decide whether a fresh challenge or two-person approval is required before production use.
- PR #2 remains open and Draft. No merge, production migration, live WHMCS change, or deployment has occurred. The AI budget migration remains branch-only and the spend cap is not active in production.
- Remaining release gates include PostgreSQL concurrency and worker/provider failure tests, database-level audit immutability, integration deletion/history lifecycle, retention dry-run and restore rehearsal, production signing-key generation/secret management, and deployment/pilot approval.
- The hybrid SaaS tenant, dual-billing, entitlement, and white-label roadmap remains a separate implementation phase; it is not claimed as delivered by this release-readiness PR.

## PostgreSQL multi-process AI budget concurrency harness — 2026-11-13

- Added `tests/Concurrency/ai_budget_reservation.php`, a standalone PostgreSQL-only integration harness that launches eight independent PHP worker processes against eight pre-created diagnostic runs.
- Workers synchronize on a filesystem barrier and attempt reservations at the same time. The test budget is configured to admit exactly three reservations of 322,144 micro-USD each; the other five must fail with `budget_exhausted`.
- The harness verifies worker outcomes, attempt-row count, summed attempt reservations, and matching daily/monthly reserved ledger balances. It also asserts that no committed spend is introduced.
- The PostgreSQL CI job now resets its disposable database after the existing Pest suites and runs this process-level harness with a deterministic budget configuration.
- This test exercises real PostgreSQL row locking across independent connections/processes; it is not a load benchmark and does not yet cover process termination mid-transaction, provider timeout, or queue-worker crash recovery.
- Execution evidence is pending the new CI run. Do not treat the concurrency gate as passed until the PostgreSQL job completes successfully on the new branch head.

## Current branch-head verification — 2026-11-13

This section supersedes earlier CI and recovery-status entries where they
conflict with the latest verified evidence.

- Verified review-branch head: `608a57a39321b82523d23d405f0cb50250912d92`.
- GitHub Actions run [43312619197](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43312619197) completed successfully. The main CI, PostgreSQL AI-budget, and WHMCS PHP 8.1 jobs all passed.
- Main CI: formatting, lint, frontend build, TypeScript, Pint, and PHPStan passed; PHPStan reported no errors. Pest reported 122 passed, 2 skipped, and 537 assertions.
- PostgreSQL 16 job: 27 tests passed with 140 assertions across AI budget ledger, reconciliation, analysis-job, and stale-queue recovery feature suites. The multi-process concurrency harness also passed.
- Concurrency evidence: in both daily-budget and monthly-budget contention scenarios, eight independent PHP workers ran concurrently; exactly three reservations were accepted and five were rejected. Attempt rows and daily/monthly reserved balances matched the expected totals, with no committed spend introduced by the harness.
- Added a provider-timeout feature test proving that an exception after the submit callback leaves the attempt ambiguous, retains both reservations, and prevents a second provider submission while the outcome remains unresolved.
- The bounded `diagnostics:recover-queued` command now inspects the latest budget attempt while holding the diagnostic-run row lock. A stale pre-submit `reserved` attempt is safely released before redispatch; a stale `submitted` attempt is changed to `ambiguous` and held for operator review; an already ambiguous attempt remains held; and a settled attempt belonging to the interrupted analysis is not automatically charged again or redispatched.
- Added recovery tests for pre-submit worker loss, post-submit worker loss, and worker loss after provider usage settlement. These tests passed in both the main CI suite and PostgreSQL 16 suite.
- A stale submitted attempt is retained as ambiguous because provider acceptance and billing cannot be inferred from a worker crash. The existing operator command only releases confirmed-not-charged attempts. A separately reviewed confirmed-charge settlement workflow remains outstanding.
- PR #2 remains open and Draft. No merge, production migration, live WHMCS change, or deployment has occurred. The AI budget migration and enforcement remain branch-only.
- Remaining release gates include confirmed-charge settlement, least-privilege production DB role provisioning, external tamper-evident audit backup/export, integration deletion/history lifecycle, production signing-key setup and ZIP verification, approved retention implementation, backup/restore rehearsal, production infrastructure verification, and an explicitly approved read-only WHMCS pilot.
- The hybrid SaaS tenant-isolation, dual-billing, entitlement, and white-label roadmap remains a separate implementation phase and is not certified as delivered by this PR.

A green CI run is necessary but does not close these release gates or authorize
merge, migration, or deployment.

## Database-enforced audit append-only controls - 2026-11-13

- Added a forward-only migration installing database triggers that reject UPDATE and DELETE on audit_events for SQLite, PostgreSQL, MySQL/MariaDB, and SQL Server.
- PostgreSQL also rejects TRUNCATE with a statement-level trigger. MySQL/MariaDB and SQL Server do not have equivalent row-trigger coverage for TRUNCATE; production runtime credentials must therefore be denied TRUNCATE, DROP, ALTER, and trigger-management privileges.
- Added regression tests that bypass Eloquent through the query builder and verify direct UPDATE/DELETE rejection; PostgreSQL CI additionally verifies TRUNCATE rejection.
- Added the audit immutability feature test to the default SQLite suite and PostgreSQL 16 job. PostgreSQL-specific TRUNCATE verification runs in a dedicated Pest suite with the Laravel test bootstrap.
- Triggers are defense in depth, not protection against a database owner/superuser who can disable triggers or alter the schema. Production still requires a distinct least-privilege runtime DB principal, a separately controlled migration principal, and externally retained backup/export evidence.
- Verified on exact branch head `9737f57042db5e3b072faa2b5de5c308d13f8b38`: CI run [43432519756](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43432519756) is green. Main CI: 127 passed, 2 skipped, 565 assertions; Pint, PHPStan, frontend formatting/lint/type checks passed. PostgreSQL 16: 36 passed, 176 assertions, including the TRUNCATE rejection and multi-process budget concurrency harness. WHMCS PHP 8.1 job passed.
- Trigger enforcement is verified on SQLite and PostgreSQL. MySQL/MariaDB and SQL Server trigger branches are implemented but still require engine-specific integration testing before production selection. PostgreSQL trigger tests demonstrate UPDATE/DELETE/TRUNCATE rejection, including query-builder bypasses.
- This milestone prevents ordinary SQL mutations but does not provide a cryptographic hash chain or externally anchored tamper evidence; an owner/superuser can still alter schema/triggers and a privileged INSERT can forge new rows. Those controls remain open. The migration has not been applied to production. No merge or deployment has occurred.

## Cryptographic audit-chain milestone — implementation complete on branch; rollout pending — 2026-11-13

- Added `docs/operations/audit-hash-chain-design.md` as the implementation contract for the next audit-integrity milestone.
- The design specifies a versioned SHA-256 hash chain, canonical event serialization, a singleton chain-head row with transaction-level locking, deterministic historical backfill, and a read-only `audit:verify-integrity` command.
- Concurrency must serialize on the chain-head row; event insertion and head advancement must commit or roll back together. Unlocked `MAX(id) + 1` sequencing is explicitly prohibited.
- All identified application audit-write call sites have been inventoried and routed through one writer: four operations/WHMCS console commands, snapshot intake, manual reanalysis, account deletion, and both AI-budget reconciliation records. A final source scan found no remaining direct application `AuditEvent::create()` or query-builder audit inserts.
- The design explicitly states that an unkeyed hash chain detects row edits/deletions/reordering but cannot stop a privileged database owner or compromised application from recomputing or appending a chain. Independent external anchoring remains a separate required release gate.
- Implemented the version-1 canonical SHA-256 serializer, transactional `AuditEventWriter`, chain-head compare-and-update guard, and a forward migration that backfills historical events in ascending primary-key order.
- The migration temporarily suspends the existing append-only trigger only during migration, performs deterministic backfill, advances the singleton head, and restores the trigger in a `finally` block. The migration is branch-only and must not be run against production without a rehearsed backup and maintenance plan.
- Replaced all identified application audit writes with the central writer, including operations-user creation, WHMCS integration creation/revocation/secret rotation, diagnostic snapshot intake, manual reanalysis, account deletion, and both AI-budget reconciliation records.
- The hash payload binds sequence and immutable event fields rather than the database auto-increment primary key, avoiding an unsafe post-insert update that would conflict with append-only enforcement.
- Implemented the read-only `audit:verify-integrity` command. Initial tests cover deterministic metadata canonicalization, list-order preservation, contiguous append links, valid-chain verification, chain-head tampering, and event-payload tampering. The AI-budget rollback failure-injection test now targets the writer service, and PostgreSQL tests use the same central writer.
- CI has verified the migration and existing PostgreSQL suites on intermediate commits; the exact latest head still requires a complete green run. SQLite and PostgreSQL are exercised; MySQL/MariaDB and SQL Server migration branches still require engine-specific validation.
- Remaining work: add/verify historical backfill fixtures, explicit writer rollback and multi-process PostgreSQL append-concurrency tests, complete the final exact-head CI run, and document production rollout rehearsal. No production migration, merge, or deployment has occurred.

## Release-readiness follow-up — 2026-11-14

- Verified exact branch head `7da6eaa3533a940f03f7d1d79d147e3677fa2bf8`: all four required CI jobs passed — general CI, PostgreSQL AI-budget/audit concurrency suite, MySQL 8.4 integration-retention suite, and WHMCS PHP 8.1 compatibility. Run: https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43506323777
- Added database-level DELETE protection for retained WHMCS integration history. Regression coverage now exercises SQLite, PostgreSQL, and MySQL 8.4 query-builder deletion attempts. MariaDB and SQL Server code paths remain unverified; PostgreSQL is the documented operations-app database.
- Updated the report-only command default and documented invocation to `diagnostics:retention:report --days=365`, a report-only command that counts old diagnostic runs and dependent findings/recommendations. It never deletes data and explicitly warns that legal holds are not evaluated. A receipt-time index supports the report.
- Added Ed25519 audit checkpoint export and verification commands. The exporter verifies the complete local chain while holding the chain-head row lock, signs a minimal checkpoint, and emits the exact JSON artifact. The verifier checks signature, environment, checkpoint predecessor (when supplied), local chain integrity, and the anchored prefix; it reports the unanchored tail.
- Added key-pair consistency and WHMCS-addon-key reuse rejection. Feature tests cover signature tampering, predecessor mismatch, unanchored-tail reporting, key reuse, and key-pair mismatch. PostgreSQL CI now exercises checkpoint export/verification with the PostgreSQL row-lock path.
- Added independent PostgreSQL migration/runtime role provisioning guidance and expanded the production-pilot checklist with runtime privilege, retention, signed-anchor, restore, and evidence gates.
- External Object Lock storage upload, remote read-back, alert delivery, independent verifier scheduling, production database role provisioning, and backup/restore rehearsal are not active. They require separate infrastructure, credentials, and operator approval.
- **Shahrukh ke liye pending:** provision the separate cloud account and Object Lock bucket; arrange independent Ed25519 production signing/anchor key custody; confirm and provision the production PostgreSQL migration/runtime roles and secret-manager access; approve the production provider/model price card after verifying official rates and expiry; and provide an isolated backup/restore rehearsal environment and access. The 365-day diagnostic retention period, no-exception rule, indefinite live audit-event retention, and Owner-authorized legal-hold placement/release policy have been approved and recorded; their guarded repository implementation is present on this branch. Do not send private keys or production database passwords through chat or commit them.
- PR #2 remains open and Draft. No merge to `main`, production migration, live WHMCS change, or deployment has occurred.

## Owner-approved retention governance — 2026-11-14

- Project owner approved **365-day** immutable external audit-backup retention; recorded in the production-pilot checklist.
- Project owner approved **Owner approval with audit trail** for legal-hold placement and release. Both actions must append an immutable audit event with owner identity, UTC timestamp, affected scope, reason, controlled evidence/case reference, and resulting state.
- Legal holds do not override the absolute 365-day diagnostic expiry. Cleanup marks active hold metadata expired_by_retention with immutable audit evidence before deleting diagnostic content; an audit-write failure aborts the transaction.
- The Owner identity decision is implemented as a single `OPERATIONS_OWNER_EMAIL` configuration value. The configured account must be email-verified and have MFA enrolled; CLI placement/release additionally verifies a fresh TOTP code.
- Implemented the legal-hold migration/model/service and `legal-hold:place` / `legal-hold:release` commands. Hold placement/release and immutable audit-event writes share a database transaction; audit failure rolls back the hold state change.
- Implemented hold inheritance for diagnostic runs, findings, and recommendations. Hold placement and cleanup serialize on the diagnostic-run row; cleanup expires active hold metadata with immutable audit evidence before deleting the target.
- Added bounded `diagnostics:retention:prune` with dry-run default, a disabled-by-default feature flag, explicit `--execute`, no active-status exception at the absolute 365-day cutoff, and cascade-safe deletion. Analysis jobs and queue recovery refuse to start expired runs. No schedule is registered and the feature flag remains false.
- Automated tests cover owner authorization, missing owner configuration, hold placement/release audit events, rollback on audit failure, descendant hold protection, dry-run behavior, disabled execution, and bounded cleanup path. CI must verify these tests on the exact PR head before any green claim.
- Infrastructure provisioning for 365-day Object Lock and independent Ed25519 key custody, plus production backup/restore rehearsal, remain outstanding.

## Diagnostic retention period approved — 2026-11-14

- Project owner approved **365 days** for diagnostic snapshots, including dependent findings and recommendations, measured from `received_at` in UTC.
- Updated the report-only Artisan command default to 365 days; its explicit `--days` override remains available for controlled reporting. Updated boundary and default-behavior tests to cover the 365-day threshold.
- Updated the retention policy, production-pilot checklist, and roadmap references from 90 to 365 days. Audit-event retention is indefinite; immutable external audit-backup retention remains a separate 365-day Object Lock policy.
- This is the approved policy and report configuration, not authorization to delete records. The command remains report-only. Legal holds do not change the 365-day cutoff. Automated cleanup remains disabled until owner-authorized legal-hold workflow, audited hold expiry, fail-closed audit writes, dependency-safe bounded deletion, negative-path tests, and backup/restore gates are complete.
- No further diagnostic-retention exception decision is pending; the approved policy is absolute 365-day expiry with no exceptions.

## Diagnostic retention exception policy approved — 2026-11-14

- Project Owner decision: **no exceptions of any kind** to the 365-day diagnostic retention period. All diagnostic runs and their dependent findings/recommendations expire after 365 days from UTC `received_at`, regardless of active legal-hold status.
- The previously approved legal-hold workflow remains for governance evidence. Legal holds require Project Owner approval and immutable audit events for placement and release; active hold metadata is marked expired_by_retention when the diagnostic target reaches 365 days. Diagnostic content is not retained beyond the cutoff.
- No support case, analytics need, convenience, investigation, or legal-hold status extends diagnostic content retention beyond 365 days.
- Updated the retention policy, production-pilot checklist, and cleanup implementation. The report remains read-only; the manual cleanup command is disabled by default, is not scheduled, and marks active hold metadata expired with immutable audit evidence before deleting diagnostic content at the cutoff.
- External Object Lock provisioning, independent Ed25519 key custody, production DB-role naming, and restore rehearsal remain credential/infrastructure gates.

## Audit-event retention policy approved — 2026-11-14

- Project Owner selected **indefinite retention**: audit events must never be automatically deleted, expired, or pruned. The prior 2,555-day (approximately seven-year) target is superseded.
- Updated the retention policy and production-pilot checklist. Live audit-event retention is distinct from the separately approved 365-day immutable external backup-object retention; backup expiry does not authorize deletion of live audit records.
- Audit events remain append-only and are retained indefinitely. Diagnostic content expires after 365 days with no legal-hold exception; hold metadata is expired and audited at the cutoff.
- The decision was recorded in policy documentation. It does not authorize production migration, merge, or deployment.

## Transactional tenant billing outbox and webhook reconciliation — 2026-11-15

- Added tenant-scoped billing outbox and verified-webhook inbox tables with provider-account/event idempotency, payload SHA-256 binding, PostgreSQL RLS, due/lease indexes, retry state, and dead-letter timestamps.
- Payment ledger confirmation and subscription status transitions now enqueue their internal event in the same transaction as the billing mutation and immutable audit append. Repeated identical outbox keys are idempotent; key reuse with changed content fails closed.
- Added a normalized webhook inbox service that accepts events only after a future provider adapter has verified the signature and mapped the provider account to a tenant. It allowlists supported event types and payload fields, rejects future timestamps, stores no raw provider payload or credentials, and audits only provider/event type/payload digest.
- Added scheduled reconciliation for verified payment-success and subscription past-due/canceled/expired/reactivated events. Payment reconciliation reuses the confirmed-payment ledger; reactivation requires a fully settled invoice for the same tenant and subscription.
- Added bounded exponential retries (30 seconds to six hours), a 12-attempt ceiling, five-minute processing leases, permanent rejection/dead-letter handling, and redacted failure logs. Outbox publication is explicitly at-least-once; consumers must deduplicate on the stable outbox ID.
- Added a separate PostgreSQL operations connection for global scheduled scans. The normal runtime role must remain RLS-restricted; scheduled commands fail closed if the operations username is not configured. Blank environment placeholders only were added. Actual role provisioning and RLS rehearsal remain pending.
- Added feature tests for transaction-required outbox writes, idempotent duplicate handling, conflicting webhook replay, payload allowlisting, confirmed payment reconciliation, and outbox dispatch. These tests are authored but not executed.
- No payment provider was selected, no public webhook route or charge initiation was enabled, and no provider credentials were added. Provider-specific signature verification/account mapping, independent review, operations-role provisioning, and production activation remain pending.
- PHP 8.5 and project dependencies are unavailable in this execution environment (PHP 8.4 only), so migration and test execution remain blocked. All commits in this milestone use [skip ci]; workflow files and repository settings were not changed. Exact-head CI remains deferred until all engineering work is complete.
- PR #2 remains open and Draft. No merge, deployment, production migration, or live WHMCS operation was performed.

### Billing event immutability and replay-race hardening — 2026-11-15

- Added database triggers on SQLite and PostgreSQL that prevent changes to tenant identity, provider/event identity, aggregate identity, payload, and payload digest after outbox/inbox insertion while allowing only delivery/retry state changes.
- Added a dedicated audit trigger so webhook receipt audit events must reference the exact tenant of the webhook inbox row.
- Hardened concurrent webhook duplicate delivery: a unique-key race now reloads and accepts the winning identical event; a different payload or provider-account/tenant binding fails closed.
- Added regression coverage for immutable outbox/webhook payloads, mutable retry state, cross-tenant audit-subject rejection, provider-account replay binding, transient retries, and dead-letter exhaustion. Tests remain authored but unexecuted.
- Latest billing milestone commit at this checkpoint: d8221a8559931c85e14b0240531ce8829f46b8f7. All intermediate commits continue to carry [skip ci]; no workflow files or repository settings were changed.

### Provider-neutral adapter and billing lifecycle expansion — 2026-11-15

- Added provider-neutral adapter contract, verified webhook DTO, internal gateway, strict adapter/provider binding, raw-body size ceiling (256 KiB), five-minute signature timestamp tolerance, and trusted account-to-tenant resolution.
- Added provider-account registry with globally unique provider/account mapping, active + verified gating, PostgreSQL RLS, immutable tenant/provider/account identity, and a dedicated operations-role lookup. No account mapping or secret is configured.
- Persisted provider occurrence time separately from server receipt time and included it in immutable webhook replay identity.
- Added payment-failure reconciliation: unpaid invoice transitions to past_due; eligible active/trialing subscription enters a provisional configurable grace period (default seven days, clamped 1–30 days); audit and outbox records are atomic.
- Added subscription renewal reconciliation: requires a fully settled invoice and contiguous period extension, updates the active subscription period, and appends audit/outbox records atomically. Duplicate invoice/period renewals are idempotent.
- Added regression tests for adapter mismatch, stale signature, provider-account mapping, immutable mapping, payment failure, and settled subscription renewal. Not executed due to PHP 8.5/dependency environment limitation.
- All commits in this continuation include [skip ci]. No workflow file or repository setting was modified. Provider selection, provider credentials, provider-account verification/mapping, operations-role provisioning, independent security review, and production activation remain pending.

### Refund, dispute and renewal ledger expansion — 2026-11-15

- Added invoice.payment_failed, subscription.renewed, payment.refunded, payment.dispute_opened, payment.dispute_won and payment.dispute_lost normalized event handling.
- Failed payment marks an unpaid invoice past_due and moves eligible active/trialing subscriptions into the provisional seven-day grace period (configurable 1–30 days).
- Subscription renewal requires a fully settled invoice for the same tenant/subscription, matching currency, confirmed ledger payment totals, and contiguous period boundaries. Duplicate renewal invoice/period notifications are idempotent.
- Added append-only tenant_billing_adjustments with tenant RLS, immutable update/delete database guards, and tenant-bound audit trigger. Refunds and lost disputes reduce invoice net paid; open/won disputes do not reduce paid balance. Combined refund/lost-dispute totals are capped at the original payment amount.
- Underpaid subscriptions after refunds/lost disputes are moved into the same grace-period workflow. Added feature coverage for refund idempotency, dispute lifecycle, payment failures, renewal and immutable adjustment records.
- No actual provider, payment credentials, provider-account mapping, checkout, charge initiation or public webhook endpoint is active. Test execution remains blocked by PHP 8.5/dependency availability.

### Provider-account payment binding and idempotency hardening — 2026-11-15

- Automated payment ledger rows now persist the originating provider account ID. Payment success, refunds and disputes must match provider + account + payment ID; an event from a different account cannot mutate a payment even when the tenant/provider match.
- Payment idempotency now compares collection mode, tenant, invoice, amount, currency, provider, provider account, provider payment ID, and manual evidence fields. Reusing a key with changed identity fails closed.
- Added a composite payment/tenant unique key and composite foreign key from adjustment records so a refund/dispute cannot reference a payment belonging to another tenant at the database boundary.
- Added regression coverage for account-bound payment idempotency, cross-account refund rejection, and cross-tenant adjustment foreign-key rejection.

### Provider-account settlement proof for renewals/reactivation — 2026-11-15

- Hardened subscription renewal and webhook reactivation so the settled invoice must be covered by confirmed payment ledger entries from the exact provider and provider account that delivered the signed event. A different account belonging to the same tenant cannot reactivate/renew against an unrelated payment.
- Added regression coverage proving a renewal from a mismatched provider account is dead-lettered without changing subscription status or period.

### Subscription provider identity and append-only payment integrity — 2026-11-15

- Added provider account binding to tenant subscriptions. Provider, provider account, and provider subscription ID may be bound once and cannot be changed/unset afterward at the database boundary.
- Automated payment ledger writes require the invoice subscription's exact provider/account binding. Subscription past-due/canceled/expired/reactivated/renewed webhook events are also checked against that binding.
- Payment rows are append-only at the database layer, and payment audit evidence is tenant-bound. Refunds/disputes remain separate append-only adjustment records.
- Provider-supplied grace dates are no longer accepted: the platform applies its own bounded configurable grace policy (default seven days, 1–30 day clamp).
- Added regression coverage for immutable payment rows, immutable provider subscription identity, and provider-account mismatch on renewal. Tests remain unexecuted pending PHP 8.5 and dependencies.

### Provider reference-to-tenant-local ID resolution — 2026-11-15

- Added provider-side invoice identity fields on invoices, scoped by tenant/provider/account and immutable after binding.
- Added TenantBillingWebhookReferenceResolver. After signature verification and provider-account-to-tenant mapping, it resolves provider invoice/subscription IDs to local invoice/subscription IDs within the exact tenant/provider/account. Adapters cannot choose internal IDs or tenant IDs.
- Automated payment ledger, payment failure, refunds/disputes and settled subscription reactivation/renewal now require invoice, subscription, payment and provider account identities to match.
- Added account-scoped resolver tests for invoice and subscription references, including rejection of an invoice reference from a different provider account. Tests remain unexecuted pending PHP 8.5 and dependencies.

### Provider-side reference resolution completion — 2026-11-15

- Provider invoice identifiers are now persisted on invoices with tenant/provider/account uniqueness and immutable identity triggers. Provider subscription identifiers are bound to provider accounts and immutable once set.
- The gateway maps provider invoice/subscription references to local IDs through a tenant-scoped resolver only after signature and account verification. Unknown references fail as permanent rejections; adapters cannot inject local IDs.
- Automated payment writes verify the invoice and subscription provider/account binding. Payment failures, refunds, disputes, renewals and reactivation all require the same provider account context.
- Provider account identity is included in webhook receipt audit metadata. No public webhook route or provider-specific adapter has been activated.

- Added tenant-authorized provider account registration/deactivation service with immutable identity, active/verified gating, and audit-chain evidence. The registration method records an operator attestation only after external account ownership verification; it does not handle provider secrets or perform provider-side verification itself.

### Operational dead-letter recovery — 2026-11-15

- Added manual replay counter with a hard limit of three operator requeues per webhook event.
- Added tenant billing-authorized recovery service and explicit Artisan command. It requires a dead-lettered event, active verified provider-account mapping, tenant billing.manage permission, constrained reason code and explicit --confirm.
- Recovery resets attempts/lease state, requeues immediately, and appends tenant-bound immutable audit evidence. Logs are redacted and do not include payloads or raw exception messages.
- Registered outbox dispatch, webhook reconciliation and dead-letter recovery commands in the explicit Laravel command list. Added tests for authorization, active account requirement, replay limit and command fail-closed behavior. Tests remain unexecuted pending PHP 8.5 and dependencies.

### Outbox dead-letter recovery — 2026-11-15

- Added a bounded manual replay counter for tenant billing outbox messages, capped at three operator requeues.
- Added tenant billing-authorized outbox recovery service and explicit Artisan command, with reason-code-only audit evidence and no event payload logging.
- Added a database audit guard that binds outbox replay audit records to the exact tenant of the outbox message.
- Outbox and webhook dead-letter recovery commands are explicitly registered; consumers remain responsible for stable outbox-ID deduplication under at-least-once delivery.
- Added tests for tenant authorization, replay counter reset/limit, and audit evidence. Tests remain unexecuted pending PHP 8.5 and dependencies.

### PostgreSQL billing RLS rehearsal harness — 2026-11-15

- Added a standalone PostgreSQL rehearsal script covering tenant-local visibility and cross-tenant insert/update denial for billing outbox, webhook inbox, provider-account registry and adjustment ledger.
- The script verifies RLS is enabled, runtime and operations roles are distinct on the same database, the runtime role is neither BYPASSRLS nor superuser, the operations role has BYPASSRLS but is neither superuser nor table owner, and cleans up only mutable test fixtures.
- Global reconciliation now fails closed unless the dedicated operations role has the expected privilege boundary and does not own the protected billing/audit tables.
- The script is authored but not run; it requires provisioned PostgreSQL runtime/operations roles, two test tenant UUIDs, and an existing tenant-A adjustment fixture. These remain infrastructure rehearsal gates.

### Billing operations visibility — 2026-11-15

- Added the redacted `billing:operations:report` command with human-readable and JSON output for webhook/outbox due backlog, stale processing leases, dead letters, retry exhaustion, manual replay ceiling, and oldest due age.
- The report executes through the dedicated PostgreSQL operations-role guard and emits aggregate metrics only; tenant IDs, provider/account IDs, event IDs, payloads, signatures, and exception messages are excluded.
- Added feature coverage for the JSON contract, backlog/dead-letter/replay counters, oldest-due age, and non-disclosure of tenant/provider/payload identifiers. Runtime execution remains pending the PHP 8.5 environment.
- No provider activation, credentials, workflow changes, repository settings, merge, or deployment.

### Billing alert integration — 2026-11-15

- Integrated aggregate webhook-inbox and transactional-outbox health into the existing operational metrics snapshot using the separately guarded PostgreSQL operations role.
- Added alert conditions for any billing dead letter, processing leases older than five minutes, and due pending backlog older than the approved 15-minute queue threshold. Existing alert-state deduplication and repeat behavior is reused.
- Missing billing tables or unavailable/invalid operations-role configuration yields null metrics; unavailable telemetry cannot falsely clear an active alert.
- Metrics expose aggregate counts and age only; no tenant IDs, provider/account IDs, event IDs, payloads, or secrets. Notifications remain disabled by default until Shahrukh configures approved destinations.
- Added feature coverage for metric aggregation, non-disclosure, alert evaluation, and fail-closed resolution behavior. Runtime execution remains pending PHP 8.5 and PostgreSQL operations-role provisioning.

### Central mail secret-clear semantics — 2026-11-15

- Fixed an environment-fallback edge case: explicitly cleared SMTP username/password and Postmark server token now remain cleared even when corresponding environment values exist.
- Persisted encrypted clear-state markers alongside the encrypted mail configuration; the settings page continues to expose only set/not-set booleans and never returns secret values.
- SMTP credential-pair validation now respects explicit clears, and Postmark cannot be re-enabled using a fallback token after the owner clears it.
- Added regression coverage for clearing Postmark tokens despite an environment fallback and clearing SMTP credentials without environment values reappearing.
- Tests are authored but not executed in this environment; PHP 8.5 is required by the repository and only PHP 8.4 is available. All commits in this follow-up use [skip ci]. No workflow file or repository setting was changed.

### Alert delivery status under Log mail mode — 2026-11-15

- Alert dispatch now reports email as `not_sent` when the central mail transport is configured for Log; it no longer treats a redacted preview/no-op as successful email delivery.
- Incomplete delivery keeps the alert eligible for retry and increments the existing failed-notification result; configured team/critical webhooks continue to be evaluated independently.
- Added a regression test proving Log mode sends no email and cannot mark the overall alert delivery successful even when the team webhook acknowledges.
- Runtime tests remain unexecuted pending PHP 8.5 and dependencies. Follow-up commits continue to use [skip ci]; no workflow file or repository setting was changed.

### Production restore approval freshness — 2026-11-15

- Moved the production owner's fresh TOTP challenge to after the verified pre-restore backup and immediately before the immutable restore-approval record and destructive restore.
- The owner email and approval-reference checks still occur before the confirmation gate. If the final TOTP check fails, the safety snapshot is retained and the target database is not restored.
- This narrows the gap between the second factor and the destructive operation; no production restore was run. Runtime tests remain pending PHP 8.5 and infrastructure access. The commit uses [skip ci]; no workflow file or repository setting was changed.

### Backup manifest and restore gate hardening — 2026-11-15

- Restore approval evidence is now written to and verified in immutable object storage before the final fresh production-owner TOTP challenge; TOTP is requested immediately before the destructive PostgreSQL restore.
- Backup manifest intake now enforces a 1 MiB size ceiling, strict SHA-256 syntax, a positive encrypted archive size within the 5 GB single-PutObject limit, and an archive object key derived exactly from the manifest creation date and backup UUID.
- Backup-success marker updates now fail closed on incomplete writes or permission-setting failures before the atomic rename.
- Updated the recovery runbook with these validation and approval-order guarantees. No cloud backup or restore was run; runtime tests remain pending PHP 8.5, cloud account, secret-manager access, and isolated recovery infrastructure. All commits continue to include [skip ci].

### PostgreSQL billing operations least-privilege boundary — 2026-11-15

- Hardened `TenantBillingOperationsConnection` to fail closed unless the billing operations login is distinct from runtime, BYPASSRLS but not superuser, non-inheriting, login-capable, not a database/role creator or replication role, not a member of `rb_migrator`, and has schema USAGE without CREATE, and resolves an exact `public`-only search path.
- Added an effective privilege audit: the operations login may SELECT exactly provider-account registry, webhook inbox, and outbox; any other public table privilege or any DML/sequence/schema-creation capability is rejected. Required tables must exist and be readable before cross-tenant operations run.
- Defined DBA provisioning for the dedicated `rb_billing_ops` role and added sanitized verification queries in the PostgreSQL role-separation runbook.
- Changed PostgreSQL RLS rehearsal fixture creation and cleanup to use the tenant-scoped runtime role after setting tenant context, not the RLS-bypass operations role. The rehearsal now checks the operations role's effective read allowlist and role inheritance and search-path boundaries.
- No production role was provisioned and no PostgreSQL rehearsal was executed. PHP 8.5 runtime validation and isolated PostgreSQL credentials remain pending. All commits use `[skip ci]`; workflow files and repository settings were not changed.

### Central mail transport hardening — 2026-11-15

- Removed message subjects from Log-driver preview records; the Log transport now records only the transport event and driver, not recipient, subject, or message content.
- Removed unencrypted SMTP (`none`) from the settings UI and server-side validation. SMTP now requires STARTTLS or implicit TLS; the transport no longer disables automatic TLS negotiation.
- Added regression coverage for redacted Log-mode output and rejection of unencrypted SMTP configuration. The tests are authored but not executed; PHP 8.5 and the project runtime dependencies are still required.
- SMTP remains the first live transport, Postmark remains the launch transport, and Log remains the active default. No mail credentials were requested or changed. All commits use `[skip ci]`; no workflow file or repository setting was changed.

### Audit checkpoint command output hardening — 2026-11-15

- Sanitized checkpoint export and publication command failures. Raw exception messages are no longer written to terminal output, preventing database, key-provider, object-storage endpoint, account, or CLI diagnostic details from leaking through operator command output.
- Added a regression test for export failure output when the independent signing key is not configured.
- Existing local hash-chain verification, PostgreSQL append-only trigger/privilege boundary, signed external checkpoint verification, and immutable Object Lock publisher remain in place. Production anchoring and provider rehearsal remain owner-controlled gates.
- Tests are authored but not executed; PHP 8.5 and the project runtime dependencies are still required. All commits use `[skip ci]`; no workflow file or repository setting was changed.

### Queue worker crash visibility — 2026-11-15

- Queue telemetry now retains explicit queued/processing state and UTC processing start time. A worker crash no longer erases the in-flight sample at job start.
- Successful completion and terminal failure clear the sample; delayed retries return it to queued state with the new availability time. Queue age reporting excludes processing jobs, while a separate processing-age metric feeds the approved 15-minute alert threshold.
- Added regression coverage for queued → processing → finished lifecycle, processing-age telemetry, and stale-worker alert evaluation. Runtime tests remain unexecuted pending PHP 8.5 and dependencies.
- Every intermediate commit uses [skip ci]. No workflow file or repository setting changed; PR #2 remains Open + Draft, with no merge or deployment.

### Operator account lifecycle audit coverage — 2026-11-15

- Operator profile changes now append transactional audit evidence listing changed field names only; actual names and email addresses are not copied into audit metadata.
- Password changes now append transactional audit evidence with a boolean credential-change marker only. Passwords, hashes, reset tokens and other credential material are never included.
- Existing account deletion remains password-confirmed, transactionally audited, and blocked for the last remaining operations account. Tenant membership invite/accept/role-change/suspend/revoke remains tenant-authorized, owner-protected, and audit-recorded.
- Added a regression test for profile audit privacy. Password-change audit coverage is implemented; full runtime test validation remains pending PHP 8.5 and dependencies.
- All continuation commits carry [skip ci]. PR #2 remains Open + Draft; no workflow file or repository setting was changed, and no merge or deployment occurred.

### Retention enforcement and recovery-gate verification — 2026-11-15

- Re-verified the diagnostic retention implementation against the approved absolute policy: UTC `received_at` cutoff at 365 days, inclusive boundary, no legal-hold exception, bounded batch deletion, dry-run default, and dual execution gates (deployment feature flag plus explicit `--execute`). Cleanup is deliberately not scheduled.
- Existing feature coverage exercises report-only behavior, cleanup disabled by default, active descendant-hold expiry with immutable audit events, exact cutoff handling including active analysis, and transaction rollback when audit append fails. Audit events remain append-only and are not part of diagnostic cleanup.
- Added operator password-change audit regression coverage. Audit metadata contains only a boolean credential-change marker; no password or credential material is recorded.
- Extended the independent security review checklist for operator profile/password audit privacy and in-flight queue worker observability.
- Corrected the in-flight queue alert lifecycle so a healthy observed processing-age metric can resolve the alert, while missing telemetry cannot. Added regression coverage for stale alert, exact 15-minute boundary, healthy resolution, and telemetry gaps.
- Runtime tests remain unexecuted because the current environment lacks the required PHP 8.5 runtime and project dependencies. Do not treat this as CI evidence. All commits continue to include [skip ci]; no workflow files or repository settings were changed.

### Audit checkpoint input bounds — 2026-11-15

- Bounded both the independent public-key verifier and application checkpoint verification command to read at most 64 KiB + 1 byte before rejecting oversized input. This caps memory use even if a file grows between metadata inspection and read.
- Added regression coverage for oversized checkpoint rejection in both verification paths. Checkpoint envelope version and signing protocol remain unchanged.
- Runtime tests are not executed in this environment; final exact-head CI remains pending the single planned non-skip-ci commit.

### Audit checkpoint verifier hardening — 2026-11-15

- Applied a strict 64 KiB checkpoint input bound to all three verification entry points: independent public-key verifier, local application verification command, and checkpoint monitor. Each reads at most 64 KiB + 1 byte before rejecting oversized files, preventing unbounded file reads even if file size changes during access.
- Sanitized local checkpoint verification failures so exception messages cannot expose database/provider configuration or infrastructure details. Monitor failures remain reason-code-only.
- Added regression tests for oversized input across all verification entry points and for local verifier error redaction. Updated the independent review matrix and key-custody boundary notes.
- No key format, signing protocol, workflow file, or repository setting changed. Tests remain unexecuted pending PHP 8.5 and project dependencies.

### Backup marker input bounds — 2026-11-15

- Bounded the operational backup-success marker parser to 4 KiB + 1 byte before JSON decoding. Oversized, malformed, missing, or future-dated markers remain unavailable and therefore cannot falsely satisfy the 26-hour successful-backup alert.
- Added regression coverage for oversized marker rejection. No marker contents are logged or exposed in metrics.

### Backup and audit verification review — 2026-11-15

- Exact-version backup downloads now compare the VersionId returned by the object store with the immutable VersionId requested; a mismatch fails closed before checksum/decryption/restore can proceed.
- Audit-chain verification now treats malformed stored metadata/canonicalization errors as a sanitized integrity failure rather than allowing an uncaught exception to escape. A regression test checks that malformed payload text is not echoed.
- Backup/restore review confirms manifest size is capped at 1 MiB, encrypted archive size is capped at 5 GB, endpoint identity is bound to database name/host/port, archive authentication and both checksums are verified, and production restore requires durable approval evidence plus a fresh owner TOTP immediately before restore.
- Tests are authored but not run in this environment; PHP 8.5 and project dependencies are unavailable. Cloud Object Lock and restore rehearsal remain owner/infrastructure gates.

### Exact-version backup creation gate — 2026-11-15

- Backup creation now requires the object-store `get-object` response to return the exact VersionId requested for both the encrypted archive and its manifest. Missing, blank, or mismatched VersionIds fail the backup before the success marker can be advanced.
- Added focused regression coverage for matching, missing, blank, and mismatched immutable version identifiers.
- Production Wasabi compatibility still requires a real owner-provisioned COMPLIANCE-mode rehearsal; this code-level guard does not claim provider validation.

- Backup manifest persistence now verifies the complete byte count and applies private file permissions before hashing/upload. The secretstream file cipher also closes an already-open source stream if destination creation fails; regression coverage now includes truncated and trailing archive data rejection.

### Billing recovery and external audit-anchor edge-case review — 2026-11-15

- Webhook dead-letter recovery now locks the active, verified provider-account mapping inside the same tenant-scoped transaction that locks the tenant and webhook event. This serializes replay authorization against concurrent provider-account deactivation; tenant identity, provider/event identity, and payload remain immutable.
- External audit-anchor exact-version downloads now read at most 64 KiB + 1 byte before returning content, matching the independent/application checkpoint verifier limit and preventing an unexpectedly oversized remote object from causing an unbounded memory read.
- Added an S3-adapter regression test for oversized exact-version downloads. Existing billing recovery tests already cover inactive/unverified mapping rejection, tenant authorization, replay ceiling, state reset, and immutable audit evidence; publisher tests cover ambiguous-create lookup-only recovery, exact-version read-back, checksum, and COMPLIANCE retention failures.
- Tests remain unexecuted pending PHP 8.5 and project dependencies. No provider/cloud rehearsal was run. No workflow file or repository setting was changed; all commits in this batch include [skip ci].

### Consolidated release-readiness checklist update — 2026-11-15

- Extended the production-pilot checklist with explicit provider selection/adapter, tenant/provider/account binding, append-only payment/adjustment ledger, PostgreSQL billing-operations RLS, at-least-once consumer deduplication, bounded audited replay, aggregate operations reporting, and alert-delivery gates.
- Extended the independent security-review pack with billing webhook/outbox recovery trust boundaries and adversarial cases, including concurrent provider-account deactivation during replay. Provider activation remains disabled.
- Consolidated owner-controlled release inputs in the pilot checklist: cloud/Wasabi and Object Lock provisioning; secret-manager and Ed25519 key custody; PostgreSQL role provisioning; isolated PostgreSQL + WHMCS/MariaDB + uploaded-file recovery rehearsal; payment-provider/merchant ownership/grace/pricing decisions; production SMTP and alert delivery; PHP 8.5 validation; independent review; and final release authorization.
- No workflow file or repository setting was changed. Tests remain unexecuted because PHP 8.5 and project dependencies are unavailable in this environment; no CI run has been triggered. PR #2 remains Open + Draft, unmerged and undeployed.

### Hybrid SaaS entitlement and membership follow-up — 2026-11-15

- Closed a tenant quota enforcement gap: diagnostic snapshot ingestion now checks `diagnostics_per_period` inside the same transaction as snapshot persistence and audit append, holding the active subscription row lock to serialize concurrent requests. The inclusive exact-limit boundary rejects the next snapshot; absent limits fail closed. Concurrent identical deliveries re-check the committed snapshot and preserve idempotent responses even when the winning upload consumes the last slot.
- Repaired malformed namespace separators in `TenantResourceLimitService`; the shared service now loads as valid PHP and continues to guard tenant integration provisioning.
- Closed a membership invitation lifecycle gap: implemented account-bound `invitationFor` lookup, dispatch invitation mail after the membership transaction commits, and permit safe reissue of pending invitations while still rejecting active/suspended duplicates. Revoked membership rows can be re-invited without violating the unique tenant/user key.
- Repaired malformed billing command registration in `bootstrap/app.php` and added the missing `ReportTenantBillingOperations` import so the billing operations report is discoverable by Artisan.
- Added tests for exact diagnostic quota boundary and missing quota configuration. Existing invitation email tests now exercise the completed after-commit queue path and account-bound signed invitation route. Tests remain unexecuted pending PHP 8.5 and project dependencies.
- Expanded production pilot gates for hybrid SaaS tenant mapping, tenant quotas, versioned plan/entitlement approval, distinct platform-vs-tenant billing, RLS rehearsal, and custom-domain/white-label security. Custom domains remain explicitly unimplemented and disabled pending separate design/review.
- No workflow file or repository setting changed; every commit in this batch includes [skip ci]. No CI run, merge, production migration, or deployment was performed.
- Additional entitlement integrity: database triggers now make each tenant subscription's commercial snapshot immutable after provisioning (plan code, billing mode, currency, unit price, and entitlement JSON); period/status transitions remain available for renewals. Added regression coverage for attempted entitlement and price mutation. `requireLimit` also rejects negative requested quantities.
- Extended quota enforcement to tenant member seats: active, invited, and suspended memberships consume the configured `members` allowance; revoked memberships do not. A pending invitation resend reuses its seat, while new or revoked-member invitations fail at the exact limit. Tenant row locking serializes invite/seat allocation. Lifecycle and invitation fixtures now include explicit seat entitlements, with exact-capacity and resend coverage.
- Subscription integrity follow-up: PostgreSQL and SQLite now enforce a single current subscription snapshot per tenant across trialing/active/past_due/suspended states. Plan replacement must terminalize the prior snapshot before inserting the next; a regression test rejects overlapping current subscriptions. Updated the reactivation lifecycle test to supply the fully settled invoice/payment evidence required by the implementation rather than attempting an unbacked status flip.

### Central staff RBAC implementation — 2026-11-15

- Added a dedicated central staff access table with a fixed permission allowlist: tenants, billing, support, settings, and audit. This application authorization is separate from PostgreSQL runtime, migration, read-only, and billing-operations roles.
- The configured OPERATIONS_OWNER_EMAIL identity is the sole super admin and always receives all permissions without a staff row. Staff creation, permission changes, and removal are super-admin-only; staff cannot escalate themselves.
- Added immutable, transactional audit evidence for staff creation, before/after permission changes, and removal. Password material is excluded from audit metadata.
- Added central staff administration UI and permission-based access to platform mail settings. Existing tenant membership routes retain their independent tenant-scoped authorization and are not accidentally gated by central staff permissions.
- Protected the configured super admin from profile deletion and changing the configured email identity.
- Added feature coverage for super-admin all-permission behavior, explicit staff allowlists, denied self-escalation, settings permission enforcement, audit events, and super-admin profile protection.
- Documented OPERATIONS_OWNER_EMAIL bootstrap and central RBAC operations in docs/operations/central-staff-rbac.md. Empty permissions are supported for newly created or existing staff.
- Current branch head: 5462ac5669df0e4c0dcbaa0ab68ff7e998e291a0 at the time of this entry. All continuation commits use [skip ci]. PHP 8.5 test execution and exact-head CI remain deferred until the final one-shot commit. No workflow file, repository setting, production resource, or deployment was changed.

### Final release-readiness implementation pass — 2026-11-15

- Completed central staff RBAC implementation and added strong-password validation, staff create/update/delete lifecycle tests, permission allowlist tests for all five central areas, settings-route authorization tests, transactional audit-failure rollback coverage, and configured-super-admin self-protection tests.
- Added missing operations-user lifecycle tests for hidden password prompts, strong password enforcement, confirmation mismatch, duplicate email rejection, password hashing, and immutable creation audit evidence.
- Rechecked diagnostic queue recovery: five-minute scheduler, bounded batches, queue retry/visibility plus analysis lease guard, expired-record suppression, reserved-attempt release, ambiguous submitted-attempt hold, and settled-attempt no-recharge behavior are covered by feature tests.
- Rechecked retention: UTC inclusive 365-day received_at cutoff, report-only mode, dry-run default, explicit execute + disabled-by-default guard, transactional deletion evidence, active-hold expiry, and indefinite audit retention are covered/documented. No cleanup schedule is registered.
- Updated the production checklist to separate deferred tenant onboarding/provider activation/white-label/custom-domain work from this release. Replaced stale owner-pending list with Shahrukh's explicit environment setup checklist: Wasabi Object Lock COMPLIANCE, signing keys, production DB roles, mail credentials, PHP 8.5 local test, and backup/restore rehearsal. Shahrukh's security-review and release approval are recorded as received.
- Updated deployment mail guidance to the central Log/SMTP/Postmark configuration and documented OPERATIONS_OWNER_EMAIL and the four PostgreSQL application/service identities.
- Compared the actual branch against handoff head 8364f4ac8815aa75422e26a5db72ef7f5fb5e411: 38 commits ahead, zero behind; changed-file comparison confirms no workflow file or repository setting changed in this implementation pass.
- Current exact branch head: cc95d2570e64af1a040d3db2a921a38840a4f01f. All 38 commits in this pass include [skip ci]. Exact-head CI has intentionally not been triggered yet; it will run once on the final non-[skip ci] commit after remaining review/documentation is complete. PHP 8.5 test execution is not available in this environment.

### One-shot release CI handoff — 2026-11-15

- PR #2 description now contains the final release-readiness summary, verification boundaries, and the complete Shahrukh setup checklist.
- Shahrukh's security-review and release approval are recorded as received. Owner-run Wasabi/Object Lock, production signing-key custody, PostgreSQL role provisioning, mail credentials, PHP 8.5 local suite, and backup/restore rehearsal remain documented as environment setup; repository engineering does not wait on them.
- Deferred tenant onboarding, commercial plan/provider activation, white-label, and custom domains are explicitly outside this release.
- Pre-final branch head: e2e7104a7e0a39c4f2972340fa37f07f6fbc162f. The 39-commit finalization pass is 0 behind the verified handoff head and changed no workflow files or repository settings. Every intermediate commit includes [skip ci].
- This entry is the final documentation change before the single non-[skip ci] commit. That commit is intended to trigger the six required CI jobs once. PR remains open and Draft until the exact-head CI result is green; no deployment or production operation is authorized.

### CI bootstrap failure and repair — 2026-11-15

- The first final CI attempt, run #43710503977, failed during Composer autoload/package discovery before application tests started. Root cause: 23 billing source files had doubled namespace separators in PHP namespace/import declarations; Composer reported PSR-4 skips and PHP stopped at DispatchTenantBillingOutbox.php line 3.
- Repaired namespace/import declarations across all reported billing commands, events, services, contracts, models, and DTOs. Also repaired doubled global Throwable references in TenantBillingWebhookInbox. Verified representative repaired files contain valid single-separator PHP declarations and no remaining doubled separators.
- These are [skip ci] remediation commits, as required. No workflow file or repository setting changed. The previous run's remaining jobs are not a valid green result for this head.
- Current remediation head: 50c4c8f43ff6503f32c4815ab366c66af614c913. The next single non-[skip ci] commit will trigger the required six-job CI rerun; PR #2 remains open and Draft until that exact-head run passes.

### Repaired-head CI rerun handoff — 2026-11-15

- Confirmed run #43710503977 completed: WHMCS PHP 8.1 passed; general CI, PostgreSQL, MySQL 8.4, MariaDB 11.4, and SQL Server 2022 stopped during Composer setup because of the same doubled billing namespace declarations. No app test suite executed in those jobs.
- Repaired every billing namespace/import declaration reported by Composer and the malformed global Throwable references. Representative files were re-fetched and verified to contain single PHP namespace separators and no doubled separators.
- PR #2 description now records the first CI result and the remediation boundary. Current pre-rerun head: 582f084fb733ec8fe600a4053ceb4512fe7ad6c7; 65 commits ahead and 0 behind the verified handoff head; no workflow file or repository setting changes.
- This is the final documentation entry before the one additional non-[skip ci] commit. That commit triggers the six-job rerun on the repaired head. Keep PR #2 Draft until every required job passes.

### CI #714 findings and second remediation — 2026-11-15

- Repaired-head run #43710796467 completed with failures: CI Setup Application found a missing closing SidebarMenu JSX tag; MySQL/MariaDB retention tests and PostgreSQL AI-budget tests found three billing migrations with doubled Illuminate import separators; PostgreSQL also exposed non-idempotent CREATE FUNCTION statements because the test workflow runs fresh migrations more than once. SQL Server retention and WHMCS PHP 8.1 jobs passed.
- Fixed the sidebar JSX closing tag and all three billing migration import declarations.
- Changed PostgreSQL trigger-function DDL to CREATE OR REPLACE FUNCTION in migrations 000005, 000007, 000009, 000010, and 000011 so repeated fresh-migration setup is idempotent while preserving trigger function identity.
- These remediation commits use [skip ci]. No workflow or repository setting changed. The next final non-[skip ci] commit will trigger one more six-job run on the fully repaired head.

### Final CI rerun handoff after second remediation — 2026-11-15

- Corrected the app-sidebar JSX structure and the three billing migration imports found by run #714.
- PostgreSQL migrations 000005, 000007, 000009, 000010, and 000011 now use CREATE OR REPLACE FUNCTION for all guard/audit trigger functions, preventing collisions when the CI setup and RefreshDatabase each run fresh migrations in the same database.
- PR description records both failed CI attempts and their exact causes. Shahrukh's setup checklist and approval status remain unchanged.
- Pre-final head: d3062bebbecf604426636390b02f1f6d4f7d0e69; 76 commits ahead and 0 behind the handoff head; no workflow or repository settings changed.
- All remediation commits after the failed runs include [skip ci]. This is the final documentation change before the single additional non-[skip ci] commit; PR #2 stays Draft until all six jobs pass.

### CI #715 findings and final test/style remediation — 2026-11-15

- Run #43711132638 completed: MySQL, MariaDB, SQL Server, and WHMCS PHP 8.1 passed. The general CI job failed only at Vite Plus formatting validation (13 reported Markdown/TSX files); the PostgreSQL job reached tests and found stale tenant fixtures, outdated AnalyzeDiagnosticRun constructor calls, an unmatched full provider URL fake, and a recovery query that attempted FOR UPDATE across a nullable outer join.
- Reformatted all 13 reported Markdown/TSX files to the repository's 80-column Vite Plus format.
- Updated AI-budget test fixtures to copy the immutable tenant_id to diagnostic runs; updated AnalyzeDiagnosticRun tests to pass the captured owner and tenant context; corrected the provider fake to match the full HTTPS URL and asserted the intended entitlement exception.
- Reworked stale diagnostic recovery tenant-status filtering from a LEFT JOIN to a correlated EXISTS predicate. The transactional row lock now covers only the diagnostic run and its required integration inner join, avoiding PostgreSQL's FOR UPDATE nullable-side restriction while preserving active-tenant filtering.
- These are [skip ci] remediation commits. The next single non-[skip ci] commit will trigger one more six-job matrix; all prior run #715 failures are from the pre-remediation head.

### One-shot final CI after PostgreSQL and formatting fixes — 2026-11-15

- PR #2 description records all three earlier CI attempts, their exact failures, and the remediation evidence.
- Current pre-final head: 50e66e56babac8d7579b79ba6b2bc622f581ca5e; 96 commits ahead and 0 behind the verified handoff head. No workflow files or repository settings changed.
- The source/migration repairs, 13-file formatter pass, tenant-context test corrections, and PostgreSQL-safe recovery locking are complete. All intermediate remediation commits use [skip ci].
- This is the final documentation change before the next single non-[skip ci] commit. Keep PR #2 open and Draft until every required CI job passes.

### CI #716 findings and final remediation — 2026-11-15

- Run #43711904818 completed: MySQL, MariaDB, SQL Server, and WHMCS PHP 8.1 passed. The CI job still found formatting drift in the Markdown/TSX set. PostgreSQL tests exposed a missing global RuntimeException import, a tenant-null assertion referencing an undefined fixture, a deliberate tenant-remap FK violation that needed a nested savepoint, and a provider usage callback fixture whose JSON body needed normalization.
- Normalized the ten reported Markdown documents to preserve prose lines while removing duplicate blank lines/trailing spaces and normalizing list markers. Reformatted the three reported TSX files to the repository width and syntax conventions.
- Corrected PostgreSQL tests to assert the intended null tenant attribution, isolate the expected FK rejection in a nested transaction/savepoint, refresh the rejected in-memory integration, and verify immutable tenant attribution. Imported global RuntimeException in the analysis job.
- Made the analyzer normalize JSON-encoded response bodies before extracting confirmed usage, and changed the failing-provider test to return explicit JSON from a catch-all fake. The queue recovery EXISTS predicate remains in place to avoid PostgreSQL nullable outer-join row locks.
- Sorted imports in the affected application and feature-test files. All post-#716 remediation commits use [skip ci].
- Pre-final head: 28bab78b3822ba057a214effc27623f9e9cd1418; 134 commits ahead and 0 behind the verified handoff head; no workflow file or repository setting changed. The next single non-[skip ci] commit triggers the next six-job matrix.

### One-shot CI handoff after #716 remediation — 2026-11-15

- PR #2 description now records runs #713 through #716 and their remediation evidence. Shahrukh's environment setup checklist and approval status remain unchanged.
- Pre-final head: 8c4952822ec7c7d89596db401b649c0bfa29ffa4; 135 commits ahead and 0 behind the verified handoff head. No workflow files or repository settings changed.
- This is the final documentation change before the next single non-[skip ci] commit. The exact-head six-job matrix must be fully green before PR #2 is marked ready or merged.

### CI #717 findings and final follow-up — 2026-11-15

- Run #43713031267 completed: MySQL, MariaDB, SQL Server, and WHMCS PHP 8.1 passed. PostgreSQL exposed two remaining issues: a tenant-remapping constraint test needed a nested savepoint, and the failed-provider usage fixture required explicit raw-body JSON normalization. Vite Plus still flagged five Markdown documents and two TSX pages.
- Wrapped checklist continuations were normalized in the remaining Markdown documents; the mail-settings and central-staff TSX pages were brought under the configured width and syntax formatting.
- The tenant-remapping constraint assertion now runs in a nested DB transaction/savepoint and refreshes the model afterward. The analyzer now parses the raw response body, then falls back to the response decoder and normalizes object-shaped usage before invoking the after-response settlement callback.
- These follow-up changes use [skip ci]. Current head after remediation: 24f4971747de2855700b05493049fd7c73f5b33d; 144 commits ahead and 0 behind the verified handoff head; no workflow file or repository setting changed. One final non-[skip ci] commit is required to verify the repaired head.

### One-shot final CI handoff after #717 — 2026-11-15

- PR #2 description records all five CI attempts and the fixes applied after each failed run.
- Pre-final head: a30afb0a39bbf44acd76f3ec5b24770a02953f43; 145 commits ahead and 0 behind the verified handoff head. No workflow files or repository settings changed.
- The final PostgreSQL savepoint/provider-body fixes and remaining Markdown/TSX normalization are complete. All remediation commits use [skip ci].
- This is the final documentation change before the next non-[skip ci] commit. PR #2 remains open and Draft until all six jobs pass.

### CI #718 findings and final follow-up — 2026-11-15

- Run #43713467134 completed: MySQL, MariaDB, SQL Server, and WHMCS PHP 8.1 passed. PostgreSQL had one remaining failing assertion in the provider-usage callback test; Vite Plus flagged three Markdown documents and two TSX pages.
- Replaced the provider test's callback-generated response with a literal JSON response body matched by a catch-all fake, retaining the request assertion. The analyzer now decodes raw JSON, falls back to the framework decoder, and normalizes object-shaped usage before invoking the callback.
- Wrapped the remaining long prose paragraphs in the three reported Markdown documents, collapsed duplicate section spacing, and aligned the mail-settings/staff TSX pages with the repository formatter. The app sidebar and other formatter targets now pass.
- These changes are [skip ci] remediations. Current head: d8e65767d6d6aec57a1dff49b0a87a4fb7bbe262; 157 commits ahead and 0 behind the verified handoff head; no workflow file or repository setting changed. One final non-[skip ci] commit will verify the repaired head.

### One-shot final CI handoff after #718 — 2026-11-15

- PR #2 description records runs #713 through #718 and the remediation evidence after each failure.
- Pre-final head: 53b0201c005be4449e2a7fbe91db00fb80b6b5b0; 158 commits ahead and 0 behind the verified handoff head. No workflow files or repository settings changed.
- The final provider-response fixture, PostgreSQL savepoint assertions, and remaining formatter corrections are complete. All intermediate remediation commits use [skip ci].
- This is the final documentation change before the next non-[skip ci] commit. PR #2 remains Draft until every required CI job passes.

### CI #719 findings and final formatting/provider correction — 2026-11-15

- Run #43713904140 completed: MySQL, MariaDB, SQL Server, and WHMCS PHP 8.1 passed. PostgreSQL's only failing assertion remained the provider usage callback; Vite Plus reported two Markdown documents and two TSX pages.
- Wrapped the remaining long checklist/reviewer bullets, normalized section spacing, and aligned short JSX elements with the configured print width.
- The provider test now uses a fresh literal-JSON response for each request (rather than reusing a response object), and retains the named afterResponse callback plus request assertion. This avoids a consumed response stream masking the usage callback.
- These are [skip ci] remediations. Current head: bd977b775a5da4f07b7e4b08f8a8242d920759f1; 167 commits ahead and 0 behind the verified handoff head. No workflow file or repository setting changed. One final non-[skip ci] commit will verify the repaired head.

### Final CI handoff after #719 — 2026-11-15

- PR #2 description records runs #713 through #719 and each remediation cycle.
- Pre-final head: 6bcf8e7cc86fe7a4e344a38bac864f107a20ddf5; 168 commits ahead and 0 behind the verified handoff head. No workflow files or repository settings changed.
- The fresh provider-response fake, PostgreSQL-safe tenant-remap tests, Markdown normalization, and TSX formatting adjustments are complete. All remediation commits use [skip ci].
- This is the final documentation change before the next non-[skip ci] commit. Keep PR #2 Draft until all six jobs pass.

### PostgreSQL and billing test remediation before final CI — 2026-11-15

- Verified the live PR head before the final CI trigger: `2a9d641c24703cec6cd07a3a2f8894feb76c8005`.
- Corrected doubled PHP namespace separators in the standalone PostgreSQL
  billing RLS rehearsal and the billing outbox feature test.
- Added nested transaction/savepoint isolation around expected tenant/composite-FK
  and billing immutability violations so PostgreSQL's aborted-statement state
  does not leak into later assertions.
- The dedicated billing RLS rehearsal remains an explicit owner-run check
  because it requires two real tenant fixtures and a separately provisioned
  `pgsql_operations` role. The production role setup remains documented; no
  credentials are stored in the repository.
- All remediation commits before this entry use `[skip ci]`. This documentation
  commit is the single final non-`[skip ci]` commit intended to trigger the
  six-job matrix. Exact-head CI is pending; no workflow file, repository
  setting, production database, or deployment target was changed.

### CI #721 findings and follow-up — 2026-11-15

- Run #721 on `182559e51c6600645b33d59b2a17fc08ceea2509`: WHMCS PHP 8.1,
  MySQL, MariaDB, and SQL Server passed. PostgreSQL migrations and bootstrap
  completed; its focused suite reported 68 passed, 2 skipped, and one failed
  provider-error usage-callback assertion.
- The provider test fixture did not reliably exercise the raw JSON response
  path. It now uses a catch-all HTTP fake with a fresh JSON-encoded response.
  The callback assertion remains in place; no billing assertion was removed.
- Main CI completed the frontend build, then reported formatting issues in
  this document and `security-review-readiness-pack.md`. The previous bulk
  reflow was reverted to preserve the repository's Markdown formatting style.
- Follow-up commits after run #721 use `[skip ci]`. Current pre-final head:
  `3baea82ac2130e96ade5c518ef5809b2d790d2c5`. The next single non-`[skip ci]`
  commit will trigger the exact-head six-job matrix. PR #2 remains Draft until
  every required job passes.

### CI #722 findings and follow-up — 2026-11-15

- Run #722 on `40c47184a19c36188f3d3cae4e250e699b45ed98`: WHMCS PHP 8.1,
  MySQL, MariaDB, and SQL Server passed. PostgreSQL completed bootstrap and
  reported 68 passed, 2 skipped, and one failed provider-usage callback test.
  Main CI built the frontend, then reported formatting issues in two Markdown
  documents.
- Replaced the provider test's URL-specific fake with a catch-all fake that
  returns a fresh JSON-encoded 500 response. The callback assertion remains.
- Reverted the broad Markdown prose reflow. The engineering progress document
  retains its existing historical wrapping; only the newly added remediation
  notes are wrapped. The security review pack retains its existing wrapping,
  with targeted list wrapping and spacing corrections.
- Follow-up commits after run #722 use `[skip ci]`. Current pre-final head:
  `10c173fb6f27ec608e4ba10599873836e42d610c`. This is the final documentation
  entry before the next single non-`[skip ci]` commit. PR #2 remains Draft
  until every required CI job passes.

