# Legal-hold and diagnostic-retention runbook

## Authority and prerequisites

- Configure `OPERATIONS_OWNER_EMAIL` to the exact email address of the one approved Project Owner account. An empty or unmatched value fails closed.
- The account must exist in the operations application, have a verified email address, and have MFA enrolled and confirmed.
- Every placement and release command prompts for a fresh six-digit Owner TOTP code. Do not pass TOTP codes as command-line arguments or store them in shell history.
- Apply configuration through the approved deployment secret/configuration process. Do not commit a real Owner email or credentials to the repository.
- Legal-hold scope is limited to `diagnostic_run`, `diagnostic_finding`, and `diagnostic_recommendation`. A hold on a finding or recommendation protects its parent diagnostic run and all dependent records from retention cleanup.

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

The command checks the configured Owner identity, verified email, MFA enrollment, and fresh TOTP. The hold row and `legal_hold.placed` immutable audit event are committed in one database transaction. If the audit append fails, the hold creation rolls back. Duplicate active holds for the same target are rejected.

## Release a legal hold

Release requires a separate explicit Owner action, a reason, a controlled evidence reference, and a fresh TOTP code.

```sh
php artisan legal-hold:release <LEGAL_HOLD_UUID> \
  --reason="Formal matter closed and release approved" \
  --evidence="CASE-2026-0042-CLOSE"
```

The hold status change and `legal_hold.released` audit event are committed atomically. If the audit append fails, the hold remains active. A released hold is not deleted; its placement/release history remains available.

## Diagnostic retention reporting and cleanup

The policy is 365 days from UTC `diagnostic_runs.received_at`. Routine or discretionary exceptions are not permitted; an active, formally approved legal hold is the sole exception.

First inspect candidates using the report-only command:

```sh
php artisan diagnostics:retention:report --days=365
```

The report command never deletes data and does not evaluate legal holds.

The separate cleanup command is bounded to at most 500 diagnostic runs per invocation, excludes active processing states, checks direct and descendant holds, and appends a `diagnostics.retention.deleted` immutable audit event in the same transaction as each deletion. If hold-state evaluation or audit append fails, the transaction aborts and the run is not deleted.

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
- Hold-state lookup failure during cleanup: transaction abort; no deletion.
- Audit append failure during cleanup: transaction rollback; no deletion.
- Keep the deployment cleanup feature flag false while investigating any unexpected result.

## Release evidence still required

- Exact PR-head CI is green for every required job.
- External 365-day Object Lock bucket and independent Ed25519 key custody are provisioned and independently verified.
- Production database runtime/migration role separation and negative privilege tests are evidenced.
- Isolated backup/restore rehearsal and audit checkpoint read-back are successful.
- Project Owner separately approves any production configuration change. This runbook does not authorize a production migration, merge, or deployment.
