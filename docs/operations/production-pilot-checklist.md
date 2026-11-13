# Production pilot safety checklist

This checklist is mandatory before installing or running the WHMCS addon against the live ResellerBytes installation.

## Laravel application Redis preflight

- [ ] PHP 8.5 CLI and FPM both have Redis and ZIP extensions enabled. Redis is required for FPM sessions/cache and CLI queues; ZIP is required for the authenticated addon download route.
- [ ] Redis is reachable locally (`redis-cli ping` returns `PONG`) and is not publicly exposed.
- [ ] Redis authentication, memory limit, `noeviction` policy, persistence, and monitoring are configured and reviewed.
- [ ] Laravel `.env` uses `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, and `QUEUE_CONNECTION=redis` with distinct cache/default logical database indexes.
- [ ] A supervised queue worker is configured with a timeout shorter than Redis `retry_after`.
- [ ] Redis restart/recovery behavior has been tested before relying on it for sessions and background jobs.
- [ ] Forge's Laravel scheduler is active (`schedule:run` every minute), so expired HMAC nonces are pruned every 15 minutes.

## Application access and AI analysis

- [ ] Public self-registration is disabled; no public account-creation route is available.
- [ ] The first verified dashboard operator was created through php artisan ops:user:create "Operations Admin" operator@example.com; the password was entered through hidden terminal prompts.
- [ ] Two-factor authentication or a passkey is enabled for every production operator.
- [ ] OPENAI_API_KEY is configured only in the Forge environment, never in source control or WHMCS.
- [ ] The configured OpenAI model is available to the API project and the project has an appropriate spend limit.
- [ ] The Redis queue worker is running and supervised; the scheduler is active.
- [ ] A test snapshot has completed AI analysis, its evidence paths match the stored sanitized payload, and recommendations are clearly marked manual-only.
- [ ] A provider failure leaves the original diagnostic snapshot intact and records only a sanitized error code.
- [ ] The dashboard shows no findings as not yet analyzed until analysis has completed; an empty findings list is not treated as proof of health.

## Preconditions

- [ ] The Laravel application and ingestion API have passed automated tests and security review.
- [ ] The exact addon release and SHA-256 checksum have been reviewed.
- [ ] The addon has been statically inspected; no shell execution, SQL writes, WHMCS business API calls, or outbound destinations other than the configured operations API are present.
- [ ] A current, restorable backup of the WHMCS files and database has been confirmed by the operator.
- [ ] A maintenance window and rollback owner have been identified.
- [ ] The integration was created using `php artisan whmcs:integration:create` from an authorized server shell.
- [ ] The one-time integration secret is stored only in the external credentials JSON file; WHMCS addon settings contain only that file's path.
- [ ] The HTTPS certificate and destination hostname have been independently verified.
- [ ] The collector schema and redaction rules have been reviewed against the exact payload that will be transmitted.
- [ ] The WHMCS addon passed its PHP 8.1 syntax and credential-file tests.
- [ ] The HMAC credentials JSON file is outside both WHMCS and the document root, has mode `0600`, and is readable by the WHMCS PHP runtime user.
- [ ] WHMCS addon settings contain only the credentials-file path; the HMAC secret is not stored in `tbladdonmodules`.
- [ ] The addon has no enabled cron task or hook, and its only outbound destination is the verified HTTPS operations API.

## First-run restrictions

- [ ] Install only the reviewed addon version.
- [ ] Do not enable a cron or scheduled run.
- [ ] Run the collector manually once.
- [ ] Confirm the addon makes only the signed HTTPS POST to the operations API.
- [ ] Confirm the snapshot is accepted and visible in the operations dashboard.
- [ ] Confirm no customer, invoice, transaction, credential, API token, or raw configuration data is present in the payload.
- [ ] Confirm the existing 20i hosting module and ResellerClub registrar module remain unchanged.
- [ ] Review all findings with an operator before considering any manual remediation.

## Immediate rollback triggers

Stop the pilot and remove/disable the addon if any of the following occurs:

- Unexpected WHMCS database writes or business-state changes
- Unexpected outbound network requests
- Any customer, billing, domain, hosting, or payment data is collected
- PHP errors, elevated resource use, slow requests, or cron degradation attributable to the addon
- Invalid signatures, unexplained retries, duplicate snapshot corruption, or unexpected API responses
- Any discrepancy between reviewed addon code and the installed package

After stopping, preserve relevant sanitized logs, revoke the integration secret, remove the addon using the reviewed rollback procedure, and verify normal WHMCS operation.

## Not permitted during the pilot

Do not change PHP versions, cron commands, file permissions, OPcache, WHMCS directory paths, module-debugging settings, 20i settings, ResellerClub settings, billing, customer accounts, domains, or hosting services based on an AI recommendation.

The first pilot validates collection and transport only. It does not authorize repairs.

## Audit, database, and retention release gates

- [ ] The exact PR head SHA has all required CI jobs green, including PostgreSQL concurrency, MySQL retention-trigger, general CI, and WHMCS PHP 8.1 compatibility checks.
- [ ] PostgreSQL migration and runtime principals are provisioned separately; runtime is not a table owner, schema creator, migration-ledger reader, or audit-event mutator.
- [ ] Runtime-role negative privilege tests prove audit UPDATE/DELETE/TRUNCATE and integration hard DELETE are rejected; normal audit append and chain verification still work.
- [ ] The WHMCS integration retention trigger has passed PostgreSQL and MySQL/MariaDB integration tests. SQL Server support remains unverified until its dedicated CI coverage passes.
- [ ] The report-only diagnostic retention command `php artisan diagnostics:retention:report --days=90` has been run against a restored non-production copy and its candidate/dependent counts reviewed. It does not delete records and does not evaluate legal holds.
- [ ] Legal-hold authority, release approval, evidence reference, and retention exceptions are approved before any automated diagnostic cleanup is implemented.
- [ ] The external audit anchor is provisioned in a separate security account with immutable retention, independent Ed25519 signing key, read-only verifier, alert routing, and a successful restore/read-back drill.
- [ ] The latest database head matches the newest external signed checkpoint; any unanchored tail and checkpoint age are documented.
- [ ] A database backup has been restored in isolation and compared against the newest external checkpoint before any production migration or pilot.
- [ ] The approved audit retention period and diagnostic retention exception policy are documented by the responsible compliance owner.

**Shahrukh ke liye before pilot:** approve the legal-hold workflow and retention exceptions; approve the external Object Lock retention period; supply the actual Forge database/schema and secret-manager role naming for the PostgreSQL role setup; and arrange the separate cloud account/key custody for external audit anchoring. Do not share private keys or production database passwords in this repository or chat.
