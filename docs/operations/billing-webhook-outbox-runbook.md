# Tenant billing outbox and webhook reconciliation

Status: repository implementation only; provider activation and database
provisioning pending
Last updated: 2026-11-15

## Scope and trust boundary

The billing ledger is the source of truth. This implementation adds a
transactional outbound event outbox and an inbound webhook inbox so provider
callbacks can be reconciled without trusting duplicate, cross-tenant, or
unverified deliveries.

No payment provider has been selected. No public webhook endpoint, provider SDK,
charge initiation, subscription checkout, customer portal, or external tenant
webhook destination is enabled. The internal inbox service may only be called by
a future provider adapter after that adapter verifies the provider signature,
resolves the provider account to exactly one tenant, and maps the provider event
to the documented normalized payload. The inbox service is not a signature
verifier.

## PostgreSQL operations-role boundary

The `pgsql_operations` connection must use a dedicated `rb_billing_ops` login, distinct from the tenant
runtime identity. It is the only application login with `BYPASSRLS`, but it is never a
superuser, database/role creator, replication role, schema creator, migrator
member, or owner of application tables. Its effective privileges are SELECT-only
on exactly `tenant_billing_provider_accounts`, `tenant_billing_webhook_events`, and `tenant_billing_outbox`; no other table or sequence privileges are allowed.
The application connection guard checks role flags, database identity, schema
privileges, ownership, and the effective table allowlist before any cross-tenant
read. Any mismatch fails closed and billing telemetry remains unknown rather
than healthy.

The RLS rehearsal creates and cleans its outbox, inbox, and provider-account
fixtures using the tenant-scoped runtime role with tenant context set before
writes. The bypass role is used only to verify its catalog/role boundary; it is
not used as a fixture writer. Provision `rb_billing_ops` using the DBA-only procedure in `postgresql-role-separation.md`,
and retain sanitized privilege-verification output before enabling the
application connection.

## Transactional outbox

tenant_billing_outbox stores tenant-scoped events in the same database
transaction as the billing state change. Payment ledger writes and subscription
status transitions enqueue their event before the transaction commits. A
rollback therefore removes both the business mutation and its outbox record.

-  Idempotency is unique per (tenant_id, idempotency_key). Repeating the same
  event returns the original row; reusing the key for different event content
  fails closed.
-  PostgreSQL RLS is enabled on the outbox table. Composite tenant ownership is
  represented directly by tenant_id.
-  Database triggers make tenant identity, event identity, aggregate identity,
  and payload immutable after insert; delivery state and retry counters remain
  mutable. A separate audit trigger verifies that webhook-receipt audit rows
  carry the exact tenant of the referenced inbox event.
-  billing:outbox:dispatch --limit=100 publishes due events as
  TenantBillingOutboxPublished on Laravel's event dispatcher.
-  Delivery is at-least-once: a crash after listener side effects but before the
  delivered marker is committed can cause a redelivery. Every listener MUST
  deduplicate on the stable outboxId and must not assume exactly-once delivery.
-  Attempts use exponential retry starting at 30 seconds, capped at six hours,
  with a maximum of 12 attempts. A message that exhausts attempts is marked
  dead_lettered; it is not silently deleted.
- A processing lease older than five minutes can be reclaimed by another worker.

The event bus is an internal extension point. It is not an outbound HTTP webhook
transport. A future transport must add destination ownership verification,
allowlisting/SSRF controls, signed payloads, secret custody, delivery receipts,
and independent security review before activation.

## Provider-neutral verification and account routing

-  BillingProviderAdapter defines the provider boundary. Each implementation
  identifies its provider and must verify the exact raw request body against
  provider-specific signature headers before returning a normalized
  VerifiedBillingWebhook value object. No generic HMAC assumption is made
  because providers use different signing schemes.
-  TenantBillingWebhookGateway rejects provider/adapter mismatches, caps raw
  request bodies at 256 KiB, enforces a five-minute signature timestamp window,
  resolves the provider account through trusted server-side mapping, then
  resolves provider invoice/subscription references to local IDs within that
  tenant/account before calling the inbox. Provider adapters never choose local
  tenant, invoice, or subscription IDs.
