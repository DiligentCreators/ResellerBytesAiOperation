# Kelviq integration

ResellerBytes AI Ops uses Kelviq as the production payment gateway (`BILLING_GATEWAY=kelviq`). Local development may keep `BILLING_GATEWAY=sandbox`.

## Billing settings screen

Platform operators with the `billing.settings.manage` central permission (the configured super admin has it implicitly) manage gateway credentials from **Platform → Billing settings** (`/platform/billing/settings`). The gateway status page links here when the viewer holds that permission.

| Area | Behaviour |
| ---- | --------- |
| Gateway | Choose `sandbox` or `kelviq`. UI shows whether the effective value comes from the database or `.env` fallback. Switching to `kelviq` requires a resolvable API key for the selected mode. Sandbox remains blocked in production unless `BILLING_SANDBOX_ALLOW_IN_PRODUCTION` is enabled. |
| Kelviq mode + secrets | Mode `sandbox` \| `live`. Separate sandbox/live API keys and webhook secrets. The browser only receives configured yes/no, source (`database` \| `env`), and a last-4 mask (`••••1234`). Blank input keeps the current stored value. An explicit **Clear** checkbox nulls the DB column so env/config applies again. Live mode requires a resolvable live API key and live webhook secret. |
| Webhook URL | Read-only copy of `{APP_URL}/webhooks/kelviq` (named route `webhooks.kelviq`). |
| Test connection | Throttled POST that calls Kelviq `GET /webhook/logs/?page_size=1` with the resolved key for the current mode. Reports success or HTTP/connection failure only — never the key. Audited as `billing.settings.connection_tested`. |
| Trial rules (global) | `trials_enabled`, `trial_card_required`, `trial_first_time_only` stored on the single settings row and exposed via `App\Services\Billing\BillingSettings`. Per-plan `trial_enabled` + `trial_days` are managed on Platform → Billing → Plans. |

**Fallback order** (resolver `App\Services\Billing\BillingSettings`): non-empty database value wins; otherwise `config('billing.*')` / env; otherwise defaults (`gateway=sandbox`, `mode=sandbox`). Mode-specific DB keys fall back to the single env pair `KELVIQ_API_KEY` / `KELVIQ_WEBHOOK_SECRET`. If the `billing_gateway_settings` table is not migrated yet, the resolver falls back to config so Artisan commands do not crash.

**Permission**: `billing.settings.manage` — only the super admin may grant or revoke it on central staff. Holding `billing` or `settings` alone is not enough to open the screen or grant this permission.

**Audit**: each save writes one `billing.settings.updated` event listing changed field names; non-secret fields include old/new values; secret fields record only `replaced` or `cleared` (never the value, not even masked). No event when nothing changed.

## Setup

1. Create a Kelviq organization and product/plans in the **sandbox** dashboard first.
2. Copy the server API key and webhook signing secret (`kq_whsec_...`).
3. Prefer entering credentials in **Platform → Billing settings**. Env keys remain the fallback (never commit real values):

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
5. Register webhook endpoint URL: `{APP_URL}/webhooks/kelviq` (also shown on the billing settings screen).
6. Subscribe at least to: `checkout.completed`, `subscription.created`, `subscription.updated`, `subscription.cancelled`, `subscription.plan_changed`, `subscription.paused`, `subscription.resumed`, `invoice.created`, `invoice.paid`, `invoice.payment_failed`, `refund.created`, `refund.updated`, `order.refunded`.
7. Use **Test connection** on the settings screen, or run `php artisan billing:kelviq:check` (prints booleans and URLs only — never secrets).

## Checkout behaviour

- We create/reuse a Kelviq customer with `customerId = acct_kelviq_{tenantUuidSansDashes}` (same as the local provider account id).
- Checkout metadata includes `tenant_id`, `plan`, `period`, `provider_invoice_id`, `local_invoice_id`, `local_subscription_id`.
- Success redirect lands on **Payment processing** and does **not** activate entitlements.
- Activation happens only after signed webhook → inbox → `billing:webhooks:reconcile` (also queued from the webhook controller).

## Plans & prices

Platform operators with `billing.settings.manage` create and edit the catalog at **Platform → Billing → Plans**. Prices are entered as major units in the UI and stored as integer minor units. Saving a plan sets `prices_confirmed_at`; the public pricing page treats the catalog as placeholders while any public active plan still has that column null.

| Interval flags | Effect |
| -------------- | ------ |
| `monthly_active` / `yearly_active` | Inactive intervals are hidden on pricing / plans / checkout and rejected at checkout (validation error). |
| `is_active` | Deactivated plans disappear from the public catalog and new checkouts; existing subscriptions keep their stored snapshot. |

### Price-change effect on existing subscriptions

Checkout and plan-change flows snapshot `unit_amount_minor`, `currency`, and `charge_period` on the subscription (and amounts/currency on invoices). Editing a plan price does **not** reprice existing subscriptions or past invoices. MRR and proration use the stored subscription amount. Prefer the stored `charge_period`; the legacy null-period inference that compares `unit_amount_minor` to the current plan yearly price is only for backfill and can misclassify after a price edit — run the charge-period backfill command for null rows.

Kelviq renewals bill whatever price is configured on the **Kelviq** plan. When you change a price here, also update the matching Kelviq plan amount. Do **not** rely on Kelviq plan-level trials for ResellerBytes eligibility; trials are gated by `BillingSettings` + per-plan flags and started only via the explicit trial action.

