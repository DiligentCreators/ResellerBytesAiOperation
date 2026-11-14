# Legal-hold and diagnostic-retention runbook

## Authority and prerequisites

- Configure `OPERATIONS_OWNER_EMAIL` to the exact email address of the one approved Project Owner account. An empty or unmatched value fails closed.
- The account must exist in the operations application, have a verified email address, and have MFA enrolled and confirmed.
- Every placement and release command prompts for a fresh six-digit Owner TOTP code. Do not pass TOTP codes as command-line arguments or store them in shell history.
- Apply configuration through the approved deployment secret/configuration process. Do not commit a real Owner email or credentials to the repository.
- Legal-hold scope is limited to `diagnostic_run`, `diagnostic_finding`, and `diagnostic_recommendation`. A hold on a finding or recommendation records the matter against its parent diagnostic run and dependent records. A legal hold does not extend diagnostic content retention beyond 365 days.

## Place a legal hold

Use a controlled case/evidence reference and a concise reason. Do not place customer payloads, case documents, credentials, or sensitive evidence in command arguments or audit metadata.

```sh
php artisan legal-hold:place diagnostic_run <DIAGNOSTIC_RUN_UUID> \
  --reason="Preserve records for a formal legal matter" \
  --evidence="CASE-2026-0042"
```

Supported target types:

- `diagnostic_run`
- `diagnostic_finding`
- `diagnostic_recommendation`

The command checks the configured Owner identity, verified email, MFA enrollment, and fresh TOTP. It rejects targets already at or beyond the 365-day retention cutoff. The hold row and `legal_hold.placed` immutable audit event are committed in one database transaction. If the audit append fails, the hold creation rolls back. Duplicate active holds for the same target are rejected.

## Release a legal hold

Release requires a separate explicit Owner action, a reason, a controlled evidence reference, and a fresh TOTP code.

```sh
php artisan legal-hold:release <LEGAL_HOLD_UUID> \
  --reason="Formal matter closed and release approved" \
  --evidence="CASE-2026-0042-CLOSE"
```

The hold status change and `legal_hold.released` audit event are committed atomically. If the audit append fails, the hold remains active. A released hold is not deleted; its placement/release history remains available.

## Diagnostic retention reporting and cleanup

The policy is an absolute 365 days from UTC `diagnostic_runs.received_at`. No exception is permitted, including active legal holds. Legal-hold records and their immutable audit history remain retained, but diagnostic runs, findings, recommendations, and payloads expire at 365 days.

First inspect candidates using the report-only command:

```sh
php artisan diagnostics:retention:report --days=365
```

The report command never deletes data. It reports age-based candidates only; active legal holds do not change the 365-day eligibility cutoff.

The separate cleanup command is bounded to at most 500 diagnostic runs per invocation and does not exempt `received`, `queued`, or `analyzing` states. At the 365-day cutoff it locks each diagnostic root, marks any active direct/descendant legal-hold records `expired_by_retention`, appends a `legal_hold.expired_by_retention` immutable audit event for each, appends the `diagnostics.retention.deleted` event, and deletes the diagnostic content in one transaction. Analysis jobs and stale-queue recovery refuse to start work for records at or beyond the cutoff. If an audit append fails, the transaction aborts and the diagnostic content is not deleted.

Dry-run is the default:

```sh
php artisan diagnostics:retention:prune --limit=100
```

Execution requires both of the following independent gates:

1. A release owner has explicitly approved enabling `DIAGNOSTICS_RETENTION_CLEANUP_ENABLED=true` through the deployment configuration process after exact-SHA CI, backup/restore, and operational release gates pass.
2. The operator explicitly supplies `--execute`:

```sh
php artisan diagnostics:retention:prune --limit=100 --execute
```

The feature flag is false by default. The command is not scheduled. Do not enable it during development or deployment preparation, and do not execute it against production until separately approved. Audit events are retained indefinitely and are never targeted by this cleanup.

## Fail-closed response

- Missing/incorrect Owner configuration, unverified email, missing MFA, or invalid TOTP: no hold change.
- Target missing, unsupported, or already actively held: no hold change.
- Audit append failure during placement/release: transaction rollback.
- Legal holds do not grant retention exceptions; active hold records are marked `expired_by_retention` when their diagnostic target reaches 365 days.
- Audit append failure during cleanup: transaction rollback; no deletion.
- Keep the deployment cleanup feature flag false while investigating any unexpected result.

## Release evidence still required

- Exact PR-head CI is green for every required job.
- External 365-day Object Lock bucket and independent Ed25519 key custody are provisioned and independently verified.
- Production database runtime/migration role separation and negative privilege tests are evidenced.
- Isolated backup/restore rehearsal and audit checkpoint read-back are successful.
- Project Owner separately approves any production configuration change. This runbook does not authorize a production migration, merge, or deployment.
