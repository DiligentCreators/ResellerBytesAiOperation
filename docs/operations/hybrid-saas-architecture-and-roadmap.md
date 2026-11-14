# Hybrid SaaS architecture and product roadmap

Status: operator-approved architecture direction; implementation pending
Reviewed: 2026-11-12
Applies to: ResellerBytes AI Operations

## 1. Product direction

Follow a hybrid path: validate the platform internally for ResellerBytes hosting operations, then commercialize the same platform for external hosting providers as subscription SaaS. ResellerBytes is represented as a normal tenant, not a privileged data-sharing space.

## 2. Approved architecture decisions

| Area             | Approved decision                                        | Implementation consequence                                                                           |
| ---------------- | -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| Hosting and data | One shared application and shared database               | Strict tenant isolation at every data-access boundary.                                               |
| Billing          | Automated recurring billing and manual invoices/payments | One subscription, billing-event, and entitlement model reconciles both paths.                        |
| Branding         | ResellerBytes brand plus white-label path                | Tenant-configurable branding; advanced white-label capabilities follow the first commercial release. |

These are approved design decisions, not claims that the functionality is already implemented.

## 3. Application architecture

Use a modular monolith with asynchronous queue-based diagnostic processing. Keep one deployable application and clear domain boundaries:

- Identity and access: authentication, MFA, tenant membership, roles, and platform administration.
- Tenant management: organizations, lifecycle, settings, and ownership.
- WHMCS integrations: credentials, signed ingestion, addon versions, and integration health.
- Diagnostics: snapshots, runs, findings, recommendations, and reanalysis.
- AI gateway: model selection, prompt versions, token ceilings, budget reservations, attempts, and usage settlement.
- Billing and entitlements: plans, subscriptions, invoices, payments, webhook events, and feature/usage limits.
- Audit and retention: tenant-attributed audit events, retention holds, exports, and bounded cleanup.
- Branding: tenant display identity, logo/theme settings, support links, and later custom-domain mapping.

Do not split into microservices for the first commercial release. Revisit service extraction only when measured throughput, isolation, or operational ownership justifies it.

## 4. Shared-database tenant isolation

### Tenant ownership

Introduce an organization-level tenants entity. Every tenant-owned record must have an explicit tenant owner, including integrations, diagnostic runs/snapshots, findings, recommendations, usage records, billing records, notifications, and tenant-scoped audit events.

A tenant membership model links users to tenants and records role and membership status. Initial roles should distinguish owner, administrator, operator, billing, and read-only viewer. Define and test the exact permission matrix before external onboarding.

ResellerBytes receives a normal tenant record. Do not implement a hidden global bypass for internal operators. Platform support personnel, if required, use a separately authorized and audited platform-administration capability.

### Enforcement requirements

- Derive tenant context from authenticated membership and authorized resources, never from an untrusted request parameter alone.
- Apply tenant scoping in policies/services/repositories for every read, write, export, background job, and download.
- Verify resource ownership on nested routes and prevent insecure direct-object-reference access.
- Carry tenant identity explicitly in queued jobs and revalidate ownership when the job executes.
- Include tenant attribution in audit and usage records without allowing a tenant to read another tenant's records.
- Add cross-tenant negative tests for list, detail, update, delete, reanalysis, export, addon credential management, and background processing.
- Use database constraints and composite indexes to support tenant ownership and uniqueness.
- Evaluate PostgreSQL row-level security as defense-in-depth only after connection-pooling and transaction-scoped tenant context are designed and tested. Application authorization remains mandatory.

### Migration approach

1. Inventory every existing table and relationship that contains customer or operational data.
2. Create the initial ResellerBytes tenant and map existing users, integrations, runs, findings, recommendations, and audit history to it.
3. Backfill tenant ownership in bounded, observable migration steps.
4. Verify that no tenant-owned row is unmapped and relationships remain consistent.
5. Add non-null constraints, foreign keys, and tenant-aware indexes only after backfill is verified.
6. Enable mandatory tenant scoping and cross-tenant tests before external tenant creation is enabled.

Do not expose customer onboarding until this migration and isolation gate is complete. Preserve existing diagnostic and audit history.

## 5. Billing and subscription architecture