## Plan mapping

| Local field                            | Kelviq                                    |
| -------------------------------------- | ----------------------------------------- |
| `plans.kelviq_plan_identifier_monthly` | `planIdentifier` + `chargePeriod=MONTHLY` |
| `plans.kelviq_plan_identifier_yearly`  | `planIdentifier` + `chargePeriod=YEARLY`  |

Configure matching Kelviq plan identifiers (monthly + yearly) and keep Kelviq list prices aligned with the catalog. Paid checkout never sends `trialPeriod`.

## Trials

Trials are an **explicit** separate action (`POST tenants/{tenant}/billing/trial/{plan}`), never part of paid checkout (RB-2).

Eligibility (`TrialPolicy::trialDaysFor`): global `trialsEnabled` AND plan `trial_enabled` AND `trial_days >= 1` AND (if `trialFirstTimeOnly`) the tenant has never had any subscription row.

| Mode | Behaviour |
| ---- | --------- |
| Card not required | Local `trialing` subscription, no invoice, no gateway call. `trial_ends_at` / period end = now + trial days. Snapshot stores the chosen interval amount for later paid conversion. |
| Card required | Zero-amount open invoice for session tracking + gateway checkout with `checkout_kind=trial`. Kelviq receives `trialPeriod` as integer days (>= 1), which overrides any Kelviq plan trial. Sandbox confirms the card hold without activating. |

`billing:expire-trials` (every five minutes) expires `trialing` rows whose `trial_ends_at` has passed. A later paid invoice webhook can still move `trialing` → `active` when a real charge succeeds.

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
2. ~~Confirm `trialPeriod` request shape/units~~ — treated as integer days (>= 1); only sent on explicit trial checkouts.
3. Sandbox + live API keys and webhook secrets for ResellerBytes (custody with ops; not in git).
4. Canonical plan identifiers for Starter / Growth / Business (monthly + yearly).
5. Confirm invoices list/retrieve paths if we add customer portal deep-links beyond Kelviq’s hosted portal.
6. Preferred webhook event subset if Kelviq recommends a smaller subscription than the full list above.

## Plan changes & proration

ResellerBytes computes plan-change economics in-app; Kelviq is used as a checkout/collection channel for amounts due today.

### Immediate upgrades (higher normalized monthly price, or monthly → yearly)

1. `ProrationCalculator` computes a day-based unused-time credit in integer minor units:
    - remaining days = whole days from start of today (UTC) to `current_period_ends_at`
    - credit = round(current period amount × remaining_days / total_days), floored at 0 and capped at the new plan price
    - `total_due` = new plan price − credit
2. The app creates a provisional `incomplete` subscription (`replaces_subscription_id`) and an open invoice whose line items are the full new-plan charge plus a negative credit line. Service period on the invoice is the new full interval starting now.
3. Kelviq (or Sandbox) receives a **one-off checkout for `total_due` only** — we do not ask Kelviq to prorate.
4. If `total_due` is `0`, the app settles locally (paid $0 invoice, activate new sub, cancel previous) with **no gateway call**.
5. Activation for paid upgrades still waits for the signed webhook → inbox → `billing:webhooks:reconcile` path.

### Scheduled downgrades (lower normalized monthly price, or any yearly → monthly)

1. No Kelviq/Sandbox checkout and no charge today. The current plan and limits stay active.
2. The pending change is stored on the subscription (`scheduled_plan_id`, `scheduled_charge_period`, `scheduled_change_at` = current period end). Scheduling again replaces the pending change; an immediate upgrade clears it.
3. `billing:apply-scheduled-plan-changes` (every five minutes) applies due rows. Because subscription plan snapshots are immutable, apply **cancels** the current row and creates a replacement `active` subscription (`replaces_subscription_id`) with the new plan/period starting at the old period end, then issues a renewal invoice for the new plan price.
4. Sandbox has no native renewal checkout — the command settles that renewal invoice in-ledger (paid + payment row) and writes audit/outbox events. For Kelviq live, the same app-side apply still owns the plan switch; collecting the renewal charge via Kelviq must be confirmed with Kelviq (see GAP).
5. Subscriptions with `cancel_at_period_end` do **not** receive a scheduled plan apply — the schedule is cleared and cancellation wins.

### Webhooks

- Upgrades: keep relying on `invoice.paid` / `invoice.payment_succeeded` (normalized) to settle the provisional incomplete subscription.
- Scheduled downgrades: no Kelviq call at schedule time; at period end the app applies the change. If Kelviq later emits `subscription.plan_changed` / renewal invoices for the same period, reconcile must stay idempotent and must not double-extend the period (treat as attestation only until mapping is confirmed).

### GAP — Kelviq native proration / subscription-update (unverified)

Kelviq’s native proration, mid-cycle subscription-update, and scheduled-plan APIs are **unverified** against current public docs used by this repo. Do **not** assume Kelviq will mirror our day-based credit or scheduled downgrade semantics. Before live traffic:

1. Confirm with Kelviq whether subscription update / proration endpoints exist and how they price mid-cycle changes.
2. Confirm how renewal invoices are created for plan changes applied at period end.
3. Confirm webhook payload shape for `subscription.plan_changed` vs app-owned scheduled applies.
4. Until confirmed, production must keep app-owned proration + one-off checkout for `total_due`, and app-owned scheduled downgrades.

## TODO markers in code

Search for `TODO(kelviq):` — items left open where public docs were incomplete.
