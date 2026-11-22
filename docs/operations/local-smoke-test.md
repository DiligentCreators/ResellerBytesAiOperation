# Local smoke test (sample WHMCS snapshots)

Use this only on a local/non-production environment. The sample commands refuse to run when `APP_ENV=production`.

## Prerequisites

- App served locally (e.g. Laravel Herd).
- Database migrated and demo accounts available.

## Commands

```bash
php artisan migrate
php artisan db:seed --class=DemoAccountsSeeder
php artisan whmcs:sample-integration
php artisan whmcs:sample-snapshot --heartbeat
php artisan whmcs:heartbeats:check
```

Optional:

```bash
# Explicit tenant / integration UUIDs
php artisan whmcs:sample-integration {tenant-uuid}
php artisan whmcs:sample-snapshot {integration-uuid} --fixture=schema_1_1_positive --heartbeat

# Simulate a missed heartbeat, then open heartbeat.missed
php artisan whmcs:sample-snapshot --heartbeat --stale
php artisan whmcs:heartbeats:check

# Local/testing QA accounts (staff + starter/growth/pro owners). Writes credentials
# JSON to --output only; never prints passwords, TOTP secrets, or recovery codes.
# Requires plan monthly prices already set in the platform plan editor / DB.
php artisan qa:provision-accounts --output=/absolute/path/qa-accounts.json

# Send reports to one tenant immediately (force bypasses schedule/due + idempotency;
# value report still requires the reports.value entitlement).
php artisan findings:send-weekly-digest --tenant={tenant-uuid} --force
php artisan reports:send-value-report --tenant={tenant-uuid} --force
```

`whmcs:sample-integration` creates or reuses a **Local demo WHMCS** integration for the first (or given) active tenant. Credentials are generated and stored encrypted; they are never printed.

`whmcs:sample-snapshot` loads a fixture from `tests/Fixtures/snapshots/`, signs it like the WHMCS addon, and dispatches it through the real HTTP kernel path (routing, middleware, validation, ingest, deterministic rules, continuity).

`qa:provision-accounts` refuses outside `local`/`testing`. Re-running rotates passwords and 2FA secrets for the QA emails only and refreshes plan entitlement snapshots on their subscriptions.

## Where to click in the UI

Sign in with a local demo account (password from `DEMO_ACCOUNTS_PASSWORD` / `operations.demo_accounts.password` — not stored in this doc).

- **Home** — installation health overview after ingest
- **Fix Center** — open rule findings and guided/one-click fix flows
- **Reports** — trends and value reporting surfaces
- **Installations** — WHMCS integration list, heartbeat state, auto-collect
- **Settings → Alerts** — tenant alert preferences
- **Settings → Auto-fix** — auto-fix policy toggles
- **Platform settings** — platform operator configuration (super-admin)

## Notes

- Fixtures live under `tests/Fixtures/snapshots/` (default: `schema_1_1_positive`).
- Do not run these commands against production data.
- Never commit secrets, passwords, or integration credentials into the repo or this document.