-  tenant_billing_provider_accounts binds one provider account to exactly one
  tenant with a global unique key. Provider invoice IDs are stored on invoices
  under a tenant/provider/account-scoped unique key; provider subscription IDs
  are stored on subscriptions and bound to the same account. A mapping is
  routable only when status=active and verified_at is present.
  Tenant/provider/account identity is immutable; the table has PostgreSQL RLS
  and the global resolver runs under the dedicated operations connection.
-  Provider account mapping is tenant-billing-authorized and audited through
  TenantBillingProviderAccountService; registration records an attestation after
  provider-side account ownership has been independently verified. The service
  itself does not verify credentials, and no HTTP route is exposed.
  Shahrukh/provider owner approval is required before any account mapping is
  activated. No credentials or signing secrets are stored in this registry.
-  The inbox records server receipt time separately from the provider event
  occurrence timestamp. Signature timestamp replay checks are independent from
  provider event time; event IDs remain the durable duplicate-delivery key.

## Verified webhook inbox

tenant_billing_webhook_events stores a provider-account-scoped, normalized event
envelope:

-  Tenant, provider, provider account, and provider event ID are bound. Unique
  constraints prevent duplicate event IDs both within a tenant and for the same
  provider account. Invoices, confirmed automated payment rows and subscriptions
  persist the originating provider account and provider-side reference IDs.
  Provider/account/subscription identity is immutable once bound. Automated
  payment writes, subscription status/renewal webhooks, refunds and disputes
  must match that exact account and payment, not merely a provider or tenant ID.
-  The normalized payload is restricted to allowlisted keys for payment success
  and subscription past-due/canceled/expired/reactivated events. Raw provider
  payloads, signature headers, credentials, and arbitrary metadata are not
  stored.
-  A canonical SHA-256 digest binds the normalized payload. A duplicate with the
  same digest is an idempotent success; a duplicate with changed content is
  rejected. Concurrent duplicate inserts recover the winner's row after a
  unique-key race.
-  First receipt appends a tenant-attributed immutable audit event containing
  provider, event type, and payload digest only.
-  Supported reconciliation events are invoice.payment_succeeded,
  invoice.payment_failed, payment.refunded, payment.dispute_opened,
  payment.dispute_won, payment.dispute_lost, subscription.past_due,
  subscription.canceled, subscription.expired, subscription.reactivated, and
  subscription.renewed. Failed payment attempts mark the unpaid invoice past_due
  and move eligible active/trialing subscriptions into a configurable grace
  period (default seven days, bounded to 1–30 days). Renewals require a fully
  settled invoice for the same tenant/subscription and the same provider account
  that sent the signed renewal event, plus a contiguous period extension;
  duplicate renewal events for the same paid invoice are idempotent.
  Reactivation webhooks apply the same provider-account payment evidence
  requirement. Reactivation requires a fully settled invoice belonging to the
  same tenant and subscription.
-  Payment confirmation calls the existing confirmed-payment ledger. Invoice
  locking, provider payment ID uniqueness, payment idempotency, audit append,
  and outbox enqueue occur in the same transaction as marking the inbox event
  processed.
-  Failed payment attempts mark unpaid invoices past_due and place eligible
  subscriptions into the configurable grace period (default seven days, bounded
  to 1–30 days). A settled renewal invoice must belong to the same
  tenant/subscription, match currency, and have confirmed payment ledger
  coverage; renewal periods must be contiguous and no longer than 366 days.
-  Confirmed payment rows are append-only and have tenant-bound audit evidence.
  Refunds and dispute lifecycle events are recorded in the append-only
  tenant_billing_adjustments ledger. A composite foreign key binds every
  adjustment to a payment owned by the same tenant. Refunds and lost disputes
  reduce the invoice net paid balance; an open dispute does not change
  entitlements, and a won dispute does not reduce the paid balance.
  Refund/dispute losses cannot cumulatively exceed the original payment amount.
  Underpaid subscriptions enter the same grace workflow.
-  Malformed/unsupported events are dead-lettered. Transient failures retry with
  exponential backoff starting at 30 seconds, capped at six hours, for at most
  12 attempts. No raw exception message or payload is written to logs.
-  Processing leases older than five minutes are eligible for recovery.
  Processed and dead-lettered rows are retained; no automatic purge is
  configured.

billing:webhooks:reconcile --limit=100 performs the bounded reconciliation pass.
Provider signature verification and provider-account-to-tenant mapping must
happen before calling TenantBillingWebhookInbox::acceptVerifiedEvent.

