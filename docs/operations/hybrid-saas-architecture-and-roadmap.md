# Hybrid SaaS architecture and product roadmap

Status: operator-approved architecture direction; implementation pending
Reviewed: 2026-11-12
Applies to: ResellerBytes AI Operations

## 1. Product direction

Follow a hybrid path: validate the platform internally for ResellerBytes hosting operations, then commercialize the same platform for external hosting providers as subscription SaaS. ResellerBytes is represented as a normal tenant, not a privileged data-sharing space.

## 2. Approved architecture decisions

| Area | Approved decision | Implementation consequence |
| --- | --- | --- |
| Hosting and data | One shared application and shared database | Strict tenant isolation at every data-access boundary. |
| Billing | Automated recurring billing and manual invoices/payments | One subscription, billing-event, and entitlement model reconciles both paths. |
| Branding | ResellerBytes brand plus white-label path | Tenant-configurable branding; advanced white-label capabilities follow the first commercial release. |

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
