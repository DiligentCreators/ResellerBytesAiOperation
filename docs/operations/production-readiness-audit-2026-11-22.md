# Production readiness audit — 2026-11-22

**Repository:** DiligentCreators/ResellerBytesAiOperation  
**Audit branch:** `work/production-readiness-audit-2026-11-22`  
**Baseline:** local `main` (37 commits ahead of `origin/main`, through QA-RB-43)  
**Scope:** repository engineering gates for the B1–B14 / S1–S3 / QA-RB ship, not live infrastructure sign-off

## Verdict

**Repository production-ready after the fixes in this audit branch**, subject to the existing owner-run gates in `production-pilot-checklist.md` (Redis/PostgreSQL roles, audit-anchor Wasabi account, mail, backups, exact-head CI green on the merge SHA).

This document does **not** authorize WHMCS live installation, payment-provider activation, or external tenant onboarding.

## Method

- Static analysis: PHPStan level 7 (`composer types:check` / `phpstan analyse`)
- Automated tests: Pest feature/unit suite; Redis infra test skips when Redis is unreachable
- Frontend gates: `npm run check`, `npm run types:check` (passed during local CI entry)
- Source review: audit-anchor S3 adapter, AI finding typing, registration/security env surface, composer Windows lint script
- Cross-check against `production-pilot-checklist.md` and deferred SaaS/billing gates

## Findings and disposition

| ID  | Severity   | Finding                                                                                                                                | Disposition                                                                                                                                                               |
| --- | ---------- | -------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A1  | Critical   | `S3AuditCheckpointObjectStore` leaked raw `JsonException` ("Syntax error") on non-JSON CLI output instead of a stable fail-closed code | **Fixed** — decode through `decodeJsonObject()` → `audit_anchor_storage_invalid_response`                                                                                 |
| A2  | High       | Fake AWS CLI shebang stubs failed on Windows; create/readback and oversized-download tests errored                                     | **Fixed** — `*.php` CLI binaries invoke via `PHP_BINARY`; portable `.php` stubs in tests                                                                                  |
| A3  | High       | PHPStan: AI finding payloads typed as `array<string, mixed>` broke `stabilizeFindingData` / offset access in `AnalyzeDiagnosticRun`    | **Fixed** — normalized validated finding shape in `WhmcsDiagnosticAnalyzer` + tightened PHPDoc                                                                            |
| A4  | Medium     | PHPStan: `FindingWorkflowService` assigned `CarbonImmutable` from `now()` into `Illuminate\Support\Carbon` properties                  | **Fixed** — use `Carbon::now()`                                                                                                                                           |
| A5  | Medium     | PHPStan: unnecessary nullsafe on `TenantSubscription::plan` entitlements fallback                                                      | **Fixed**                                                                                                                                                                 |
| A6  | Medium     | `RedisInfrastructureTest` errored when Redis was down (local), instead of skipping                                                     | **Fixed** — ping then `markTestSkipped`                                                                                                                                   |
| A7  | Medium     | `composer lint:check` `HEAD^{tree}` broke under PowerShell (`^` stripped)                                                              | **Fixed** — quoted `"HEAD^{tree}"`                                                                                                                                        |
| A8  | High (ops) | Public registration defaults open; production pilot requires it closed                                                                 | **Documented** — `.env.example` adds `SECURITY_REGISTRATION_OPEN` with production note; leave product default open for local SaaS flows; **must set false in production** |
| A9  | High (ops) | Owner-run infrastructure still pending (Wasabi Object Lock, PostgreSQL roles, mail, backup/restore, audit-anchor keys)                 | **Out of repo scope** — tracked in production-pilot checklist; not a code blocker                                                                                         |
| A10 | Info       | Local `main` was 37 commits ahead of `origin/main` with no open PR for the B/QA ship                                                   | **Addressed** by this PR                                                                                                                                                  |

## Local verification (this branch)

- PHPStan: clean after A3–A5
- `tests/Feature/S3AuditCheckpointObjectStoreTest.php`: pass (including new non-JSON fail-closed case)
- `tests/Feature/Infrastructure/RedisInfrastructureTest.php`: skip when Redis unreachable; pass when Redis is up
- Broader related Pest files re-run as part of the fix batch

## Production env must-haves (operator)

Before any live pilot, confirm at minimum:

1. `APP_ENV=production`, `APP_DEBUG=false`
2. `SECURITY_REGISTRATION_OPEN=false` (or Platform setting equivalent)
3. Redis for cache/session/queue; supervised workers; scheduler
4. Confirmed TOTP for every production operator; `OPERATIONS_OWNER_EMAIL` set
5. Dedicated audit-anchor bucket/credentials; never reuse backup bucket
6. Exact PR-head CI: all six workflow jobs green
7. No WHMCS addon install until `production-pilot-checklist.md` preconditions are checked

## Explicit non-goals for this release

- Payment provider live activation / public billing webhooks
- External reseller / white-label / custom domains
- Enabling `DIAGNOSTICS_RETENTION_CLEANUP_ENABLED`
- Treating documentation checklists as penetration-test sign-off
