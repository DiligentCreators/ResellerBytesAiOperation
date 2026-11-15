# Shared-database tenant schema and backfill design

Status: design plus additive schema foundation authored, 2026-11-15  
Scope: approved hybrid SaaS target (shared application, shared PostgreSQL database, tenant-scoped billing and branding). The additive tenant and membership migration is authored on the release-readiness branch but has not been executed against any database. No tenant records are provisioned, no data is backfilled, and customer onboarding remains disabled.

## 1. Design invariants

1. A tenant is an organization/workspace boundary, not an individual user. A user may belong to multiple tenants through an explicit membership.
2. Every tenant-owned record must resolve to exactly one tenant. Missing ownership is quarantined, not treated as a default tenant.
3. Membership and role checks are required at request boundaries. A record's integration owner is not a substitute for current tenant membership.
4. Tenant identifiers are captured in queued jobs and revalidated at execution and persistence boundaries, just as the account owner is currently revalidated.
5. Existing account-level `owner_user_id` remains a transitional operator/creator attribution field. It must not become the long-term authorization boundary.
6. Audit events remain append-only and are retained indefinitely. Tenant attribution is metadata/indexing, not a tenant-scoped deletion mechanism; platform-wide events may have a null tenant.
7. Diagnostic payload retention remains 365 days with no exception. Legal holds must be evaluated before any eligible deletion; audit events are never automatically deleted.
8. External registration, customer onboarding, and payment collection stay disabled until the tenant-isolation and billing release gates pass.

## 2. Proposed core schema

### `tenants` (organization/workspace)

- `id`: UUID primary key.
- `name`: bounded display name.
- `slug`: normalized unique identifier, reserved and collision checked.
- `status`: `provisioning | active | suspended | closed`; only active tenants may create new work.
- `created_by_user_id`: nullable/restricted FK to users for operator-created bootstrap records.
- timestamps and optional `settings` JSON for non-secret presentation preferences only.
- No customer-facing self-service creation in the initial migration.

### `tenant_memberships`

- UUID primary key; `tenant_id` and `user_id` FKs with restrictive deletion.
- `role`: initial closed enum/string allowlist: `owner | admin | operator | viewer | billing`.
- `status`: `invited | active | suspended | revoked`.
- `invited_by_user_id`, `accepted_at`, timestamps.
- Unique active identity invariant on (`tenant_id`, `user_id`); role changes and revocation are audited.
- At least one active owner must remain for every active tenant. Owner transfer requires an explicit audited operation; no implicit promotion.

### Tenant-owned domain records

- `whmcs_integrations`: add nullable `tenant_id` FK during expansion. Keep `owner_user_id` during transition. Add tenant/status index and enforce tenant consistency before making tenant_id NOT NULL.
- `diagnostic_runs`: derive tenant from integration initially; add a denormalized `tenant_id` for indexed policy/RLS enforcement only after an integrity check and backfill. Composite consistency must ensure run tenant equals integration tenant.
- `diagnostic_findings` and `diagnostic_recommendations`: inherit run tenant; if tenant_id is denormalized, enforce finding/run and recommendation/finding tenant equality.
- `ai_usage_attempts`: add tenant attribution copied from the locked diagnostic run at reservation time. Settlement/reconciliation must use the immutable captured tenant and reject a run/integration ownership mismatch.
- `ai_budget_periods`: preserve a platform-wide budget scope while adding a nullable tenant scope and a uniqueness key that includes scope, period type, and period start. Tenant reservations must atomically enforce both tenant and platform caps; never replace the platform cap.
- `audit_events`: add nullable tenant_id for tenant-scoped filtering and reporting. The append-only hash chain remains global and sequence ordered; tenant_id must be included in canonical hash input/version migration. Platform events may remain null. No tenant can update/delete or independently prune audit events.
- `legal_holds`: resolve and store nullable tenant_id when the held subject is tenant-owned; platform-wide subjects remain null. Hold placement/release remains restricted, evidence-backed and audited.
- `integration_request_nonces`: inherit the integration boundary; no separate tenant copy is required unless query patterns/RLS require it.
- `operations_metric_samples`, `operations_alert_states`, `operations_queue_job_samples`: remain platform operational telemetry by default. Never expose these global records through tenant dashboards; any future tenant metrics require a separate explicitly scoped data product.
- `users`, sessions, cache, queue infrastructure: platform identity/infrastructure, not tenant-owned data.

