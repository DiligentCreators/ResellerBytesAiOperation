# ResellerBytes AI Operations Engineer

A read-only-first operations and diagnostics platform for the ResellerBytes WHMCS installation.

The application receives a narrowly allowlisted technical snapshot from a WHMCS addon, validates the signed request, stores the sanitized evidence, and can use the OpenAI Responses API to produce structured findings and human-reviewed recommendations.

## Safety boundary

The initial release is advisory and read-only.

- No WHMCS customer, invoice, transaction, payment, service, or domain records are collected.
- No WHMCS business APIs, provisioning APIs, registrar APIs, shell commands, or SQL writes are called by the addon.
- The existing 20i hosting provisioning module and ResellerClub registrar module remain untouched.
- The addon is manual-first. Do not configure recurring collection during the initial production pilot.
- AI recommendations never execute changes. Every operational action remains a human decision.
- Missing telemetry is reported as unknown, not healthy.
- WHMCS remains on its existing MariaDB database at 20i. The Laravel application uses a separate PostgreSQL database on Forge.

## Architecture

- Laravel 13, PHP 8.5, Inertia 3, React 19, TypeScript, Tailwind CSS 4.
- PostgreSQL is the Laravel application's system of record.
- Redis is used for sessions, cache, and queued diagnostic analysis.
- The WHMCS addon is compatible with the WHMCS web PHP 8.1 runtime.
- Addon-to-API authentication uses HMAC-SHA256, timestamp validation, one-time nonces, body hashing, and idempotent snapshot identifiers.
- Integration secrets are encrypted at rest and are created, rotated, and revoked through Artisan commands.
- AI analysis uses structured output, strict server-side validation, evidence-path verification, and a prompt version. OpenAI response storage is disabled for these requests.

## Repository areas

- app/ — API, dashboard controllers, jobs, models, and diagnostic services.
- database/migrations/ — PostgreSQL schema.
- routes/ — authenticated dashboard and signed ingestion API.
- resources/js/ — React dashboard and setup screens.
- whmcs-addon/ — PHP 8.1-compatible WHMCS addon source and standalone tests.
- docs/architecture/ — architecture and security contracts.
- docs/operations/ — production pilot checklist and deployment runbook.

## Local development

Requirements: PHP 8.5, Composer, Node 22, PostgreSQL, Redis, and the PHP extensions used by the application (including pdo_pgsql, redis, and zip).

1. Install dependencies: composer install
2. Copy .env.example to .env and configure PostgreSQL, Redis, and OpenAI settings.
3. Generate the application key: php artisan key:generate
4. Run migrations: php artisan migrate
5. Install frontend dependencies: npm ci
6. Start development services: composer dev

Create an operator account through the hidden password prompts:

    php artisan ops:user:create "Operations Admin" operator@example.com

Public registration is disabled. Do not seed a default production user.

## WHMCS integration lifecycle

Create a one-time integration secret:

    php artisan whmcs:integration:create "ResellerBytes Production WHMCS"

Rotate a secret:

    php artisan whmcs:integration:rotate INTEGRATION_UUID

Revoke an integration:

    php artisan whmcs:integration:revoke INTEGRATION_UUID

Secrets are displayed once only. Store them in an approved password manager and place the WHMCS credentials JSON file outside both the WHMCS installation root and public document root, with exact permissions 0600. WHMCS addon settings contain only the credentials file path.

## WHMCS addon package

After deployment, sign in to the dashboard and open **WHMCS Addon & Setup** to download the versioned ZIP package. The download is authenticated, marked no-store, and includes a SHA-256 manifest.

WHMCS manages addon activation in its Admin Area but does not provide a built-in ZIP uploader. 20i File Manager supports browser-based uploads without FTP. If the panel offers ZIP extraction, extract the package into modules/addons; otherwise extract it locally and upload the four PHP files through File Manager while preserving the resellerbytes_ai_ops/lib structure. Then activate the addon in WHMCS Admin.

## Verification

CI runs:

- PHP 8.5 application checks, PHPStan, Pint, TypeScript, frontend lint/format checks, and Pest tests.
- Redis 7 service integration tests.
- PHP 8.1 syntax and standalone tests for the WHMCS addon.

A green CI run does not replace the production-readiness review or controlled live WHMCS pilot.

## Operations documentation

- Initial architecture: docs/architecture/initial-architecture.md
- Production pilot checklist: docs/operations/production-pilot-checklist.md
- Deployment runbook: docs/operations/deployment-runbook.md
- WHMCS addon implementation notes: whmcs-addon/README.md

## Production status

Do not deploy until the feature branch has passed the full CI suite, security review, and controlled pilot preconditions. No deployment or live WHMCS installation is performed by this repository workflow.
