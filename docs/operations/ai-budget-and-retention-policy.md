# AI spend and data-retention policy design

Status: operator-approved policy targets; implementation design only
Reviewed: 2026-11-12
Applies to: ResellerBytes AI Operations

## Approved limits and retention periods

| Control                                      | Approved target                        |
| -------------------------------------------- | -------------------------------------: |
| Global AI spend limit per UTC day            | USD 5.00                               |
| Global AI spend limit per UTC calendar month | USD 50.00                              |
| Diagnostic snapshot retention                | 90 days                                |
| Audit event retention                        | 2,555 days (approximately seven years) |

The daily and monthly limits apply to the application as a whole, across all WHMCS integrations and operators. The monthly limit is independent of the daily limit; both must have available capacity before a provider request is submitted. Period boundaries use UTC to keep accounting deterministic across operators and hosting locations.

These values are policy targets, not proof that enforcement is active. Until the budget ledger and reservation path are implemented and verified, the application must not describe the limits as enforced.

## AI budget enforcement design

### Accounting model

- Record every provider attempt independently, linked to the diagnostic run and a unique attempt identifier.
- Store input tokens, output tokens, total tokens, provider response ID (when available), model, prompt version, price-card version, reservation amount, settled amount, and lifecycle status.
- Store monetary values as integer micro-USD (one millionth of a US dollar); do not use floating-point arithmetic for budget decisions.
- Use a versioned model price card. The price card must identify the model and effective date and hold input/output price rates. Never silently apply a new rate to historical usage.
- Compute settled cost from provider-reported token usage and the price card that was active when the reservation was made. Round cost upward to the smallest accounting unit so rounding cannot understate spend.

### Reservation and concurrency

1. Before making an OpenAI request, calculate a conservative maximum request cost using enforced input and output token ceilings and the selected model's versioned price card.
2. In one database transaction, lock the UTC daily and monthly budget-period rows in a stable order, then verify that committed spend plus outstanding reservations plus the new reservation does not exceed either approved limit.
3. Create a unique reservation for the provider attempt and increase both period reservation totals atomically. A concurrent worker must observe the reservation before it can reserve budget of its own.
4. If either period has insufficient capacity, do not call the provider. Record a safe budget-exhausted outcome and leave the diagnostic available for later operator review or a later period.
5. Mark the reservation as submitted before sending the provider request. On a completed response, persist the response ID and usage promptly, then settle the reservation atomically: move the actual charge into committed spend and release only the unused reserved amount.
6. A duplicate settlement must be idempotent, keyed by the unique attempt/response identity.

The request token ceilings must be enforceable, not estimates alone. The current analyzer limits output to 3,000 tokens and the ingestion endpoint bounds the snapshot body, but an explicit conservative input-token ceiling and a reviewed tokenizer/byte-bound strategy are still required before reservations can be called hard limits.

### Failure and retry handling

- A failure known to have occurred before provider submission may release its reservation.
- A definitive provider rejection with no billable completion may be reconciled according to the provider's documented usage response; do not assume every HTTP error is free.
- A timeout, connection reset, worker crash, or lost response after submission is an ambiguous outcome. Keep the full reservation counted and mark it for reconciliation; do not automatically release it merely because no usage was persisted.
- Do not automatically issue another billable provider attempt while the previous attempt for the same run is unresolved. This prevents a retry from creating a second charge while the first request may already have completed.
- A manual reconciliation path must record who resolved the reservation, the evidence used, and the resulting adjustment in the audit stream.
- If actual reported usage exceeds the reservation, record the actual charge, flag the overrun, and block subsequent reservations when either budget is exhausted. This is an incident to investigate, not a reason to understate provider spend.

### Required persistence components

The final consolidated schema batch is expected to provide:
- UTC daily/monthly budget-period rows with approved limit, committed amount, reserved amount, and lockable period identity.
- Per-attempt usage/reservation ledger rows with unique attempt identity, model and prompt metadata, price-card version, token counts, monetary amounts, state, timestamps, provider response ID, and reconciliation metadata.
- Appropriate uniqueness and lookup indexes for idempotent settlement, run history, and period accounting.

No schema migration is added in this design-only step. Database changes will be consolidated into the agreed final migration batch.

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
- Audit events remain append-only during their retention period. The existing Eloquent guard is only an application-layer control; database-level immutability and separately controlled backup/export remain separate release gates.
- Any eventual expiry job must be bounded, dry-run capable, auditable, and must not run until a production backup and restore procedure has been verified.
- The 2,555-day target does not authorize deletion of records before their expiry or bypass any applicable legal hold.

## Release gates before enforcement

1. Approve and implement the versioned provider price card and enforceable input/output token ceilings.
2. Implement atomic daily/monthly reservations, idempotent settlement, and ambiguous-outcome reconciliation.
3. Test simultaneous reservations at both budget boundaries, duplicate settlement, provider errors, timeout-after-submit, worker crash, and retry suppression for unresolved attempts.
4. Add the budget ledger and retention metadata in the final consolidated migration batch; do not run migrations as part of this work.
5. Implement retention dry-run reporting and bounded cleanup with dependency and legal-hold tests. Do not execute pruning during development or deployment preparation.
6. Verify database restore and audit retention/export controls before enabling either scheduled cleanup path.

## Current non-goals

- No live OpenAI spend cap is claimed until reservation enforcement is implemented.
- No destructive migration or data-pruning command is run as part of this design.
- No provider price is hard-coded in this document; price-card values must be verified against the provider's current official pricing when implementation is prepared.