Both payment paths use the same subscription state machine and entitlement service.

### Automated path

- Integrate a payment provider behind a provider-neutral billing adapter.
- Create checkout/payment sessions and recurring billing agreements through the provider.
- Verify webhook signatures and event identity; enforce unique provider event IDs and idempotent processing.
- Reconcile payment success, failure, refunds, disputes, cancellation, and subscription renewal.
- Never trust a browser redirect as proof of payment.

### Manual path

- Support platform-generated invoices and operator-recorded offline payments.
- Record amount, currency, invoice reference, payment method/reference, received time, recording operator, and evidence reference.
- Require auditable permissions for recording, correcting, refunding, or voiding manual payment records.
- Use append-only billing events and adjustment records rather than silently overwriting payment history.
- Define approval or second-person review thresholds for high-value adjustments before launch.

### Shared entitlement rules

- Enforce subscription status and entitlements server-side.
- Reconcile both payment paths into the same subscription lifecycle.
- Define trial, active, past-due, grace-period, suspended, cancelled, and expired behavior.
- Define plan limits for integrations, diagnostic volume, history, and supported features.
- Distinguish platform-wide AI spend caps from tenant-level plan quotas and usage allowances.
- Enforce entitlements consistently across UI, API, addon operations, reanalysis, and background jobs.
- A manual payment must not bypass invoice reconciliation or create an unaudited entitlement grant.

Payment provider, supported currencies, tax treatment, refund workflow, and manual-payment approval thresholds remain explicit product decisions. Do not assume a provider or jurisdiction-specific tax configuration.

## 6. Branding and white-label architecture

The first public commercial experience uses the ResellerBytes AI Operations brand. Build the data model so tenant presentation can later be customized without forking application code.

Initial tenant branding fields may include display name, logo asset, theme tokens, support contact/link, and email sender display identity. Validate uploaded assets and isolate storage paths by tenant.

A later white-label release can add custom domains, domain verification, TLS provisioning, branded email templates, and plan-based feature eligibility. A tenant must never claim another tenant's verified domain or branding assets.

Keep legal operator identity, privacy disclosures, security notices, and required product attribution configurable only where legally and operationally appropriate. White-label branding must not conceal the actual data controller or support responsibility.

## 7. AI cost and usage controls

Retain the separately approved global budget policy:

- USD 5.00 per UTC day across the entire platform.
- USD 50.00 per UTC calendar month across the entire platform.

Add tenant-level quotas and plan allowances separately. A tenant quota cannot increase the global platform cap. Every billable attempt must reserve global capacity and satisfy tenant entitlements before provider submission. Keep per-attempt usage and billing records attributable to the tenant.

Do not advertise hard budget enforcement until reservation, settlement, ambiguous-outcome reconciliation, and concurrency tests pass.

## 8. Delivery roadmap and release gates

Estimates below are planning ranges, not delivery commitments. They assume focused development and timely access to infrastructure and payment-provider accounts.

### Phase 1 — Internal production foundation

Estimate: 4–6 working days for the currently identified release-readiness work.

- Resolve current CI failures and obtain a fully green consolidated run.
- Complete global AI budget enforcement and recovery tests.
- Implement retention dry-run controls; do not execute destructive pruning.
- Verify backup/restore, package signing, operational configuration, and security controls.
- Run a controlled, read-only internal WHMCS pilot after explicit deployment approval.

Exit gate: internal pilot is stable, costs are observable, recovery is tested, and no unresolved critical/high security issue remains.

### Phase 2 — Shared-database multi-tenancy

Estimate: 5–8 working days after Phase 1, subject to schema complexity.

- Implement tenants and tenant memberships.
- Map existing ResellerBytes data to the initial tenant.
- Add tenant ownership to all tenant-scoped records and background jobs.
- Implement role/permission policies and tenant-scoped audit/usage.
- Add cross-tenant authorization and isolation tests.
- Verify migration, rollback, and data integrity on a production-like copy.

Exit gate: all tenant isolation tests pass; external tenant provisioning remains disabled until then.

### Phase 3 — Commercial SaaS and dual billing

Estimate: 7–12 working days, excluding payment-provider approval delays.

