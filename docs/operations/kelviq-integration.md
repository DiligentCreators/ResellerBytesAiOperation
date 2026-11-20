# Kelviq integration

ResellerBytes AI Ops uses Kelviq as the production payment gateway (`BILLING_GATEWAY=kelviq`). Local development may keep `BILLING_GATEWAY=sandbox`.

## Setup

1. Create a Kelviq organization and product/plans in the **sandbox** dashboard first.
2. Copy the server API key and webhook signing secret (`kq_whsec_...`).
3. Set env keys (never commit real values):

```
BILLING_GATEWAY=kelviq
KELVIQ_MODE=sandbox
KELVIQ_API_KEY=
KELVIQ_WEBHOOK_SECRET=
KELVIQ_SANDBOX_BASE_URL=https://sandboxapi.kelviq.com/api/v1
KELVIQ_LIVE_BASE_URL=https://api.kelviq.com/api/v1
KELVIQ_TIMEOUT=15
KELVIQ_CONNECT_TIMEOUT=5
```

4. In Platform → Billing → Plans, set **Kelviq plan id (monthly)** and **Kelviq plan id (yearly)** per catalog plan.
5. Register webhook endpoint URL: `{APP_URL}/webhooks/kelviq`
6. Subscribe at least to: `checkout.completed`, `subscription.created`, `subscription.updated`, `subscription.cancelled`, `subscription.plan_changed`, `subscription.paused`, `subscription.resumed`, `invoice.created`, `invoice.paid`, `invoice.payment_failed`, `refund.created`, `refund.updated`, `order.refunded`.
7. Run `php artisan billing:kelviq:check` (prints booleans and URLs only — never secrets).

## Checkout behaviour

- We create/reuse a Kelviq customer with `customerId = acct_kelviq_{tenantUuidSansDashes}` (same as the local provider account id).
- Checkout metadata includes `tenant_id`, `plan`, `period`, `provider_invoice_id`, `local_invoice_id`, `local_subscription_id`.
- Success redirect lands on **Payment processing** and does **not** activate entitlements.
- Activation happens only after signed webhook → inbox → `billing:webhooks:reconcile` (also queued from the webhook controller).

## Plan mapping

| Local field                            | Kelviq                                    |
| -------------------------------------- | ----------------------------------------- |
| `plans.kelviq_plan_identifier_monthly` | `planIdentifier` + `chargePeriod=MONTHLY` |
| `plans.kelviq_plan_identifier_yearly`  | `planIdentifier` + `chargePeriod=YEARLY`  |

## Sandbox testing (from Kelviq docs)

Any future expiry + any CVC. Useful cards:

| Card                  | Result             |
| --------------------- | ------------------ |
| `4242 4242 4242 4242` | Success            |
| `4000 0000 0000 0002` | Declined           |
| `4000 0000 0000 9995` | Insufficient funds |
| `4000 0025 0000 3155` | 3DS then success   |

Never use real cards in sandbox.

## Go-live checklist

- [ ] Sandbox end-to-end: checkout → webhook → active subscription → invoice paid
- [ ] Plan change: current plan stays active until new payment webhook; abandoned change cancels only `incomplete`
- [ ] Webhook signature failures return 4xx; verify-endpoint probe (unsigned empty POST) returns 4xx
- [ ] `KELVIQ_MODE=live` with both `KELVIQ_API_KEY` and `KELVIQ_WEBHOOK_SECRET` set
- [ ] Live plan identifiers set on every public plan
- [ ] Platform → Billing → Gateway shows key/secret configured=true (values never displayed)
- [ ] `php artisan billing:kelviq:check` succeeds against live
- [ ] Ops runbook / secret custody / security review complete before first live charge

## What we need from Kelviq / Shahrukh

1. Confirm whether `POST /customers/` is upsert-safe on duplicate `customerId`, or which status code means “already exists”.
2. Confirm `trialPeriod` request shape/units before we enable trials on checkout.
3. Sandbox + live API keys and webhook secrets for ResellerBytes (custody with ops; not in git).
4. Canonical plan identifiers for Starter / Growth / Business (monthly + yearly).
5. Confirm invoices list/retrieve paths if we add customer portal deep-links beyond Kelviq’s hosted portal.
6. Preferred webhook event subset if Kelviq recommends a smaller subscription than the full list above.

## TODO markers in code

Search for `TODO(kelviq):` — items left open where public docs were incomplete.
