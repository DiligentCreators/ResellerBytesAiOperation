# AI spend and data-retention policy design

Status: spend ledger implementation in progress; retention reporting is implemented (report-only); destructive retention remains disabled
Reviewed: 2026-11-12
Applies to: ResellerBytes AI Operations

## Approved limits and retention periods

- **Global AI spend limit per UTC day:** USD 5.00
- **Global AI spend limit per UTC calendar month:** USD 50.00
- **Diagnostic snapshot retention:** 365 days
- **Audit event retention:** indefinite; audit events are never automatically deleted

The daily and monthly limits apply to the application as a whole, across all WHMCS integrations and operators. The monthly limit is independent of the daily limit; both must have available capacity before a provider request is submitted. Period boundaries use UTC to keep accounting deterministic across operators and hosting locations.

The implementation adds UTC daily/monthly period rows, per-attempt reservations, pre-submit state, usage settlement, and conservative ambiguous-outcome holds. Enforcement remains gated on CI, PostgreSQL concurrency verification, and deployment migration. Do not describe production limits as active until that migration has been applied and verified.

## AI budget enforcement design

### Accounting model

- Record every provider attempt independently, linked to the diagnostic run and a unique attempt identifier.
- Store input tokens, output tokens, total tokens, provider response ID (when available), model, prompt version, price-card version, reservation amount, settled amount, and lifecycle status.
- Store monetary values as integer micro-USD (one millionth of a US dollar); do not use floating-point arithmetic for budget decisions.
- Use a versioned model price card. The price card identifies the model, rate version, and expiry. The current `gpt-5.6-sol` short-context card is pinned to the official November 2026 rate and expires at 2026-11-30 23:59:59 UTC; reservations fail closed after expiry until the card is reviewed and updated. Never silently apply a new rate to historical usage.
- Compute settled cost from provider-reported token usage and the price card that was active when the reservation was made. Round cost upward to the smallest accounting unit so rounding cannot understate spend.

### Reservation and concurrency

1. Before making an OpenAI request, calculate a conservative maximum request cost using enforced input and output token ceilings and the selected model's versioned price card.
2. In one database transaction, lock the UTC daily and monthly budget-period rows in a stable order, then verify that committed spend plus outstanding reservations plus the new reservation does not exceed either approved limit.
3. Create a unique reservation for the provider attempt and increase both period reservation totals atomically. A concurrent worker must observe the reservation before it can reserve budget of its own.
4. If either period has insufficient capacity, do not call the provider. Record a safe budget-exhausted outcome and leave the diagnostic available for later operator review or a later period.
5. Mark the reservation as submitted before sending the provider request. On a completed response, persist the response ID and usage promptly, then settle the reservation atomically: move the actual charge into committed spend and release only the unused reserved amount.
6. A duplicate settlement must be idempotent, keyed by the unique attempt/response identity.

The analyzer enforces a 65,536-byte ceiling across the system prompt, serialized snapshot, and JSON schema. UTF-8 token count cannot exceed byte count, so the full byte ceiling is used as a conservative input-token reservation bound. Output is capped at 3,000 tokens in the provider request. The configured limits are shared by the reservation calculator and analyzer request.

### Failure and retry handling

- A failure known to have occurred before provider submission may release its reservation.
- A definitive provider rejection with no billable completion may be reconciled according to the provider's documented usage response; do not assume every HTTP error is free.
- A timeout, connection reset, worker crash, or lost response after submission is an ambiguous outcome. Keep the full reservation counted and mark it for reconciliation; do not automatically release it merely because no usage was persisted.
- Do not automatically issue another billable provider attempt while the previous attempt for the same run is unresolved. This prevents a retry from creating a second charge while the first request may already have completed.
- A manual reconciliation path must record who resolved the reservation, the evidence used, and the resulting adjustment in the audit stream.
- If actual reported usage exceeds the reservation, record the actual charge, flag the overrun, and block subsequent reservations when either budget is exhausted. This is an incident to investigate, not a reason to understate provider spend.

### Required persistence components

The ledger migration `2026_11_12_000008_create_ai_budget_ledger_tables.php` provides:

- UTC daily/monthly budget-period rows with approved limit, committed amount, reserved amount, and lockable period identity.
- Per-attempt usage/reservation ledger rows with unique attempt identity, model and prompt metadata, price-card version, token counts, monetary amounts, state, timestamps, provider response ID, and reconciliation metadata.
- Appropriate uniqueness and lookup indexes for idempotent settlement, run history, and period accounting.

Migration `2026_11_12_000008_create_ai_budget_ledger_tables.php` adds the daily/monthly budget periods and per-attempt ledger. It has not been run against production.

## Data-retention design