- Add plans, subscriptions, entitlements, invoices, and billing events.
- Implement a provider-neutral adapter and one approved automated payment integration.
- Implement manual invoice/payment recording and adjustment audit.
- Add idempotent signed webhook processing and reconciliation.
- Implement customer registration, onboarding, plan limits, and customer-facing usage views.
- Test payment failure, duplicate events, refund, cancellation, renewal, and grace-period behavior.

Exit gate: payment lifecycle and entitlements are reconciled and tested for both payment paths.

### Phase 4 — Branding and product expansion

Estimate: 7–14 working days.

- Add tenant branding settings and safe asset management.
- Add branded transactional email and support details.
- Add advanced reporting, scheduled diagnostics, and notifications.
- Prepare custom-domain verification and white-label entitlement design.
- Implement white-label domain/TLS workflows only after security review.

Exit gate: tenant branding cannot cross tenant boundaries and core support/security disclosures remain accurate.

### Phase 5 — Controlled commercial launch

Estimate: 5–7 working days.

- Publish pricing, product documentation, privacy terms, and service terms.
- Onboard a small number of external beta customers.
- Monitor AI cost, diagnostic quality, queue health, support load, and churn signals.
- Resolve beta findings before general availability.
- Publish operational runbooks, incident process, backup/restore evidence, and rollback procedure.

Exit gate: beta findings are resolved and operational ownership is established.

Overall first-commercial-release planning range: approximately 5–9 weeks. This can change after Phase 1 and the tenant-data inventory.

## 9. Explicit non-goals for the first commercial release

- No microservices split without measured need.
- No automatic remediation or production changes by AI.
- No separate database per standard customer; shared database is the approved default.
- No custom-domain white-label promise until domain ownership, TLS, routing, and tenant isolation are implemented.
- No assumption that a payment redirect proves payment.
- No customer onboarding before tenant isolation and billing entitlements pass their release gates.
- No destructive retention run without approved holds, dry-run review, and verified restore.

## 10. Immediate next actions

1. Finish and verify the current release-readiness branch; the latest CI failure must be fixed before merge.
2. Keep this architecture as the approved target, but do not mix the full SaaS implementation into the current release-readiness change.
3. After the current release PR is accepted and merged, create a dedicated multi-tenancy implementation branch.
4. Start that branch with a schema/relationship inventory and tenant backfill design before writing migrations.
5. Keep external registration and payment collection disabled until their phase exit gates pass.

## 11. Decision record

Approved by operator on 2026-11-12:

- Hosting: shared application and shared database with strict tenant isolation.
- Billing: automated recurring billing and manual invoices/payments, reconciled through one subscription/entitlement model.
- Branding: ResellerBytes-branded product plus a white-label path for eligible customers.

These are approved architectural decisions. Implementation, tests, provider selection, and production readiness remain separate verification steps.


### Tenant isolation implementation checkpoint — 2026-11-15

The release-readiness branch now adds a first account-ownership enforcement layer as groundwork, not as completion of the organization-level SaaS tenancy phase:

- `whmcs_integrations.owner_user_id` is a nullable FK to the verified application user; existing integrations are deliberately left unassigned by migration rather than guessed or bulk-mapped.
- New integration provisioning requires an explicit `--owner` email resolving to an existing email-verified account with confirmed TOTP MFA.
- A one-way, audited `whmcs:integration:assign-owner` command can map an unassigned legacy integration to a selected verified MFA account. It refuses reassignment once ownership exists.
- Dashboard aggregates, integration lists, diagnostic lists/details, finding lists/details, and reanalysis actions are scoped to the authenticated integration owner. Cross-owner detail access returns 404.
- Cross-owner regression coverage has been added for list pages, aggregate counts, details, and reanalysis. Test execution is pending the required PHP 8.5 environment.
- Diagnostic analysis queue jobs now carry the captured owner user ID as explicit serialized context. Workers verify the integration still belongs to that owner before starting, before provider submission, before persisting findings, and when marking failures; owner changes or unassigned integrations fail closed.
- Snapshot ingestion dispatches analysis only for owned integrations. Manual reanalysis passes the authenticated owner ID. Stale-queue recovery joins the integration owner, ignores unassigned legacy records, revalidates ownership under lock, and dispatches with the captured owner ID.