## 3. Authorization and database enforcement

- Resolve the active tenant from an explicit route/context selection and active membership; do not infer it from a submitted record ID.
- Centralize membership/role policy checks. Default deny for missing tenant context, inactive tenant, inactive membership, and unknown role.
- Every integration, diagnostic, finding, recommendation, usage, billing and tenant audit query must include tenant scope. Nested resources must validate the entire parent chain.
- Jobs serialize `tenant_id`, `actor_user_id` where relevant, and the subject ID. At execution, lock and verify tenant + membership/authorization state; repeat before external side effects and before writes.
- Exports, downloads, signed URLs, notifications, webhook delivery and support tooling must resolve the same tenant boundary and use short-lived, tenant-bound capabilities.
- PostgreSQL RLS is defense in depth, not a replacement for application policies. Use a transaction-local tenant setting (`SET LOCAL`) on every tenant transaction; connection-pool reuse must never leak tenant context. Platform maintenance jobs use an explicit privileged role and audited purpose.
- Add composite foreign keys/unique constraints where supported to prevent cross-tenant parent-child references. Application validation alone is insufficient for tenant integrity.

## 4. Backfill and rollout sequence

1. Inventory existing integration IDs, owner_user_id values, related diagnostic counts, audit references, usage attempts, and legal holds in a read-only report. Do not emit secrets or diagnostic payloads.
2. Obtain Shahrukh's explicit tenant name/slug and per-integration owner/tenant mapping. Existing `owner_user_id = NULL` records remain quarantined. Never assign them to a default tenant.
3. Create the first tenant and its initial owner membership only after the mapping is approved and the target user is verified, email-confirmed, and TOTP-enabled.
4. Add nullable tenant columns and supporting indexes (expand migration); backfill in bounded, restartable batches from the approved mapping; record counts and checksums.
5. Verify every integration has exactly one approved tenant; every child record matches its parent tenant; usage and audit attribution reconcile; legal holds resolve; no orphan or cross-tenant references exist.
6. Enable tenant-aware application reads/writes and owner/membership policies. Keep legacy owner-scoped behavior as a temporary compatibility check.
7. Run cross-tenant negative tests, concurrent membership revocation tests, queue replay/reassignment tests, export/download tests, and production-like migration/rollback rehearsal.
8. Enable PostgreSQL RLS after transaction-local tenant context and privileged maintenance behavior are verified.
9. Only after independent review and release authorization, make tenant_id mandatory for tenant-owned records and consider enabling external onboarding.

Rollback is expand/contract aware: do not drop legacy owner attribution or tenant data during rollback. Roll back application enforcement first while retaining nullable columns and audit evidence. No destructive rollback or production migration is authorized by this design.

## 5. Required isolation test matrix

- User with no membership cannot list, read, mutate, export or reanalyse another tenant's records.
- A member of Tenant A cannot use Tenant B IDs in nested diagnostic/finding/recommendation endpoints.
- Revoked/suspended membership blocks existing sessions and queued work.
- Tenant reassignment is prohibited by default; any approved transfer is an explicit audited migration with old/new tenant evidence.
- A queued job created under Tenant A cannot continue after the subject is moved, membership is revoked, or tenant is suspended.
- AI reservation, settlement, ambiguity reconciliation and period counters cannot debit or release another tenant's budget; global cap remains enforced.
- Tenant audit views filter by tenant while global chain verification remains complete and tamper evident.
- Legal holds block diagnostic expiry; diagnostics expire after 365 days when no valid hold applies; audit events remain indefinite.
- Export/download tokens, notification recipients and webhook destinations cannot cross tenant boundaries.
- RLS rejects queries without tenant context and prevents tenant context leakage across pooled connections.

