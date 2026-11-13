# Independent security review checklist

Status: preparation checklist; independent review not yet performed
Applies to: ResellerBytes AI Operations release-readiness PR #2
Review target: exact reviewed PR head SHA, not a moving branch name

This checklist prepares evidence for an independent application and infrastructure security review. A checked implementation item is not a production sign-off. Reviewers must record the exact commit, test evidence, deployment assumptions, and unresolved findings.

## 1. Scope and trust boundaries
- [ ] Record the exact PR head SHA and verify all required CI jobs are green on that SHA.
- [ ] Review the Laravel application, API ingestion boundary, queue workers, PostgreSQL roles, Redis, addon package builder, and WHMCS PHP 8.1 addon.
- [ ] Treat the Laravel VPS, WHMCS hosting account, PostgreSQL administrator, cloud Object Lock account, OpenAI project, and operator endpoints as separate trust boundaries.
- [ ] Confirm the addon remains read-only and does not write WHMCS business records, call provisioning/registrar APIs, execute shell commands, collect customer/billing data, or contact unapproved destinations.
- [ ] Confirm hybrid SaaS tenant isolation, tenant billing, entitlements, and white-label branding are not represented as delivered.

## 2. Authentication and authorization
- [ ] Verify public self-registration is unavailable and operator accounts use controlled provisioning.
- [ ] Verify operational routes require authenticated, email-verified operators with confirmed TOTP.
- [ ] Verify passkeys are supplementary and cannot bypass confirmed-TOTP middleware.
- [ ] Test unauthenticated, unverified, TOTP-unconfirmed, expired-session, and revoked-session access to dashboard, integrations, diagnostics, findings, addon setup, download, and fingerprint routes.
- [ ] Test object-level authorization and IDOR across integration, diagnostic run, finding, and download identifiers.
- [ ] Test the last-operator deletion guard under concurrent deletion attempts and verify successful deletion is audited.
- [ ] Review password reset, password confirmation, session rotation, cookie flags, CSRF, rate limiting, and TOTP replay protection.
- [ ] Verify two-person no-charge reconciliation rejects same-operator approval, stale/replayed OTPs, missing MFA, invalid evidence, repeated approvals, and cancellation.

## 3. WHMCS ingestion and addon boundary
- [ ] Independently review HMAC canonicalization: HTTP method, exact path, timestamp, nonce, and raw-body digest.
- [ ] Test timestamp skew, nonce replay, duplicate snapshot UUID, invalid signature, invalid integration status, oversized body, query-string rejection, and malformed JSON.
- [ ] Verify TLS peer/host validation, redirect refusal, response-size bounds, and fixed destination allowlisting in the addon client.
- [ ] Verify credentials JSON is outside WHMCS and the document root, is not a symlink, has exact mode 0600, and is readable only by the WHMCS PHP runtime identity.
- [ ] Inspect collector allowlists and redaction against the actual payload; prove customer, invoice, payment, domain, credential, API-token, and raw configuration values are excluded.
- [ ] Verify integration revocation invalidates the previous secret and increments its version.
- [ ] Verify hard DELETE is rejected by model and database trigger, including query-builder and multi-row attempts on supported engines.
- [ ] Verify package manifest covers every addon source file, signature verification rejects altered bytes, and the authenticated fingerprint is compared before installation.
- [ ] Confirm package signing keys are not embedded in the repository, ZIP, WHMCS, or application database.

## 4. AI spend and job safety
- [ ] Verify global USD 5 daily and USD 50 monthly caps apply before provider submission across integrations and operators.
- [ ] Review integer micro-USD arithmetic, price-card version/expiry, input byte ceiling, output token ceiling, and conservative reservation sizing.
- [ ] Verify daily/monthly row locks serialize concurrent reservations and no over-budget provider call is made.
- [ ] Verify settlement is idempotent and persists provider response IDs and usage before releasing unused reservation.
- [ ] Verify pre-submit failures may release reservations, while post-submit timeout/crash/unknown outcomes remain held and block another billable attempt.
- [ ] Confirm automatic billable retries are disabled.
- [ ] Verify no-charge reconciliation requires independent provider evidence, two distinct verified TOTP operators, explicit approvals, row locks, and atomic audit events.
- [ ] Confirm confirmed-charge settlement is not implemented and ambiguous outcomes are never silently released or guessed.
- [ ] Verify AI prompts treat snapshots as untrusted input and findings are constrained to observed evidence paths.

