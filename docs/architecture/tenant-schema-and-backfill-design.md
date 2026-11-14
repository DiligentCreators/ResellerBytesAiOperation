# Shared-database tenant schema and backfill design

Status: implementation design checkpoint, 2026-11-15  
Scope: approved hybrid SaaS target (shared application, shared PostgreSQL database, tenant-scoped billing and branding). This document is a schema and migration plan; it does not enable customer onboarding or perform a data backfill.

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

## 6. Explicit blockers and non-goals

- The first tenant identity/slug and mapping of each legacy integration require Shahrukh's explicit approval.
- Payment provider, pricing and production credentials remain separate operator approvals.
- No migration, data backfill, customer onboarding, payment collection, production RLS activation, merge or deployment is performed by this design checkpoint.
- This design does not authorize deleting or rewriting audit history, nor relaxing diagnostic retention.