## 6. Initial schema foundation now authored

- Added `tenants` with UUID identity, unique slug, lifecycle status, creator attribution, and non-secret settings JSON.
- Added `tenant_memberships` with UUID identity, restrictive tenant/user/inviter foreign keys, one membership row per tenant/user, explicit role and membership status, acceptance timestamp, and lookup indexes.
- Added nullable `whmcs_integrations.tenant_id` with restrictive FK and tenant/status index. Existing records remain null; migration contains no implicit tenant assignment or data backfill.
- Added `Tenant` and `TenantMembership` models, user/integration relationships, closed role/status constants, and a fail-closed `TenantAccess` resolver requiring an active tenant and active membership before granting role access.
- Added tests for explicit membership, outsider denial, role restrictions, suspended/revoked memberships, and unassigned legacy integrations. These tests are authored but have not been run.
- This is an additive schema/model foundation only. Tenant-scoped application queries across every domain table, audit attribution/hash versioning, tenant-aware billing, job serialization/revalidation, RLS, and operational backfill verification remain incomplete.

## 7. Explicit blockers and non-goals

- The first tenant identity/slug and mapping of each legacy integration require Shahrukh's explicit approval.
- Payment provider, pricing and production credentials remain separate operator approvals.
- No migration, data backfill, customer onboarding, payment collection, production RLS activation, merge or deployment is performed by this design checkpoint.
- This design does not authorize deleting or rewriting audit history, nor relaxing diagnostic retention.


## 8. Application query and queued-work enforcement progress

- Added a centralized `TenantRecordQuery` that keeps unassigned legacy integrations visible only to their recorded owner, while tenant-assigned integrations and their diagnostic runs/findings require an active tenant and active membership with a recognized role. A tenant assignment overrides historical owner access; a former owner without membership is denied.
- Applied the query boundary to integration, diagnostic-run and finding list/detail controllers and dashboard aggregates. Nested detail reads authorize through the full integration-to-tenant relationship before loading child evidence/recommendations.
- Tenant manual reanalysis now requires an active membership with owner/admin/operator capability, plus an active subscription carrying the `diagnostics.reanalyze` entitlement. The authenticated actor and captured tenant UUID are serialized into the job and audit event; legacy unassigned records remain owner-only.
- Analysis jobs now capture the integration tenant UUID at dispatch. Tenant jobs revalidate the captured tenant identity and active tenant before starting, before provider submission, and before persisting findings; legacy jobs remain owner-bound. Tenant jobs also require the `diagnostics.analysis` subscription entitlement before reserving budget or calling the provider. Failure mutation is scoped to the captured context.
- Stale queue recovery now carries tenant UUID into redispatched jobs and ignores tenant-owned jobs whose tenant is inactive. Legacy unassigned jobs retain their prior owner-bound path.
- Added regression tests for tenant-member read access, former-owner/outsider denial, revoked membership, captured tenant reassignment, tenant suspension, and tenant-aware stale queue recovery. Tests are authored but not executed.
- This does not yet implement tenant attribution in AI usage reservations/settlement and budget periods, tenant-aware audit event hashing, role-specific write permissions, RLS, exports/downloads/webhooks/notifications, or all billing surfaces. The tenant read path is not a substitute for those remaining gates.


## 9. Tenant-aware AI budgets and audit attribution