This is not yet the complete shared-database tenant model approved for SaaS. Initial organization/workspace and membership tables/models plus fail-closed membership resolution are now authored as additive schema groundwork. The integration/diagnostic/finding read paths and dashboard now use active membership-aware query scoping; tenant-assigned data no longer falls back to historical owner access. Analysis jobs capture and revalidate tenant identity and active status, including stale queue recovery. Manual reanalysis remains legacy-owner-only until the full tenant job authorization and audit context are complete. AI usage attempts and budget periods now capture tenant identity and enforce both tenant and platform daily/monthly caps atomically. New audit events carry tenant attribution under hash version 2 while legacy version 1 hashes remain verifiable. Historical usage/audit attribution remains null pending explicit mapping. Tenant-aware billing/subscription entitlements, role-specific write permissions, tenant-aware manual reanalysis, exports/downloads/webhooks/notifications, cross-tenant database constraints, backfill verification, and PostgreSQL RLS defense-in-depth remain roadmap work. External customer onboarding must remain disabled until those gates pass.

Existing integrations have `owner_user_id = NULL` after migration and are intentionally invisible to user-scoped dashboards until Shahrukh reviews and explicitly assigns each one. Do not infer or auto-assign their owner.

### Tenant schema and backfill design checkpoint — 2026-11-15

A detailed shared-database tenant schema and safe backfill design is recorded in `docs/architecture/tenant-schema-and-backfill-design.md`. It defines organization/workspace tenants, explicit user memberships and roles, tenant attribution for integrations/diagnostics/AI usage/budget/audit/legal holds, global operational telemetry boundaries, tenant-aware job execution, PostgreSQL RLS defense-in-depth, isolation test matrix, and expand/backfill/verify/contract rollout.

The tenant migration is authored on the branch but has not been executed; no tenant rows or data backfill are applied. This is intentional: the first tenant slug and explicit mapping of existing integrations require Shahrukh's approval. The design preserves the existing global audit hash chain and indefinite audit retention, 365-day diagnostic retention with no exceptions, and current platform-wide AI budget cap while introducing a future tenant-level cap. Existing unassigned records remain quarantined; external onboarding and payment collection stay disabled.


### Tenant billing and authorization implementation checkpoint — 2026-11-15

- Added tenant subscription, invoice and payment ledger schemas with billing-mode and provider reference fields, entitlement snapshots, paid periods and explicit grace windows. This supports the approved hybrid recurring + manual billing architecture without selecting or activating a payment provider.
- Added fail-closed subscription entitlement resolution and a role capability policy. Tenant reanalysis requires owner/admin/operator membership and a matching subscription feature. AI analysis jobs require a separate active subscription entitlement before any AI budget reservation or provider submission.
- Manual reanalysis is now tenant-aware end-to-end: actor role and entitlement are checked at request and transaction boundaries, the actor ID and tenant UUID are captured in the queued job, and audit events carry tenant attribution. Legacy unassigned integrations remain owner-only.
- Added an idempotent payment ledger for already-confirmed manual/automated payments, with invoice tenant consistency, overpayment protection, currency checks, manual billing-role authorization, and audit events. No payment collection is initiated and no external webhook is trusted without a future signature-verification integration.
- Automated billing and manual invoice/payment paths share the same tenant subscription and entitlement snapshot model. No provider, pricing, or payment collection is activated; Shahrukh approval remains required.
- Test coverage has been authored for role boundaries, subscription expiry/grace, feature/limit denial, manual reanalysis entitlement, AI entitlement gating, payment idempotency and tenant isolation. Tests remain unexecuted pending PHP 8.5/runtime dependencies.
- Migration `2026_11_15_000005_create_tenant_billing_entitlements.php` is authored only and has not been executed. No subscription, invoice or payment data has been provisioned.


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


### Tenant resource quota enforcement — 2026-11-15

