# Tenant billing outbox and webhook reconciliation

Status: repository implementation only; provider activation and database provisioning pending  
Last updated: 2026-11-15

## Scope and trust boundary

The billing ledger is the source of truth. This implementation adds a transactional outbound event outbox and an inbound webhook inbox so provider callbacks can be reconciled without trusting duplicate, cross-tenant, or unverified deliveries.

No payment provider has been selected. No public webhook endpoint, provider SDK, charge initiation, subscription checkout, customer portal, or external tenant webhook destination is enabled. The internal inbox service may only be called by a future provider adapter after that adapter verifies the provider signature, resolves the provider account to exactly one tenant, and maps the provider event to the documented normalized payload. The inbox service is not a signature verifier.

## Transactional outbox

tenant_billing_outbox stores tenant-scoped events in the same database transaction as the billing state change. Payment ledger writes and subscription status transitions enqueue their event before the transaction commits. A rollback therefore removes both the business mutation and its outbox record.

- Idempotency is unique per (tenant_id, idempotency_key). Repeating the same event returns the original row; reusing the key for different event content fails closed.
- PostgreSQL RLS is enabled on the outbox table. Composite tenant ownership is represented directly by tenant_id.
- Database triggers make tenant identity, event identity, aggregate identity, and payload immutable after insert; delivery state and retry counters remain mutable. A separate audit trigger verifies that webhook-receipt audit rows carry the exact tenant of the referenced inbox event.
- billing:outbox:dispatch --limit=100 publishes due events as TenantBillingOutboxPublished on Laravel's event dispatcher.
- Delivery is at-least-once: a crash after listener side effects but before the delivered marker is committed can cause a redelivery. Every listener MUST deduplicate on the stable outboxId and must not assume exactly-once delivery.
- Attempts use exponential retry starting at 30 seconds, capped at six hours, with a maximum of 12 attempts. A message that exhausts attempts is marked dead_lettered; it is not silently deleted.
- A processing lease older than five minutes can be reclaimed by another worker.

The event bus is an internal extension point. It is not an outbound HTTP webhook transport. A future transport must add destination ownership verification, allowlisting/SSRF controls, signed payloads, secret custody, delivery receipts, and independent security review before activation.

## Provider-neutral verification and account routing

- BillingProviderAdapter defines the provider boundary. Each implementation identifies its provider and must verify the exact raw request body against provider-specific signature headers before returning a normalized VerifiedBillingWebhook value object. No generic HMAC assumption is made because providers use different signing schemes.
- TenantBillingWebhookGateway rejects provider/adapter mismatches, caps raw request bodies at 256 KiB, enforces a five-minute signature timestamp window, resolves the provider account through trusted server-side mapping, and only then calls the inbox.
- tenant_billing_provider_accounts binds one provider account to exactly one tenant with a global unique key. A mapping is routable only when status=active and verified_at is present. Tenant/provider/account identity is immutable; the table has PostgreSQL RLS and the global resolver runs under the dedicated operations connection.
- Provider account mapping is deliberately not self-service and no HTTP route is exposed. An operator must verify account ownership and approve the tenant mapping before activation. No credentials or signing secrets are stored in this registry.
- The inbox records server receipt time separately from the provider event occurrence timestamp. Signature timestamp replay checks are independent from provider event time; event IDs remain the durable duplicate-delivery key.

## Verified webhook inbox

tenant_billing_webhook_events stores a provider-account-scoped, normalized event envelope:

- Tenant, provider, provider account, and provider event ID are bound. Unique constraints prevent duplicate event IDs both within a tenant and for the same provider account.
- The normalized payload is restricted to allowlisted keys for payment success and subscription past-due/canceled/expired/reactivated events. Raw provider payloads, signature headers, credentials, and arbitrary metadata are not stored.
- A canonical SHA-256 digest binds the normalized payload. A duplicate with the same digest is an idempotent success; a duplicate with changed content is rejected. Concurrent duplicate inserts recover the winner's row after a unique-key race.
- First receipt appends a tenant-attributed immutable audit event containing provider, event type, and payload digest only.
- Supported reconciliation events are invoice.payment_succeeded, subscription.past_due, subscription.canceled, subscription.expired, and subscription.reactivated. Reactivation requires a fully settled invoice belonging to the same tenant and subscription.
- Payment confirmation calls the existing confirmed-payment ledger. Invoice locking, provider payment ID uniqueness, payment idempotency, audit append, and outbox enqueue occur in the same transaction as marking the inbox event processed.
- Malformed/unsupported events are dead-lettered. Transient failures retry with exponential backoff starting at 30 seconds, capped at six hours, for at most 12 attempts. No raw exception message or payload is written to logs.
- Processing leases older than five minutes are eligible for recovery. Processed and dead-lettered rows are retained; no automatic purge is configured.

billing:webhooks:reconcile --limit=100 performs the bounded reconciliation pass. Provider signature verification and provider-account-to-tenant mapping must happen before calling TenantBillingWebhookInbox::acceptVerifiedEvent.

## PostgreSQL RLS and operations role

The ordinary runtime PostgreSQL login remains tenant-scoped and must not receive BYPASSRLS, table ownership, or superuser rights. Global scheduled reconciliation needs to scan due rows across tenants, so both commands execute through the separate pgsql_operations connection.

Configure only through the approved secret manager/runtime environment:

- DB_OPERATIONS_HOST
- DB_OPERATIONS_PORT
- DB_OPERATIONS_DATABASE
- DB_OPERATIONS_USERNAME
- DB_OPERATIONS_PASSWORD
- DB_OPERATIONS_SSLMODE

.env.example contains blank placeholders only. Do not put credentials in source control or chat. The role must be separately provisioned, reviewed, and limited to the platform operations duties required for these billing tables and the transactional billing reconciliation services. Its provisioning and RLS rehearsal remain owner/infrastructure gates. The commands fail closed on PostgreSQL if the dedicated operations username is absent; they do not fall back to the tenant runtime login.

Both commands are scheduled every minute with overlap protection. Before enabling them in a PostgreSQL environment, rehearse with the actual non-production operations role and prove that the runtime role cannot read or mutate another tenant's outbox/inbox rows.

## Operator reconciliation

Before any provider is activated:

1. Select and approve the provider, pricing, merchant-account ownership model, webhook signature contract, and event mapping.
2. Implement the selected provider adapter against BillingProviderAdapter; verify signatures against the exact raw request body and normalize only supported events.
3. Verify provider account ownership and create an approved active tenant mapping; never resolve a tenant from caller-supplied data.
4. Add provider-specific secret rotation, rate limits, and provider contract negative tests. The common gateway already enforces the five-minute signature timestamp window and records provider event time.
5. Rehearse duplicate delivery, changed-payload replay, cross-tenant account mapping, concurrent delivery, worker crash after side effect, transient DB failure, dead-letter handling, and settled-invoice reactivation.
6. Provision the separate PostgreSQL operations role and secret-manager access; test the runtime and operations privilege boundaries.
7. Complete independent security review and obtain explicit release authorization before any production activation.

No production charge, webhook endpoint, database migration, or deployment is authorized by this runbook.
