# ResellerBytes AI Operations — Initial Architecture

## Status and scope

This document defines the first implementation boundary for the ResellerBytes AI Operations Engineer.

The first release is **diagnostics-only and read-only**. It may collect approved technical health signals from the ResellerBytes WHMCS installation, persist sanitized snapshots, and produce evidence-linked findings. It must not execute commands on the WHMCS host or mutate WHMCS, hosting, registrar, customer, billing, or provisioning state.

Confirmed production context:

- WHMCS: 8.13.7 General Release (8.13.7-release.1)
- Hosting/provisioning: existing 20i module; preserve it unchanged
- Registrar: existing ResellerClub integration; preserve it unchanged
- WHMCS database: MariaDB 10.11.19; it remains on MariaDB
- WHMCS web PHP: 8.1.34
- WHMCS system cron PHP: 8.3.36
- Operations application database: separate PostgreSQL database
- WHMCS staging installation: unavailable; production testing therefore requires explicit operator approval and a controlled read-only procedure

The Laravel application must never connect directly to the WHMCS database.

## Existing application baseline

The repository is an existing Laravel 13.17+ / PHP 8.5+ application using Inertia 3, React 19, TypeScript, Tailwind CSS 4, Fortify, Wayfinder, Pest 5, and PostgreSQL support through Laravel's `pgsql` connection.

Keep the starter-kit authentication, 2FA/passkey support, UI primitives, and existing application conventions. Do not replace the starter kit or introduce another frontend/backend stack.

The repository's API route file is not present and API routing is not currently registered in `bootstrap/app.php`; the API route must be explicitly registered when ingestion is implemented.

## System boundaries

### WHMCS addon

The addon is a small WHMCS-local collector and outbound client. It:

- Runs only when explicitly invoked during the pilot; scheduling is disabled until separately verified.
- Collects a versioned, allowlisted diagnostic schema.
- Redacts secrets and customer-identifying data before serialization.
- Sends one-way HTTPS requests to the operations API.
- Signs each request using an installation-specific HMAC secret.
- Does not call WHMCS provisioning, registrar, billing, suspension, termination, or customer-management APIs.
- Does not write to WHMCS business tables or change WHMCS configuration.
- Does not read or transmit full configuration files, database credentials, API tokens, payment data, or unbounded logs.

A collector must report `unknown` when a signal cannot be observed reliably. Missing visibility must never be represented as a healthy state.

### Laravel operations API

The API:

- Accepts only versioned JSON snapshots from registered integrations.
- Authenticates each request using HMAC-SHA256 over the HTTP method, request path, timestamp, nonce, and SHA-256 hash of the exact raw request body.
- Enforces a short timestamp window and one-time nonce per integration.
- Enforces strict payload validation, request-size limits, rate limits, and idempotency.
- Stores only sanitized diagnostic payloads.
- Queues AI analysis separately from ingestion so ingestion still succeeds when the AI provider is unavailable.
- Never interprets diagnostic content as executable instructions.

### AI analysis

The model receives only the sanitized snapshot and approved system-level diagnostic rules. It must return schema-validated structured output containing:

- Finding category and severity
- Evidence references to fields in the stored snapshot
- Explanation and confidence
- Recommended manual next steps
- Risk level and explicit statement that no repair was performed

AI output is untrusted input. It is validated, stored, and displayed as advice only. No shell, SQL, WHMCS API, 20i API, ResellerClub API, or other repair tool is available to the model in release one.

## Initial data model

Use UUIDs for integration, run, finding, and recommendation identifiers. Use PostgreSQL JSONB for structured evidence and sanitized snapshots.

### `whmcs_integrations`

- UUID primary key
- Human-readable installation name
- Active/revoked status
- Encrypted HMAC secret (never serialized)
- Secret version / rotation metadata
- Addon version and last-seen timestamp
- Created/updated timestamps

The integration secret is generated server-side by the `whmcs:integration:create` Artisan command and shown only once to an authorized server operator. The `whmcs:integration:rotate` command immediately invalidates the previous secret and displays the replacement once; the addon must be updated immediately. The `whmcs:integration:revoke` command disables the integration. These lifecycle actions are recorded in the audit log. There is no public integration-registration endpoint and no dashboard secret-reveal endpoint.

### `diagnostic_runs`

- UUID primary key
- Integration foreign key
- WHMCS snapshot UUID (idempotency key)
- Schema version and addon version
- Collected-at and received-at timestamps
- Processing status
- Sanitized JSONB payload
- SHA-256 payload digest
- Failure code without secret-bearing exception text

Unique constraint: integration + snapshot UUID.

### `diagnostic_findings`

- UUID primary key
- Diagnostic run foreign key
- Stable fingerprint within a run
- Category, severity, title, summary
- JSONB evidence pointers and observed values
- Confidence
- Finding lifecycle status
- Timestamps

### `diagnostic_recommendations`

- UUID primary key
- Finding foreign key
- Recommendation and rationale
- Risk level
- `requires_manual_action` defaulting to true
- Timestamps

### `integration_request_nonces`

- Integration foreign key
- Nonce
- Expiry timestamp
- Unique constraint on integration + nonce