### Diagnostic snapshots — 365 days

- Retention age is measured from snapshot receipt time in UTC.
- The 365-day policy applies to the diagnostic run and its dependent findings and recommendations, because those records contain derived evidence from the same snapshot.
- Before any deletion, provide a dry-run report with candidate counts, oldest/newest timestamps, and affected dependent records.
- A report-only command is available: `php artisan diagnostics:retention:report --days=365`. It reports candidate run counts, dependent finding/recommendation counts, cutoff, and oldest/newest receipt timestamps; it never deletes data and does not evaluate legal holds. A separate `diagnostics:retention:prune` command is hold-aware, bounded, dry-run by default, and re-checks holds inside the deletion transaction. It requires both `DIAGNOSTICS_RETENTION_CLEANUP_ENABLED=true` and explicit `--execute`; it is not scheduled and remains disabled by default.
- Deletion must preserve the independent audit-event record. Do not cascade diagnostic retention into audit events or integration records.
- The approved legal-hold model is the sole exception to the 365-day diagnostic expiry. Held records remain retained until the Project Owner explicitly approves and audits release. Cleanup must exclude held records, re-check hold state inside the deletion transaction, and fail closed if hold state or audit writing fails.

### Audit events — indefinite retention

- Retain audit events indefinitely. They must never be automatically deleted, expired, or pruned; there is no scheduled expiry based on `occurred_at`.
- Do not apply the diagnostic snapshot's 365-day policy or the external backup's 365-day Object Lock period to the live audit-event records. Backup-object retention and live audit-event retention are separate controls.
- No automated audit-event cleanup/expiry job is permitted. Any future exceptional disposition would require a separately reviewed governance and legal decision; it must not be implemented as routine retention automation.
- Audit events remain append-only. A forward-only migration installs database triggers that reject UPDATE and DELETE for SQLite, PostgreSQL, MySQL/MariaDB, and SQL Server; PostgreSQL also rejects TRUNCATE. Eloquent guards remain defense in depth. MySQL/MariaDB and SQL Server do not provide equivalent row-trigger protection for TRUNCATE, so the runtime database principal must not have TRUNCATE, DROP, ALTER, or trigger-management privileges. Production must use a least-privilege runtime principal separate from the migration/owner principal. A database owner/superuser can still disable triggers or alter the schema; separately controlled backups/exports and external tamper-evident retention remain release gates.
- No audit-event expiry job is permitted under the approved indefinite-retention policy. Backup/restore verification remains mandatory for release, but it does not create an audit-event deletion window.

## Release gates before enforcement

1. Approve and implement the versioned provider price card and enforceable input/output token ceilings.
2. Implement atomic daily/monthly reservations, idempotent settlement, and ambiguous-outcome reconciliation.
3. CI tests cover reservation limits, idempotent settlement, pre-submit release, ambiguous timeout hold, unresolved-attempt retry blocking, expired price cards, and database-level audit UPDATE/DELETE/TRUNCATE rejection. PostgreSQL concurrency tests, provider failure/timeout integration tests, worker-crash simulation, and operator reconciliation workflow remain release gates.
4. Add the budget ledger and retention metadata in the final consolidated migration batch; do not run migrations as part of this work.
5. Implement retention dry-run reporting and bounded cleanup with dependency and legal-hold tests. Do not execute pruning during development or deployment preparation.
6. Verify database restore and audit retention/export controls before enabling either scheduled cleanup path.

## Current non-goals

- No production OpenAI spend cap is claimed until the migration is applied and PostgreSQL concurrency and deployment verification pass.
- Automated queue retries are disabled for billable analysis attempts; unresolved submitted attempts remain reserved and block another billable attempt. Explicit manual reanalysis is possible only after the prior attempt is settled or reconciled.
- A fresh-MFA, two-person no-charge reconciliation command and database-level append-only audit triggers are implemented on the review branch. They are not production-enabled; migrations remain unapplied and CI/release gates still apply.
- No destructive migration or data-pruning command is run as part of this design.
- No provider price is hard-coded in this document; price-card values must be verified against the provider's current official pricing when implementation is prepared.

## Ambiguous provider-attempt reconciliation

An attempt marked `ambiguous` represents a request that may have reached the provider but whose billable outcome was not confirmed. Its reservation remains held and blocks another billable attempt for the same diagnostic run.

A trusted operator may release that reservation only after independently checking provider-side request history or obtaining provider support evidence that the request was not accepted and no charge was incurred. A timeout, missing local response ID, or absence of a visible response in the application is not sufficient evidence.

The guarded server-side command is:

```sh
php artisan ai-budget:reconcile-no-charge ATTEMPT_UUID \
  --operator=initiator@example.com \
  --approver=independent-approver@example.com \
  --evidence=PROVIDER_DASHBOARD_CASE_OR_REQUEST_REFERENCE \
  --note="Verified provider request history shows no accepted request and no charge."
```

The command requires two distinct, email-verified operator accounts with confirmed TOTP enrollment. It prompts for each operator's current six-digit code using hidden input; OTPs are never accepted as command-line options. Both codes are verified through Laravel Fortify's TOTP provider, including its cache-backed one-time-code replay protection. The initiating operator and independent approver must then explicitly approve the action. The attempt row and both budget-period rows are locked; the reservation release, attempt state transition, both operator identities/timestamps, and separate approval/reconciliation audit events are committed in one database transaction. Repeated reconciliation and same-account approval are rejected.

This command supports only the verified **not charged** outcome. It does not guess token usage, fabricate a provider response ID, or release an attempt when the provider outcome remains unknown. If provider evidence confirms a charge, do not use this command; preserve the hold and follow a separately reviewed settlement procedure. Keep provider evidence in the approved restricted evidence store and reference it without copying secrets into the audit record.

The command is a privileged operational control, not a substitute for provider evidence, least-privilege database roles, externally retained tamper-evident backups, or a separately controlled operator identity process. The two-person approval requirement is enforced in the command and service layer; it does not by itself prove that the two people are physically separate or that their endpoints are uncompromised. Production use remains blocked until PostgreSQL transaction/concurrency tests, fresh-MFA/dual-approval tests, and the full CI workflow pass.

## Audit database-role hardening

The trigger migration is only one layer. Production credentials must be split:

- **Migration/owner principal:** used only by controlled deployment migrations; owns the audit table and its triggers.
- **Application runtime principal:** used by web/queue workers; receives only the table privileges required at runtime. It must not own the table, manage triggers, or perform schema changes.
- **Backup/restore principal:** separately controlled and not available to application workers.

Before production activation, apply and verify the engine-specific equivalent of the following controls using the actual deployment role names:

### PostgreSQL

```sql
REVOKE ALL ON TABLE public.audit_events FROM PUBLIC;
REVOKE UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER
    ON TABLE public.audit_events FROM app_runtime;
GRANT SELECT, INSERT ON TABLE public.audit_events TO app_runtime;
REVOKE ALL ON SEQUENCE public.audit_events_id_seq FROM PUBLIC;
GRANT USAGE, SELECT ON SEQUENCE public.audit_events_id_seq TO app_runtime;
```

The table owner must be a separate migration principal. A superuser or owner can still alter/drop triggers or the table; use controlled credentials and externally retained backups.

### MySQL / MariaDB

Grant the runtime principal only SELECT and INSERT on the audit table. Do not grant UPDATE, DELETE, DROP, ALTER, TRIGGER, or global/schema-level privileges that imply those operations. In MySQL-family engines, TRUNCATE is a DROP-class operation and is not blocked by row triggers. The migration principal alone should own schema-change privileges.

### SQL Server

Grant SELECT and INSERT on the audit table to the runtime principal and explicitly DENY UPDATE, DELETE, ALTER, and CONTROL on the table; deny schema/database DDL and trigger-management privileges to the runtime principal. TRUNCATE is controlled by ALTER permission and must remain unavailable to the runtime principal.

### SQLite

SQLite does not provide database users/roles. Protect the database file and its containing directory with operating-system ownership and permissions; the application process must not be able to replace the database file or alter its schema outside the migration process.

These are deployment controls, not changes applied by this repository migration. Do not reuse the migration/owner credentials in web or queue worker environment variables.

### WHMCS integration lifecycle — retained history

- WHMCS integration records are retained after revocation so diagnostic history and audit references remain addressable. Normal lifecycle operations are create, secret rotation, and revocation; revocation invalidates the previous secret and increments the secret version.
- Hard deletion is rejected by the Eloquent model and by database DELETE triggers for SQLite, PostgreSQL, MySQL, MariaDB, and SQL Server. Dedicated pull-request CI jobs exercise MySQL 8.4, MariaDB 11.4, and SQL Server 2022; the SQL Server check creates an isolated minimal table and invokes the production migration directly. These tests validate the trigger's DELETE rejection path, not a full application migration on every engine. This is defense in depth, not protection against a database owner/superuser or a principal with schema/trigger administration rights.
- The runtime database principal must not receive TRUNCATE, DROP, ALTER, or trigger-management privileges. Integration records are not part of the 365-day diagnostic snapshot cleanup policy.
- Expired request nonces are the only WHMCS integration child records currently pruned automatically; the command deletes in batches of at most 1,000 and 10,000 rows per run. Revoked integrations and their diagnostic history are not pruned.
- No integration hard-delete or historical diagnostic cleanup is enabled. Any future deletion policy requires an approved retention schedule, legal-hold mechanism, dependency-aware dry run, external audit anchor, and a tested restore procedure.