## PostgreSQL RLS and operations role

The ordinary runtime PostgreSQL login remains tenant-scoped and must not receive
BYPASSRLS, table ownership, or superuser rights. The separately provisioned
operations role must be a distinct PostgreSQL login with BYPASSRLS for the
global scheduled scans, narrowly granted only the table privileges required for
these platform billing tasks, and must not be a superuser or table owner. The
application verifies BYPASSRLS, non-superuser status, same-database targeting,
and non-ownership before any global reconciliation runs. Global scheduled
reconciliation needs to scan due rows across tenants, so both commands execute
through the separate pgsql_operations connection.

Configure only through the approved secret manager/runtime environment:

- DB_OPERATIONS_HOST
- DB_OPERATIONS_PORT
- DB_OPERATIONS_DATABASE
- DB_OPERATIONS_USERNAME
- DB_OPERATIONS_PASSWORD
- DB_OPERATIONS_SSLMODE

.env.example contains blank placeholders only. Do not put credentials in source
control or chat. The role must be separately provisioned, reviewed, and limited
to the platform operations duties required for these billing tables (including
provider-account mappings and append-only adjustment records),
invoice/payment/subscription rows, and transactional billing reconciliation
services. Its provisioning and RLS rehearsal remain owner/infrastructure gates.
The commands fail closed on PostgreSQL if the dedicated operations username is
absent, the operations connection is not PostgreSQL, or its current database
differs from the runtime connection; they do not fall back to the tenant runtime
login.

Both commands are scheduled every minute with overlap protection. Before
enabling them in a PostgreSQL environment, rehearse with the actual
non-production operations role and prove that the runtime role cannot read or
mutate another tenant's outbox/inbox rows.

## Operator reconciliation

Before any provider is activated:

1.  Select and approve the provider, pricing, merchant-account ownership model,
   webhook signature contract, and event mapping.
2.  Implement the selected provider adapter against BillingProviderAdapter;
   verify signatures against the exact raw request body and normalize only
   supported events.
3.  Verify provider account ownership and create an approved active tenant
   mapping; never resolve a tenant from caller-supplied data.
4.  Add provider-specific secret rotation, rate limits, and provider contract
   negative tests. The common gateway already enforces the five-minute signature
   timestamp window and records provider event time.
5.  Rehearse duplicate delivery, changed-payload replay, cross-tenant account
   mapping, concurrent delivery, worker crash after side effect, transient DB
   failure, dead-letter handling, and settled-invoice reactivation.
6.  Provision the separate PostgreSQL operations role and secret-manager access;
   test the runtime and operations privilege boundaries.
7.  Complete independent security review and obtain explicit release
   authorization before any production activation.

No production charge, webhook endpoint, database migration, or deployment is
authorized by this runbook.


## Redacted billing operations report

Run `php artisan billing:operations:report` for a compact aggregate view, or add `--json` for machine-readable evidence.
The report contains only schema version, UTC observation time, and aggregate
counts/ages for due pending work, processing leases older than five minutes,
dead letters, retry-exhausted records, and records at the three-manual-replay
ceiling. It intentionally omits tenant IDs, provider/account identifiers, event
IDs, payloads, signatures, and exception text. On PostgreSQL it runs only
through the separately provisioned operations role and fails closed if that role
boundary is invalid. This is report-only telemetry; alert thresholds and
delivery routing are not inferred or enabled by this command.


## Billing alert integration

The scheduled `operations:metrics` snapshot includes aggregate billing webhook-inbox and
transactional-outbox health, read through the separately guarded PostgreSQL
operations connection. It reports dead-letter counts, processing leases older
than five minutes, and the oldest due pending item age. It never exports tenant
IDs, provider/account identifiers, event IDs, or payload data.

The existing `operations:check-alerts` policy raises deduplicated alerts when either queue has any
dead-lettered item, any processing lease older than five minutes, or a due
pending item older than the approved 15-minute queue threshold. Missing tables
or an unavailable/invalid operations role produce null billing metrics; those
gaps do not resolve existing alerts as healthy. Notifications remain disabled by
default (`OPERATIONS_ALERTS_ENABLED=false`) until owner-configured destinations are provisioned. Central mail
transport remains Log now, SMTP first, Postmark at launch.
