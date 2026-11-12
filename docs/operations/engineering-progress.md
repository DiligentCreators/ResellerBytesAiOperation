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

- Dashboard, integrations, diagnostic runs, findings, addon download, and addon setup routes are protected by `auth` and `verified`; no explicit operator-role/authorization middleware was observed on these routes.
- Fortify public registration is not enabled in the inspected feature configuration. The explicit operator-provisioning command creates a verified account and applies a strong password policy.
- Two-factor authentication and passkeys are enabled as available Fortify features, but the inspected dashboard routes do not enforce completed MFA/passkey enrollment. The provisioning command only instructs the operator to configure one after first sign-in.
- A self-service profile deletion route is available to authenticated, verified users. Confirm the intended account lifecycle and protect against accidental removal of the last usable operator account.
- The trusted-operator model may be acceptable if all accounts are provisioned through a controlled process, but this assumption needs an explicit production control and verification of all account-creation paths.

### Audit-event integrity review

- `AuditEvent` now rejects Eloquent model update and delete operations; creation remains allowed. A focused Pest feature test covers append, update rejection, and delete rejection.
- This is an application-layer guard only. Query-builder updates/deletes, raw SQL, privileged database access, and database administrators can bypass it; no database trigger/privilege boundary or separately controlled append-only export has been implemented or verified. Do not describe the audit store as tamper-proof.
- Stronger production assurance still requires a separately approved database-level control and/or export to separately controlled append-only storage.

### Queue and package review

- `AnalyzeDiagnosticRun` uses a database row lock, a 100-second analysis lease, a 90-second job timeout, three attempts, and bounded backoff. Provider exceptions set a safe error code and are rethrown for queue retry; the failed hook records terminal failure.
- The Redis queue uses a 120-second `retry_after`, which is longer than the job timeout and lease. This is a coherent baseline, but crash/recovery behavior for jobs left in `queued` state should be covered by an explicit reconciliation test or mechanism.
- Snapshot ingestion records the accepted snapshot and its audit event transactionally, then queues analysis. Dispatch failures are handled in the inspected controller paths, but a process interruption between persisting `queued` and successful enqueue remains a recovery scenario to validate.
- Manual reanalysis checks status on a previously loaded model and then changes it before dispatch; the check-and-transition is not atomic, so concurrent requests can enqueue duplicate jobs. The job's row lock prevents simultaneous analysis execution, but does not eliminate duplicate queue entries or duplicate request audit events.
- Both ingestion and manual reanalysis retain a crash window between persisting `queued` and successful queue publication. A durable outbox or bounded reconciliation mechanism is still needed; do not assume the current exception handlers cover process termination.
- The authenticated addon download controller builds the ZIP from the addon source directory, skips symlink files, and embeds per-file SHA-256 checksums plus a version. The checksum manifest is inside the same archive, so it detects accidental corruption only when compared with a separately trusted checksum; it is not an independent authenticity signature.
- No confirmed production-blocking defect was established in this focused source pass. The review is not a substitute for a full independent penetration test.

## Work completed on this branch

- Expanded the standalone signed API client test to exercise HTTP 500 responses, malformed JSON, empty responses, and cURL transport failures in addition to successful signing, TLS/redirect settings, and oversized response handling.
- Corrected the Pint strict-types formatting failure in the signed API client test. This correction is pending CI verification.
- Completed a focused source review of route authorization, operator provisioning, audit-event model behavior, queue execution/retry handling, and addon ZIP/checksum generation. Findings above are documented for remediation or operator disposition.
- Added an Eloquent-level append-only guard to `AuditEvent` and a focused Pest feature test. Execution of this new test and the full suite is pending; this change does not provide database-level immutability.
- Pull request: [#2 — release-readiness review](https://github.com/DiligentCreators/ResellerBytesAiOperation/pull/2).

## Remaining release gates

1. Approve and implement the stronger audit-integrity boundary: database-level controls and/or export to separately controlled append-only storage. The current Eloquent guard is defense-in-depth only.
2. Confirm the trusted-operator account model, verify every account-creation path, and decide whether MFA/passkey enrollment must be mandatory before dashboard access. Protect the last operator account from accidental deletion.
3. Add or verify reconciliation for diagnostic runs persisted as `queued` but not actually enqueued; test dispatch failures, process interruption, duplicate dispatch, and stale leases.
4. Provide an independently trusted addon ZIP checksum or signature for deployment verification; the embedded manifest alone is not an authenticity guarantee.
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