- Added `TenantResourceLimitService`, which resolves the active subscription entitlement snapshot, locks the subscription row, revalidates the selected subscription after acquiring the lock, and fails closed when a limit is absent, malformed, or exceeded.
- Supported snapshot limit keys are `limits.integrations` (maximum active tenant integrations) and `limits.diagnostics_per_period` (maximum diagnostic snapshots received inside the active subscription billing period). Numeric values are supplied by an approved plan snapshot; no prices or default quotas are invented.
- Diagnostic workers now check the tenant's `diagnostics.analysis` feature and diagnostic-period quota before reserving global AI budget or contacting the provider. The current diagnostic is included in the period count, so a tenant exactly at its allowance can process its final eligible run; any over-limit run is denied before AI spend.
- Tenant-scoped `whmcs:integration:create --tenant=<UUID> --owner=<verified-MFA-email>` now validates active tenant membership and `integrations.manage`, checks the active integration quota under the locked subscription row, and writes the integration plus tenant-attributed audit event inside a tenant RLS context and one transaction. Existing legacy-to-tenant mapping remains a controlled backfill and is not treated as customer provisioning.
- Added feature coverage for exact-limit and over-limit integration/diagnostic boundaries. Tests are authored but have not been executed in this environment. No plan catalogue, pricing, tenant subscription or production quota values were activated.


### Tenant membership lifecycle and branding write controls — 2026-11-15

- Added tenant membership invitation for existing accounts with verified email. Invitation roles are restricted to admin, operator, viewer, and billing; owner authority is never granted through self-service invitation.
- Added invitation acceptance by the invited authenticated account, role changes, suspension, revocation, and membership listing. Each state transition runs under tenant RLS context, a tenant-row lock and database transaction, and appends tenant-attributed audit evidence.
- Admin/owner capability checks are enforced server-side. Owner memberships cannot be demoted, suspended, or revoked through member administration. Revoked memberships can be re-invited by reusing the unique tenant/user membership row; active, pending, and suspended duplicates are rejected.
- Invitation acceptance uses an opaque tenant UUID route parameter rather than implicit tenant model binding so an invited-but-not-yet-active user can reach acceptance. The service then establishes tenant RLS context and verifies the invitation belongs to the authenticated user.
- Added tenant branding settings read/write endpoints. Branding writes are restricted to owner/admin, validate display name, support email, six-digit hex primary color and HTTPS logo URL, preserve unrelated tenant settings, and append audit evidence. Arbitrary settings, custom domains, and billing/identity fields are not writable through this endpoint.
- Feature tests cover lifecycle transitions, denied role changes, immutable owner authority, verified-account invitation rules, branding authorization, preservation of unrelated settings, and audit evidence. Tests are authored but have not been executed in the current PHP/dependency environment.
- Invitation delivery/email transport is not enabled here; this implementation creates auditable in-app pending invitations only. Production email delivery, sender/domain configuration and secret provisioning remain external prerequisites.


### Subscription lifecycle state machine — 2026-11-15

- Added an internal TenantSubscriptionLifecycle service with explicit transitions for trialing, active, past-due, suspended, canceled and expired states. Canceled and expired subscriptions are terminal; invalid transitions fail closed.
- Billing-role actors must hold the tenant billing.manage capability. Trusted system reconciliation may call the service without a user actor, but no public webhook or HTTP entry point is exposed.
- Past-due transitions require a future grace deadline; recovery clears grace, cancellation records canceled_at, and each actual state change appends tenant-attributed audit evidence in the same database transaction. Repeated identical transitions are no-ops and do not duplicate audit events.
- Tests cover grace-period entitlement behavior, role denial, terminal-state protection, invalid grace, audit evidence and idempotent no-op behavior. Tests are authored but not executed in this environment.
- No plan catalogue, price, currency/tax policy, payment provider, webhook endpoint, outbound delivery, subscription activation or production entitlement has been configured. Provider-signed event ingestion and transactional outbox delivery remain separate work and require provider selection, endpoint/security policy and key custody before activation.


### Subscription lifecycle payment-gate hardening — 2026-11-15

- Tightened recovery from past-due/suspended: the lifecycle service now requires a same-tenant, same-subscription invoice marked fully paid and independently checks succeeded payment-ledger totals before restoring active status. A manually edited paid flag alone cannot reactivate entitlements.
- Expiry is rejected before the paid period ends or while a past-due grace period remains open. Cancellation/expiry remain terminal; state changes are audited transactionally.
- Added regression cases for missing settlement evidence and a forged paid invoice without a confirmed payment record. Tests are authored but not executed.