## Decisions and implementation gates still open

The following items are deliberately not guessed or activated:

### Approved legal-hold governance

- **Authority:** the project Owner is the approval authority to place and release a legal hold.
- **Auditability:** every placement and release must produce an append-only audit event. At minimum record the acting owner identity, UTC timestamp, affected record/scope, action (place/release), reason, evidence or case reference, and resulting hold state. Do not put sensitive case documents or customer payloads in event metadata; store only a controlled reference.
- **Enforcement:** any future cleanup job must exclude held records at query time and re-check hold state inside the deletion transaction. A hold or audit-write failure must fail closed: no deletion. Hold release must itself be explicitly owner-approved and audited; expiry of the 365-day backup period does not override a legal hold.
- **Current implementation status:** this is an approved governance decision, not a claim that a legal-hold UI, schema, command, or cleanup enforcement is already implemented. Automated diagnostic/audit-history deletion remains disabled until those controls and tests are delivered.

### Shahrukh ke liye

1. **External anchor provisioning:** provision the separate cloud account/bucket with the approved 365-day Object Lock retention and independent Ed25519 key custody. No bucket or key is provisioned by this repository change.
4. **Production role names and ownership:** confirm the real PostgreSQL database/schema names and Forge secret-management process before applying the example `rb_migrator` / `rb_runtime` identities. The guide is a template, not an executed production change.
5. **Provider price-card approval:** verify the exact provider/model pricing and approve the active version and expiry before production spend enforcement is enabled. The CI fixture is not a substitute for commercial approval.

### Work that can proceed without credentials

- Keep the nonce-prune command bounded and idempotent; test expiry boundary, batch cap, and rerun behavior.
- Keep integration records and diagnostic history out of automatic pruning.
- Report-only retention candidate reporting is implemented and tested without enabling deletion.
- Implemented the owner-authorized legal-hold record and CLI placement/release commands. The configured `OPERATIONS_OWNER_EMAIL` is the sole Owner identity; the account must have verified email and enrolled MFA, and each CLI action requires fresh TOTP. Placement/release and their immutable audit events commit atomically. Cleanup checks direct and descendant holds under the diagnostic-run transaction lock; cleanup remains disabled by default and additionally requires explicit `--execute`.
- MySQL 8.4, MariaDB 11.4, and SQL Server 2022 integration checks are now defined in dedicated CI jobs; require all three jobs to pass on the exact PR head before claiming their retention-trigger paths are verified.
- Add a release evidence checklist requiring runtime-role negative privilege tests, external anchor read-back/signature verification, backup restore drill, and alert routing proof.

No automated diagnostic snapshot cleanup, audit-event expiry, integration deletion, external anchor upload, or production privilege change is enabled by this policy document.


### Approved diagnostic retention exceptions — 2026-11-14

- **Decision:** no discretionary or routine exception extends diagnostic retention beyond 365 days. Non-held diagnostic runs and their dependent findings/recommendations expire at 365 days from `received_at` (UTC).
- The previously approved legal-hold process remains the only exception: a hold must be placed by the Project Owner with an immutable audit event and remains effective until a separately owner-approved and audited release. This is not a discretionary retention extension.
- Customer support, convenience, product analytics, or ordinary investigation status do not independently extend retention. If a qualifying legal hold applies, the hold takes precedence; otherwise the 365-day expiry applies.
- This decision does not enable scheduled or automatic deletion. A bounded manual cleanup command now exists with dry-run default, a disabled-by-default deployment feature flag, explicit `--execute`, in-transaction hold re-check, and dependency-safe cascade deletion. It must remain disabled until the exact release SHA passes CI and backup/restore and operational gates are approved.


## Audit-event retention policy approved — 2026-11-14

- Project Owner decision: **indefinite retention**. Audit events must never be automatically deleted, expired, or pruned; there is no 2,555-day expiry.
- This applies to live audit-event records and is distinct from the separately approved 365-day immutable external backup-object retention. Backup-object lifecycle rules must not be interpreted as authorization to delete the live audit stream.
- The diagnostic snapshot/finding/recommendation policy remains 365 days, subject only to the approved owner-authorized legal hold. Audit events remain append-only and are excluded from all automated cleanup.
- Owner authority is configured only through `OPERATIONS_OWNER_EMAIL`; no fallback Owner, role escalation, or public endpoint is provided. CLI placement/release requires fresh TOTP. The migration and commands are repository code only; no production migration, scheduled retention job, or deployment is authorized.
