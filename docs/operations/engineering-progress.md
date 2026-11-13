# Engineering progress — release readiness

Last reviewed: 2026-11-12

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

This document records review evidence; it does not certify the application as production-ready.

## Operator-approved budget and retention policy — 2026-11-12

- Global AI budget targets approved: USD 5.00 per UTC day and USD 50.00 per UTC calendar month.
- Diagnostic snapshot retention target approved: 90 days, including dependent findings and recommendations.
- Audit-event retention target approved: 2,555 days (approximately seven years), separate from diagnostic retention.
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

The operator selected the hybrid business model: validate ResellerBytes AI Operations internally first, then commercialize the same platform for external hosting providers.

Approved architecture decisions:

- Hosting: one shared application and shared database with strict tenant isolation. ResellerBytes is a normal tenant, not a privileged bypass.
- Billing: automated recurring payments and manual invoices/payments through one subscription, billing-event, and entitlement model.
- Branding: launch under the ResellerBytes brand while designing tenant-configurable branding and a later white-label path.
- Architecture style: modular monolith with queue-based diagnostic processing; no microservices split for the first commercial release.
- External customer onboarding is blocked until tenant ownership is backfilled, cross-tenant access tests pass, and server-side entitlements are enforced.
- Automated billing requires verified, idempotent provider webhooks; manual payments require auditable recording and adjustment history.
- Custom domains and advanced white-label features are deferred until domain verification, TLS, routing, and tenant-isolation controls are implemented.

The approved target architecture and phased delivery plan are documented in hybrid-saas-architecture-and-roadmap.md. The overall first-commercial-release planning estimate is approximately 5–9 weeks, subject to Phase 1 verification, schema complexity, payment-provider access, and beta feedback.

### Current CI status correction

- Latest branch-head run 43255558196 completed with the main ci job failed and whmcs-addon-php81 passed.
- The main CI failure is confirmed as Markdown formatting in docs/operations/ai-budget-and-retention-policy.md and docs/operations/engineering-progress.md; the frontend build completed successfully in that run.
- Do not treat the branch as green or release-ready until formatting is corrected and a new consolidated CI run passes.
- No merge, deployment, migration, or destructive pruning is authorized by this architecture decision.

## Current branch-head verification — 2026-11-12

This section supersedes earlier CI-status statements above where they conflict with the latest verified repository evidence.

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

A green CI run is necessary but does not close these release gates or authorize merge, migration, or deployment.

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

This section supersedes earlier CI and recovery-status entries where they conflict with the latest verified evidence.

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

A green CI run is necessary but does not close these release gates or authorize merge, migration, or deployment.

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

- Verified exact branch head `69ccaecb40f59ac3d18b61e435d1f5eb0d38d7f9`: all four required CI jobs passed — general CI, PostgreSQL AI-budget/audit concurrency suite, MySQL 8.4 integration-retention suite, and WHMCS PHP 8.1 compatibility. Run: https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43506078800
- Added database-level DELETE protection for retained WHMCS integration history. Regression coverage now exercises SQLite, PostgreSQL, and MySQL 8.4 query-builder deletion attempts. MariaDB and SQL Server code paths remain unverified; PostgreSQL is the documented operations-app database.
- Added `diagnostics:retention:report --days=90`, a report-only command that counts old diagnostic runs and dependent findings/recommendations. It never deletes data and explicitly warns that legal holds are not evaluated. A receipt-time index supports the report.
- Added Ed25519 audit checkpoint export and verification commands. The exporter verifies the complete local chain while holding the chain-head row lock, signs a minimal checkpoint, and emits the exact JSON artifact. The verifier checks signature, environment, checkpoint predecessor (when supplied), local chain integrity, and the anchored prefix; it reports the unanchored tail.
- Added key-pair consistency and WHMCS-addon-key reuse rejection. Feature tests cover signature tampering, predecessor mismatch, unanchored-tail reporting, key reuse, and key-pair mismatch. PostgreSQL CI now exercises checkpoint export/verification with the PostgreSQL row-lock path.
- Added independent PostgreSQL migration/runtime role provisioning guidance and expanded the production-pilot checklist with runtime privilege, retention, signed-anchor, restore, and evidence gates.
- External Object Lock storage upload, remote read-back, alert delivery, independent verifier scheduling, production database role provisioning, and backup/restore rehearsal are not active. They require separate infrastructure, credentials, and operator approval.
- **Shahrukh ke liye:** approve legal-hold authority/release workflow and retention exceptions; approve Object Lock retention; provision the separate cloud account/bucket and Ed25519 key custody; confirm the actual PostgreSQL database/schema and Forge secret-manager role setup; and schedule an isolated backup/restore rehearsal. Do not send private keys or production database passwords through chat or commit them.
- PR #2 remains open and Draft. No merge to `main`, production migration, live WHMCS change, or deployment has occurred.