- Added nullable tenant UUID to each AI usage attempt. The tenant is captured from the diagnostic run's integration inside the reservation transaction and cannot be changed through the Eloquent model after creation.
- Added tenant daily/monthly budget periods with independent reserved and committed balances. Reservation checks and locks the platform-wide daily/monthly caps and the tenant-specific daily/monthly caps in one transaction; any failure rolls the entire reservation back.
- Platform-wide caps remain authoritative across all tenants. Tenant caps default to the same configured daily/monthly ceiling unless separately configured with `AI_TENANT_DAILY_BUDGET_MICRO_USD` and `AI_TENANT_MONTHLY_BUDGET_MICRO_USD`.
- Settlement, pre-submit release and audited ambiguous no-charge reconciliation move/release both global and captured-tenant reservations atomically, using the attempt's immutable tenant UUID and original UTC period keys.
- Suspended/inactive tenants cannot create a new billable reservation. A request already accepted by the provider must still be settled against the captured tenant, even if the tenant is suspended while the provider call is in flight.
- Added nullable tenant attribution to audit events. New writes use canonical hash version 2 with a domain-separated payload that includes tenant UUID; legacy hash version 1 payloads remain verifiable without changing historical digests. The audit verifier and signed external checkpoint verifier accept both versions in the same global sequence.
- AI budget reconciliation and integration owner/secret/revocation audit events now carry tenant attribution where the affected record has a tenant. Platform/global events remain null-scoped.
- Added tests for tenant-specific budget exhaustion, isolation between tenant allowances, preservation of the platform cap, suspended-tenant denial, immutable attempt tenant identity, tenant audit hashing, and version-one hash compatibility. Tests are authored, not executed.
- Existing AI attempts and audit events are not backfilled to tenants. Their tenant_id remains null until explicit historical mapping is approved; no migration has been executed.


## 10. Tenant subscriptions, entitlements, billing ledger and role capabilities

- Added additive migration `2026_11_15_000005_create_tenant_billing_entitlements.php` for tenant subscriptions, invoice records and payment records. Subscription rows carry plan-code and entitlement snapshots, manual/automated billing mode, provider reference, currency/amount snapshot, billing period and explicit grace/cancellation timestamps.
- Added `TenantEntitlementService` to fail closed when a tenant is inactive, has no currently effective subscription, is outside its paid/grace period, or lacks the requested feature/limit. Tenant AI analysis requires `diagnostics.analysis`; manual reanalysis requires both the `diagnostics.reanalyze` role capability and subscription feature.
- Added `TenantRoleAuthorization` capability matrix: owner/admin manage integrations and members; owner/admin/operator can request diagnostic reanalysis; viewer is read-only; billing role can read and manage billing; tenant closure is owner-only.
- Tenant-owned manual reanalysis now authorizes the current member, rechecks role and entitlement in the transaction, writes tenant-attributed audit evidence, and queues work with the actor ID plus captured tenant UUID. Tenant jobs do not fall back to the historical integration owner. Legacy unassigned integrations retain owner-only reanalysis.
- AI jobs fail closed before budget reservation/provider submission when tenant subscription entitlement is absent. A failed entitlement check creates no AI usage reservation and records a safe failure code.
- Added `TenantBillingLedgerService` for recording already-confirmed manual or automated payments. It validates tenant/invoice/subscription alignment, currency, overpayment, provider/manual evidence, idempotency, and invoice totals in one transaction. Manual entries require a locked active billing-capable membership. Payment records and invoice state changes emit tenant-attributed immutable audit events. The service does not initiate charges or accept unverified provider callbacks.
- Added regression tests for subscription period/grace handling, missing features and limits, role capability boundaries, tenant reanalysis authorization, idempotent manual/automated payment recording, cross-tenant invoice access, overpayment prevention, and AI entitlement denial before reservation. Tests are authored, not executed.
- Subscription, invoice and payment migrations are authored only; no migration or data changes have been applied. No plan pricing, gateway selection, production payment collection, or external registration has been enabled. Existing tenants have no inferred subscription.
- Remaining: subscription provisioning/plan catalogue approval; signed provider webhook integration and payment reconciliation; tenant usage limits for every resource; role gates on future integration/member/settings endpoints; exports/downloads/webhooks/notifications; complete audit attribution; composite tenant FKs and PostgreSQL RLS; backfill and operational rehearsals.