## 5. Audit integrity and database privileges
- [ ] Review all application audit-write call sites and confirm they use the central transactional AuditEventWriter.
- [ ] Verify append-only UPDATE/DELETE triggers on SQLite, PostgreSQL, MySQL 8.4, MariaDB 11.4, and SQL Server 2022; PostgreSQL TRUNCATE rejection is separately tested.
- [ ] Verify SHA-256 chain canonicalization, genesis link, deterministic backfill, unique contiguous sequence, transactional head advancement, and multi-process append test.
- [ ] Verify the integrity command is non-mutating and serializes with appends by locking the chain head while streaming.
- [ ] Verify Ed25519 checkpoint tooling rejects signature tampering, environment mismatch, predecessor mismatch, local-prefix mismatch, and key reuse.
- [ ] Confirm checkpoint tooling is local only: external bucket upload, Object Lock, remote read-back, independent verifier scheduling, and alert delivery are not implemented.
- [ ] Confirm production migration/owner, runtime, and backup/restore database identities are separate.
- [ ] Test runtime-role negative privileges against a disposable restored database: audit UPDATE/DELETE/TRUNCATE, integration DELETE, schema DDL, trigger administration, and migration-ledger access must fail where specified.
- [ ] Confirm runtime can still append audit events, advance the chain head, ingest diagnostics, process queues, and settle budgets.
- [ ] Document owner/superuser bypass capability; do not call triggers or an unanchored hash chain tamper-proof.

## 6. Retention, backups, and recovery
- [ ] Verify the 90-day diagnostic report uses UTC receipt time, includes the exact cutoff boundary, counts dependent findings/recommendations, and never deletes records.
- [ ] Confirm the report warns that legal holds are not evaluated.
- [ ] Confirm no diagnostic cleanup, audit expiry, integration deletion, or destructive pruning is scheduled or enabled.
- [ ] Confirm legal-hold authority, release workflow, retention exceptions, and evidence reference remain explicit operator/compliance decisions.
- [ ] Review nonce cleanup for bounded batches, idempotency, expiry boundary, and rerun behavior.
- [ ] Require isolated backup restore rehearsal and compare the restored chain head with the newest independent external checkpoint before production migration.
- [ ] Verify rollback uses forward corrective migrations and does not drop production tables.

## 7. Infrastructure and operational security
- [ ] Review environment examples for empty secret stubs and production-required values; verify no production secrets or real credentials are committed.
- [ ] Verify Redis is private, authenticated where appropriate, configured with noeviction/persistence/monitoring, and has correct worker timeout/retry-after ordering.
- [ ] Verify stale queued-run recovery is bounded, row-locked, idempotent, and preserves ambiguous provider reservations.
- [ ] Review logs/errors for secrets, OTPs, raw snapshots, provider payloads, and unnecessary personal data.
- [ ] Review dependency lockfiles, Composer/npm advisories, pinned GitHub Actions SHAs, artifact provenance, and least-privilege workflow permissions.
- [ ] Verify migrations use deployment credentials only and never expose them to web/queue workers.
- [ ] Confirm production SMTP, TLS, backups, alert routing, monitoring, and incident contacts before any pilot.

## 8. Mandatory no-go findings
Do not approve merge or deployment if any of the following remains true:
- Required CI is red, pending, or was run against a different code SHA.
- A user can reach operational data or addon actions without confirmed TOTP and authorization.
- WHMCS payloads contain customer, billing, credential, or raw configuration data.
- Package signature/fingerprint verification is missing or can be bypassed.
- AI budget reservations can be raced, duplicated, or released after an ambiguous provider outcome.
- Audit writes bypass the central writer, verification can race with append, or runtime credentials can mutate/delete audit history.
- Production runtime and migration-owner credentials are shared.
- Destructive retention can run without approved legal-hold policy and a verified restore.
- External anchoring or backup controls are claimed active without independent provisioning and read-back evidence.
- Any production migration, deployment, live WHMCS change, or pilot is attempted without explicit operator approval and rollback readiness.

## 9. Review record
- Reviewed PR head SHA:
- Reviewer / organization:
- Review date (UTC):
- Scope exclusions:
- Findings by severity (Critical / High / Medium / Low / Informational):
- Evidence links:
- Required remediation commits:
- Retest SHA and CI run:
- Final disposition: Not started / In progress / Changes required / Approved with conditions / Approved

A reviewer must not mark this checklist approved based solely on repository CI. Production identity, database grants, cloud Object Lock, backup restoration, external checkpoint read-back, and alert delivery require independent evidence from the actual deployment environment.
