# AI spend and data-retention policy design

Status: spend ledger implementation in progress; retention remains design only
Reviewed: 2026-11-12
Applies to: ResellerBytes AI Operations

## Approved limits and retention periods

- **Global AI spend limit per UTC day:** USD 5.00
- **Global AI spend limit per UTC calendar month:** USD 50.00
- **Diagnostic snapshot retention:** 90 days
- **Audit event retention:** 2,555 days (approximately seven years)

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

### Diagnostic snapshots — 90 days

- Retention age is measured from snapshot receipt time in UTC.
- The 90-day policy applies to the diagnostic run and its dependent findings and recommendations, because those records contain derived evidence from the same snapshot.
- Before any deletion, provide a dry-run report with candidate counts, oldest/newest timestamps, and affected dependent records.
- The eventual cleanup must use bounded batches, deterministic ordering, row-level rechecks, and an explicit enable/disable switch.
- Deletion must preserve the independent audit-event record. Do not cascade diagnostic retention into audit events or integration records.
- Any legal/security hold mechanism must exclude held records from cleanup; the hold model must be decided before enabling automated deletion.

### Audit events — 2,555 days

- Retain audit events for at least 2,555 days from `occurred_at`.
- Do not apply the diagnostic snapshot's 90-day policy to audit events.
- Audit events remain append-only during their retention period. A forward-only migration installs database triggers that reject UPDATE and DELETE for SQLite, PostgreSQL, MySQL/MariaDB, and SQL Server; PostgreSQL also rejects TRUNCATE. Eloquent guards remain defense in depth. MySQL/MariaDB and SQL Server do not provide equivalent row-trigger protection for TRUNCATE, so the runtime database principal must not have TRUNCATE, DROP, ALTER, or trigger-management privileges. Production must use a least-privilege runtime principal separate from the migration/owner principal. A database owner/superuser can still disable triggers or alter the schema; separately controlled backups/exports and external tamper-evident retention remain release gates.
- Any eventual expiry job must be bounded, dry-run capable, auditable, and must not run until a production backup and restore procedure has been verified.
- The 2,555-day target does not authorize deletion of records before their expiry or bypass any applicable legal hold.

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
- Hard deletion is rejected by the Eloquent model and by database DELETE triggers for SQLite, PostgreSQL, MySQL/MariaDB, and SQL Server. This is defense in depth, not protection against a database owner/superuser or a principal with schema/trigger administration rights.
- The runtime database principal must not receive TRUNCATE, DROP, ALTER, or trigger-management privileges. Integration records are not part of the 90-day diagnostic snapshot cleanup policy.
- Expired request nonces are the only WHMCS integration child records currently pruned automatically; the command deletes in batches of at most 1,000 and 10,000 rows per run. Revoked integrations and their diagnostic history are not pruned.
- No integration hard-delete or historical diagnostic cleanup is enabled. Any future deletion policy requires an approved retention schedule, legal-hold mechanism, dependency-aware dry run, external audit anchor, and a tested restore procedure.

## Decisions and implementation gates still open

The following items are deliberately not guessed or activated:

### Shahrukh ke liye

1. **Legal-hold authority and workflow:** identify which role may place/release a hold, whether approval must be dual-control, and what evidence/reference is mandatory. Until decided, no automated diagnostic or audit-history deletion is enabled.
2. **Retention exception policy:** confirm whether the seven-year audit target is a minimum or a fixed expiry, and whether contractual, tax, privacy, or jurisdictional requirements require longer or shorter retention. The 2,555-day value remains the current project policy target, not legal advice.
3. **External anchor retention:** approve the proposed 365-day Object Lock default or specify another period with the compliance owner. No bucket or key is provisioned by this repository change.
4. **Production role names and ownership:** confirm the real PostgreSQL database/schema names and Forge secret-management process before applying the example `rb_migrator` / `rb_runtime` identities. The guide is a template, not an executed production change.
5. **Provider price-card approval:** verify the exact provider/model pricing and approve the active version and expiry before production spend enforcement is enabled. The CI fixture is not a substitute for commercial approval.

### Work that can proceed without credentials

- Keep the nonce-prune command bounded and idempotent; test expiry boundary, batch cap, and rerun behavior.
- Keep integration records and diagnostic history out of automatic pruning.
- Add dry-run/report-only retention candidate queries without enabling deletion.
- Add legal-hold schema and cleanup exclusion tests only after the hold semantics above are approved; do not invent a hold-release workflow.
- Add MySQL/MariaDB and SQL Server migration integration coverage in isolated CI services before claiming those dialects are verified. The current CI matrix verifies SQLite and PostgreSQL only.
- Add a release evidence checklist requiring runtime-role negative privilege tests, external anchor read-back/signature verification, backup restore drill, and alert routing proof.

No automated diagnostic snapshot cleanup, audit-event expiry, integration deletion, external anchor upload, or production privilege change is enabled by this policy document.