## Tenant audit exports and internal notification inbox — 2026-11-15

- Added owner/admin-only tenant audit CSV export with tenant-scoped query, optional UTC date bounds, immutable chain sequence/hash columns, chunked streaming, CSV formula-injection neutralization, private no-store headers, rate limiting, and an immutable tenant-attributed audit event recording each export request.
- Added tenant notification inbox storage and JSON endpoints. Reads and mark-read updates are scoped simultaneously by tenant UUID and authenticated recipient user ID; notification publishing requires an active tenant and active membership, bounds payload size, accepts scalar payload values only, and uses a unique deduplication key.
- Successful tenant diagnostic analysis now creates deduplicated in-app completion notifications for active owner/admin/operator/viewer members in the same database transaction as the analyzed status. Billing-only members do not receive diagnostic notifications. Legacy owner-only diagnostics remain unchanged.
- Added tests for audit-export role restrictions/cache headers, notification recipient isolation, cross-user mark-read denial, inactive/non-member publication denial, and notification idempotency. Tests are authored, not executed.
- External tenant webhooks and outbound email/SMS/push notifications remain disabled pending endpoint ownership verification, destination allowlisting/SSRF controls, signed payload key custody, retry/dead-letter policy and Shahrukh's provider/secret configuration. The new inbox is internal only.
- New migration `2026_11_15_000006_create_tenant_notifications.php` is authored only and has not been executed. Audit export uses the existing tenant-attributed append-only hash chain; no retention changes were made.


## Composite tenant integrity and PostgreSQL RLS checkpoint — 2026-11-15

- Added migration `2026_11_15_000007_enforce_tenant_composite_constraints_and_rls.php`. It adds tenant attribution to diagnostic runs, findings and recommendations, backfills only from already explicit integration-to-tenant assignments, and adds composite foreign keys that prevent a diagnostic child, billing invoice/payment, or notification recipient from crossing tenant boundaries.
- New diagnostic ingestion persists the integration's captured tenant UUID; AI analysis persists the same UUID to findings and recommendations. Existing unassigned integrations and their historical diagnostics remain `tenant_id = NULL` and retain owner-scoped behavior.
- Added PostgreSQL-only RLS policies for tenants, memberships, integrations, diagnostics, AI usage, tenant budgets, audit events, subscriptions, invoices, payments and notifications. Policies use transaction/request-scoped application settings `app.current_tenant_id` and `app.current_user_id`; tenant membership is verified before a web route activates explicit tenant scope. Signed WHMCS API requests resolve the integration through a narrowly scoped lookup setting, verify HMAC/nonce first, then execute under the integration's tenant/legacy-owner context. Queue analysis jobs carry tenant and owner context and clear settings in a `finally` block.
- Legacy null-tenant diagnostics are not globally exposed by RLS: their policy resolves ownership through the integration's `owner_user_id`. Legacy audit rows are visible only to their recorded user/integration actor. Tenant notification SELECT/UPDATE is recipient-only; inserts require the recipient's active membership in the current tenant and use the unique notification key for idempotency.
- Composite database regression tests cover cross-tenant diagnostic findings, notification recipients, subscription/invoice links and payment/invoice links. Tests are authored, not executed. SQLite retains composite foreign-key enforcement; PostgreSQL RLS policies are created only when the migration runs on PostgreSQL.
- RLS is enabled but deliberately not forced: the schema-owner/migration role can perform controlled schema and recovery work. The production application login must be a separate non-owner role without `BYPASSRLS`; platform-wide retention, audit-integrity, backup and restore operations require a separately controlled privileged operations role. The three logical roles are schema owner/migrator, restricted application runtime, and platform operations/audit. Actual role creation, credentials, grants, ownership transfer and PostgreSQL rehearsal remain pending Shahrukh's database access; no repository settings or database state were changed.
- Migration is authored only and has not been executed. PostgreSQL policy behavior, grants, query plans and migration rollback still require a non-production PostgreSQL rehearsal before release.