This durable uniqueness constraint prevents concurrent replay of a previously accepted signed request. Expired nonce rows may be pruned by a bounded scheduled task.

### `audit_events`

- Actor type and identifier
- Event type
- Subject type and identifier
- Sanitized JSONB metadata
- Optional request IP
- Occurred-at timestamp

Never store credentials, raw request signatures, full request bodies, or unredacted exceptions in audit metadata.

## Integration operator commands

Run these commands only from an authenticated server shell by an authorized operator:

```bash
php artisan whmcs:integration:create "ResellerBytes Production"
php artisan whmcs:integration:rotate <integration-uuid>
php artisan whmcs:integration:revoke <integration-uuid>
```

Create and rotate display a newly generated 256-bit secret once. Store it in a password manager and configure it in the WHMCS addon over a secure administrative process. Do not paste it into tickets, chat, source control, shell scripts, CI variables without an approved secret store, or logs. Rotation has no overlap/grace period: the old secret stops working immediately. Revocation is immediate and repeat-safe. No command ever displays an existing secret.

## API contract (first release)

- `POST /api/v1/whmcs/diagnostic-snapshots`

Required authentication headers:

- `X-RB-Integration`
- `X-RB-Timestamp`
- `X-RB-Nonce`
- `X-RB-Signature`

The signed canonical message is:

```text
UPPERCASE_METHOD
REQUEST_PATH
UNIX_TIMESTAMP
NONCE
LOWERCASE_HEX_SHA256_OF_RAW_BODY
```

The signature is lowercase hexadecimal HMAC-SHA256 using the integration's secret. Compare signatures with a constant-time comparison. Validate the raw body digest before JSON parsing. Reject stale timestamps, malformed headers, unknown/revoked integrations, invalid signatures, reused nonces, invalid schema versions, oversized bodies, and invalid payloads.

A repeated snapshot UUID for the same integration is idempotent only when the raw-body SHA-256 digest matches the original request; return the existing run without duplicating it. Reusing a snapshot UUID with a different payload is an idempotency conflict (HTTP 409). A reused nonce is rejected (HTTP 409).

The endpoint must not return the integration secret or any internal diagnostic configuration.

## Initial diagnostic payload allowlist

The schema may include:

- WHMCS version and addon/collector version
- Web PHP version and explicitly observable runtime capabilities
- Allowlisted PHP extension availability as booleans
- Cron last-run timestamp and observable completion state
- Installed module identifiers and enabled/disabled state, excluding credentials and settings
- Sanitized system-health warning identifiers and severity only; arbitrary warning text is not accepted
- Selected filesystem permission checks only where the addon can reliably observe them
- Collector capability/limitation declarations

Do not include:

- Customer names, email addresses, addresses, phone numbers, IP histories, or account records
- Invoice, transaction, card, bank, or payment information
- Database usernames/passwords, API tokens, license keys, session values, or secrets
- Full `configuration.php`, full module configuration, or environment files
- Unbounded or raw logs
- File contents unrelated to explicitly approved diagnostics

## Operational and safety requirements

1. No automatic repair or write capability in the first release.
2. No WHMCS DB connection from Laravel or the AI provider.
3. No change to the existing 20i or ResellerClub modules.
4. No production addon installation without an operator-approved backup and rollback plan.
5. First production pilot is manual, one-shot, read-only, and observable.
6. Do not change PHP versions, cron commands, file permissions, OPcache, WHMCS paths, or module-debugging settings based solely on an AI recommendation.
7. Do not claim that a check is healthy when the addon cannot observe it.
8. Ingestion must remain functional if the AI provider is unavailable.
9. Secrets must be encrypted at rest, excluded from serialization/logging, revocable, and rotatable.
10. All accepted snapshots and recommendations must be traceable to an integration and source run.

## Delivery sequence

1. **Foundation:** correct PostgreSQL example configuration; add integration, snapshot, nonce, finding, recommendation, and audit schema with models and focused tests.
2. **Secure ingestion:** register API routing; implement HMAC authentication, replay protection, strict validation, idempotency, rate limits, and feature tests.
3. **Integration provisioning:** authorized operator workflow/Artisan command to create, revoke, and rotate an integration secret; reveal a newly generated secret once only.
4. **WHMCS addon:** build the versioned read-only collector and signed outbound client; static review and compatibility checks before any production pilot.
5. **Analysis:** queue-based, provider-abstracted AI analysis with strict structured-output validation and evidence references.
6. **Dashboard:** integration status, diagnostic history, findings, evidence, recommendations, and audit history.
7. **Release gate:** automated checks, security review, manual production backup/approval, one-shot live pilot, then operator review before scheduling.

## Explicitly out of scope for release one

- Automated fixes or remote command execution
- WHMCS database access from the Laravel app
- Hosting suspension/unsuspension, termination, provisioning, upgrades, or password changes
- Registrar operations or domain registration/renewal/transfer
- Billing, invoicing, payment, refund, or customer account mutations
- Automatic PHP, cron, filesystem permission, or server configuration changes
- Multi-customer SaaS tenancy