- Added SQLite and PostgreSQL database triggers to enforce exact tenant-attribution parity for integration → diagnostic run → finding → recommendation. This closes the nullable-composite-FK loophole where a tenant-owned child could otherwise omit `tenant_id` and skip a MATCH SIMPLE composite foreign key. Regression tests now cover both mismatched and null-attribution bypass attempts.
- Extended tenant-parity triggers to AI usage attempts and immutable audit subjects. A usage attempt must match its diagnostic run's tenant when the run reference exists; audit events for tenant, diagnostic and billing subjects must carry the same tenant as the referenced record. Legacy owner attribution and post-retention AI ledger rows with a null diagnostic reference remain supported.
- Added `whmcs:integration:assign-tenant`, a one-way transactional mapping command for an explicitly approved tenant UUID. It assigns only currently unassigned integrations, then backfills diagnostic runs/findings/recommendations and AI usage attempts under database parity guards; it appends a new tenant-attributed audit event and never rewrites historical audit-chain entries. It does not create tenant memberships or infer a role. Command execution requires the separately controlled platform operations/schema-owner database role after Shahrukh provisions access.

## 11. Tenant quota enforcement and invitation lifecycle hardening — 2026-11-15

- `TenantResourceLimitService` now has valid PHP namespace/import declarations and enforces diagnostic capacity at the inclusive limit (`used >= configured limit`). Missing `diagnostics_per_period` entitlement remains fail-closed.
- Tenant diagnostic snapshot ingestion acquires the active subscription row lock and checks the period quota inside the same outer database transaction as the snapshot insert and audit append. The subscription lock serializes concurrent uploads so two requests cannot both consume the final slot.
- If a concurrent identical snapshot request waits on the quota lock and the winning request fills the final slot, the loser rechecks the integration/snapshot identity and returns the normal idempotent response rather than a misleading quota denial. Changed-payload conflicts remain HTTP 409.
- Integration provisioning already checks the active subscription's integration limit while holding the tenant transaction; both resource paths use the same locked entitlement snapshot.
- Membership invitation lookup now verifies the signed route's tenant, membership ID, intended authenticated user, active tenant and still-pending invitation. It cannot disclose an invitation to another account.
- Invitation delivery is dispatched after the membership transaction commits. Pending invitations can be safely reissued; active and suspended memberships still block a duplicate invite. Existing signed-link expiry and normal account MFA requirements remain in force.
- Tests were added for diagnostic quota exact-boundary and missing-limit denial. Existing invitation tests cover queue dispatch, account binding, expiry, GET non-acceptance, and revoked-invitation suppression. Runtime tests remain unexecuted pending PHP 8.5 and dependencies.
- Tenant subscription commercial snapshots are now database-immutable after provisioning: plan code, billing mode, currency, unit price, and entitlement JSON cannot be edited in place. Provider/account identity remains immutable once bound. Renewals may advance only the billing period/status fields; a plan change requires a new subscription snapshot and explicit audited lifecycle rather than retroactively changing entitlements.
- Tenant member seats are also enforced from the active entitlement snapshot. Active, invited, and suspended memberships consume a seat; revoked memberships do not. Pending invitation resend reuses its existing seat, while new/revoked-member invitations are serialized on the tenant/subscription locks and denied at the exact seat limit.
- PostgreSQL and SQLite now enforce at most one current tenant subscription per workspace (trialing, active, past_due, or suspended) with a partial unique index. A plan replacement must transition the prior snapshot to a terminal state in the same controlled transaction before inserting a new snapshot; overlapping entitlement rows cannot be resolved by silently choosing the newest ID.
