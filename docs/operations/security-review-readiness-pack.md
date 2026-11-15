# Security-review readiness pack

Status: evidence pack prepared; independent security review not performed Review
target:
exact final PR #2 head SHA after the one-shot final CI run Repository:
DiligentCreators/ResellerBytesAiOperation Branch: work/release-readiness-audit
PR: #2
(must remain Draft until separately authorized)

## 1. Purpose and reviewer instructions

This pack is an evidence index and review plan, not a security certification,
penetration-test report, production approval, or authorization to merge/deploy.
The
independent reviewer must check out the exact immutable commit SHA recorded in
the
review record, reproduce the required tests, inspect the actual deployment
configuration
and cloud controls, and report findings by severity.

Do not approve based only on repository tests or documentation. Production
identity,
secret-manager access, database grants, Wasabi Object Lock, backup restoration,
independent audit checkpoint read-back, and alert delivery require evidence from
the
real isolated environment.

## 2. System and trust-boundary inventory

### Components and trust boundaries

- **Laravel operations application:** Authenticated operator access, diagnostic
  metadata, findings and recommendations. Review authentication, confirmed TOTP,
  authorization, IDOR, CSRF and session controls.
-  **WHMCS addon:** Read-only collector on a separate WHMCS host. Review the
  fixed
endpoint allowlist, TLS validation, HMAC signing, nonce replay defense and
strict
  payload minimization.
-  **PostgreSQL:** Operational data, audit events, retention and legal-hold
  metadata.
Review runtime/migration/read-only role separation, triggers, least privilege
and
  transaction boundaries.
- **Redis / Laravel queues:** Job dispatch and queue telemetry. Review private
networking, authentication, persistence, worker timeout ordering and queue-event
  metric minimization.
- **AI provider:** Diagnostic summaries and model usage. Review budget
reservation/settlement, ambiguous outcomes, input/output limits and the absence
of
  automatic billable retries.
-  **Audit chain:** Append-only event history. Review canonical hashing,
  contiguous
  sequence, append transactions and verification race safety.
-  **External audit anchor:** Signed checkpoints and immutable evidence. Review
  Ed25519
key custody, predecessor continuity, conditional create, exact-version read-back
and
  COMPLIANCE retention.
-  **Wasabi backup storage:** Encrypted PostgreSQL backup archives and
  manifests. Review
bucket versioning, Object Lock COMPLIANCE, retention days, encryption,
checksums,
  read-back and access policy.
-  **Alert destinations:** Operational metadata only. Review secret redaction,
  one
primary team route, critical WhatsApp/SMS acknowledgement and
retry/deduplication.
-  **Operator workstation / secret manager:** Production credentials and TOTP.
  Verify
that secrets never enter Git, shell history, tickets, logs, chat or build
artifacts.

## 3. Implemented controls to independently verify

### Identity and application access

-  Operational access requires authenticated, verified operators with confirmed
  TOTP.
  Passkeys are supplementary and must not bypass the TOTP gate.
-  Owner-only legal-hold placement/release requires the configured Owner
  identity and
  fresh TOTP.
-  Production restore requires Shahrukh's explicit incident-specific approval, a
  matching
  Owner email, approval reference, fresh TOTP, and interactive confirmation.
-  PostgreSQL design separates runtime, deploy-only migration, and read-only
  reporting
identities. Verify actual grants from the deployment, not only migration
scripts.
-  Verify tenant resource quotas for integrations, diagnostic snapshots, and
  member seats serialize on the active subscription row, deny exact-limit
  overages, fail closed on missing limits, and preserve idempotent duplicate
  snapshot delivery at the quota boundary. Active/invited/suspended members
  consume seats; pending invitation resend does not. Verify plan code, billing
  mode, currency, unit price, and entitlement snapshots are database-immutable
  after provisioning. Verify PostgreSQL/SQLite enforce a single current
  subscription snapshot per tenant.
-  Verify tenant invitations are queued only after membership commit, signed for
  seven days, bound to the intended user and pending membership, never
  auto-accepted on GET, and cannot be disclosed to another account. Verify
  pending resend does not create duplicate membership rows and revoked
  invitations are not delivered.

### WHMCS ingestion and AI spend

-  Review the addon HMAC canonicalization, timestamp skew, nonce
  uniqueness/replay
protection, fixed endpoint allowlist, TLS peer/host verification, response-size
  bounds, and redirect refusal.
-  Confirm the addon collects only the approved operational allowlist and
  excludes
customer, billing, payment, domain, credential, API-token, and raw configuration
data.
- Verify global USD 5/day and USD 50/month caps, integer micro-USD accounting,
  conservative reservation, idempotent settlement, and held ambiguous outcomes.
-  Verify no automatic billable retries and no silent release after provider
  submission
  uncertainty.

### Billing webhook, outbox, and recovery

-  The provider-neutral adapter registry is empty by default. No provider SDK,
  live credentials, public webhook route, checkout, or charge-initiation
  endpoint is activated.
-  Verify raw-body signature validation, five-minute timestamp tolerance, 256
  KiB request bound, provider/account identity match, and tenant-scoped
  resolution of provider invoice/subscription references. Adapters must not
  supply local tenant or record IDs.
-  Verify transactional outbox/inbox identity and payload immutability,
  provider-account binding, idempotent reconciliation, at-least-once outbox
  delivery, and consumer deduplication by stable outbox ID.
-  Dead-letter replay must require tenant `billing.manage`, explicit command confirmation,
  constrained reason code, and a maximum of three manual replays. Webhook replay
  additionally requires the original provider-account mapping to remain active
  and verified; that mapping is row-locked with the tenant and event during
  replay.
-  Verify replay state changes and audit evidence are atomic, and logs/reports
  exclude tenant/provider/event identifiers, signatures, payloads, and raw
  exception text.
-  The dedicated PostgreSQL billing-operations identity must be separately
  provisioned and verified as same-database, BYPASSRLS but
  non-superuser/non-owner, non-inheriting, and SELECT-only on exactly the
  provider-account, webhook-inbox, and outbox tables.
-  No payment provider is selected or activated. Final grace policy,
  merchant-account ownership, provider pricing, production credentials, and
  non-production RLS rehearsal remain release gates.

### Audit chain and checkpoint publication

- Audit events are append-only and retained indefinitely. Verify the central
  AuditEventWriter is used for every application audit append.
-  Verify sequence allocation, canonical SHA-256 hashing, transaction/locking,
  and
  non-mutating integrity verification.
-  Checkpoint signing uses Ed25519. Shahrukh is the sole initial signing
  operator;
private keys must remain in the approved secret manager and must not be
committed.
-  Provider-neutral checkpoint publishing, an AWS CLI-backed S3-compatible
  adapter,
manual publish command, and fake-store contract tests exist. No production
account,
bucket, Object Lock read-back, or independent verifier schedule is provisioned.
-  The checkpoint envelope/key verifier does not yet provide a versioned
  multi-key
rotation transition; do not rotate the production public key until that support
is
  reviewed and implemented.

### Retention and legal holds

-  Diagnostic records expire absolutely 365 days after UTC received_at. No
  exception is
  permitted, including legal holds.
-  A legal hold is Owner-authorized, TOTP-protected, and audited. It does not
  extend
diagnostic content retention. Active hold records are marked
expired_by_retention with
immutable audit evidence when the target diagnostic data reaches the 365-day
cutoff.
-  Audit events are retained indefinitely and are not targeted by diagnostic
  cleanup.
-  Cleanup is dry-run by default, disabled by feature flag, bounded, and not
  scheduled.
Any production enablement/execution requires separate approval after all release
  gates.

### Monitoring and notification

- Approved thresholds: error rate >2% over 5 minutes; API p95 >1.5 seconds; no
successful backup for 26 hours; disk 80% warning / 90% critical; queued jobs
older
  than 15 minutes or remain in-flight for more than 15 minutes.
-  Metric capture avoids request/response payloads and queue job payloads.
  Missing
metrics must remain unavailable/null, not fabricated. The backup-success marker
is
bounded to 4 KiB before JSON parsing; malformed or oversized markers remain
missing.
-  Alert scheduling is one-minute with overlap protection, deduplication and
  repeat
  suppression. It is disabled by default.
-  Standard routing requires owner email plus exactly one Slack or Discord
  destination.
  Critical alerts require configured WhatsApp and/or SMS bridges. A delivery is
successful only after provider acknowledgement (2xx for the provider-neutral
webhook
  bridge).
-  No production destination, credentials, delivery receipt, or live alert has
  been
  provisioned.

### Backup and recovery

-  PostgreSQL custom-format backups use a dedicated read-only dump role; restore
  uses the
  dedicated migration role.
-  Backup archives use authenticated libsodium secretstream encryption and
  SHA-256
  verification.
-  Wasabi S3-compatible upload fails closed unless HTTPS endpoint, credentials,
  bucket
versioning, Object Lock, conditional If-None-Match create, returned object
VersionIds,
  and an explicit positive retention period are supported/configured. Per-object
COMPLIANCE retention and SSE-S3 AES256 are requested and exact-version metadata
is
verified. The archive VersionId is pinned in the manifest and exact-version
read-back
  is checked.
-  The backup is read back and its ciphertext digest is checked before the
  success marker
  is atomically updated.
-  Verification-only restore checks manifest and archive metadata, the exact
  pinned
archive VersionId, lock mode/retain-until, checksums, authenticated decryption,
and
  pg_restore --list without changing a database.
-  Destructive restore requires explicit execution and confirmation, a fresh
  pre-restore
backup, and an immutable approval record. Production adds Owner approval and
fresh
  TOTP.
-  Single-PutObject uploads fail closed above 5 GB. Multipart Object Lock upload
  is not
  implemented.
-  Scope is PostgreSQL application database only. WHMCS/MariaDB, uploaded files,
  and
  external provider state are not covered by this backup implementation.
-  No actual Wasabi upload, backup, or isolated restore rehearsal has been
  performed.

## 4. Required reviewer test matrix

### Required negative and adversarial test matrix

-  **Authentication:** Unauthenticated, unverified, TOTP-unconfirmed, expired
  and revoked
  sessions. Evidence: HTTP/feature test results.
-  **Authorization:** Cross-tenant/object ID substitution, role escalation and
  direct
  route access. Evidence: IDOR/access-control test matrix.
-  **WHMCS HMAC:** Altered method/path/body, stale timestamp, nonce replay,
  duplicate
  UUID and malformed JSON. Evidence: signature and replay tests.
-  **Billing recovery:** Duplicate/changed provider events, tenant/account
  mismatch, concurrent provider-account deactivation during dead-letter replay,
  unauthorized replay, three-replay ceiling, audit rollback, stale outbox
  leases, and aggregate-report privacy. Evidence: billing feature tests,
  PostgreSQL RLS rehearsal, and independent provider contract tests.
-  **Data minimization:** Customer/billing/domain/payment/credential/raw-config
  fields in
  fixtures. Evidence: payload allowlist and redaction checks.
-  **AI budget:** Concurrent reservation race, daily/monthly cap, provider
  timeout after
submit and duplicate settlement. Evidence: DB concurrency and idempotency tests.
-  **Audit:** Concurrent append, tampered row, sequence gap,
  UPDATE/DELETE/TRUNCATE and
  chain verification race. Evidence: cross-engine tests and role probes.
-  **Legal hold:** Wrong Owner, missing MFA, stale TOTP, duplicate hold, expiry
  at the
365-day boundary and audit append failure. Evidence: LegalHoldTest and audit
events.
-  **Retention:** UTC inclusive cutoff, expired `received`/`queued`/`analyzing` states,
bounded batch, audit failure rollback and held target still expiring. Evidence:
  retention and queue-recovery tests.
-  **Checkpoint:** Signature tamper, environment/predecessor mismatch,
  stale/future
timestamp, duplicate key, missing version, checksum and retention mismatch, plus
rejection of checkpoint files larger than 64 KiB before unbounded parsing.
Evidence:
  independent verifier, local verifier and publisher tests.
-  **Alerts:** Missing route, invalid/non-HTTPS endpoint, timeout, non-2xx,
  duplicate
  alert, retry and critical routing. Evidence: adapter and state-service tests.
-  **Backups:** Wrong key, tampered/truncated archive, wrong manifest, missing
  Object
  Lock, size/hash mismatch, wrong target DB and failed read-back. Evidence:
  cipher/manager tests and provider rehearsal.
-  **Restore:** No --execute, no confirmation, production wrong Owner, missing
  approval
reference, failed TOTP and failed pre-restore backup. Evidence: restore command
tests
  and audit events.
-  **Infrastructure:** Redis exposure, secret leakage, worker timeout/retry
  ordering, TLS
downgrade and workflow permissions. Evidence: config review and live environment
  evidence.

## 5. Known limitations and explicit release blockers

1. Wasabi account creation/billing and bucket provisioning are pending.
2.  Backup Object Lock retention days are not approved. The backup code must
   remain
disabled until Shahrukh supplies the retention period; do not assume 365 days
for
   backups.
3.  Secret-manager access, backup encryption key, and dedicated production
   backup/restore
   credentials are pending.
4.  Isolated backup/restore rehearsal access is pending. RPO is 24 hours and RTO
   is 4
   hours; no rehearsal evidence exists yet.
5.  The PostgreSQL backup tool does not cover the separate WHMCS/MariaDB
   database,
   uploaded files, or external provider state.
6.  Backups above 5 GB fail closed until a multipart Object Lock adapter is
   implemented.
7.  Production alert destinations and provider-specific WhatsApp/SMS bridges are
   not
   provisioned or delivery-tested.
8.  External audit checkpoint production bucket, actual Object Lock
   configuration/read-
back rehearsal, independent verifier schedule, and production alerting are not
   provisioned. The S3-compatible adapter and manual publisher are implemented.
9.  Production Ed25519 key custody and key-rotation transition support remain
   pending.
10. AI provider pricing and production price-card approval remain pending.
11. Independent security review and final release authorization remain pending.
12.  Hybrid SaaS tenant isolation, tenant billing/entitlements, and white-label
    branding
    must not be represented as shipped in this release.

## 6. CI and release evidence handling

Intermediate commits are intentionally marked [skip ci] to conserve GitHub
Actions
minutes. Do not treat a previous green run as proof for a later commit. After
all
roadmap work and local/static validation are complete, the final commit must
omit [skip
ci] and the single final CI run must pass all six required jobs on that exact PR
head
SHA.

The last previously confirmed green run before subsequent hardening was run
#43598332919
on commit 703772cc8def8f45d394ef53fc80ca582c3dae68 (six jobs; general CI 203
passed, 3
skipped, 915 assertions). Four concurrent commits landed without [skip ci] after
the
cost rule and triggered extra runs:
-  Commit 555f92ef6b5c828562f3d4e19b84e63da48759a6, run #43599729637: five
  matrix jobs
  passed; general CI failed the formatting check for
  docs/operations/external-audit-anchor-monitoring-and-storage.md.
-  Commit a831220273d0de4387c9c84979761216ad72537e, run #43600418285: five
  matrix jobs
  passed; general CI failed 30 tests after a backup manager test changed
  database.default to an unreachable PostgreSQL host, causing cascading SQLite
  transaction failures.
-  Commit 591aeadbf5b3eecbdfa0b3dee012481397ef3a80, run #43600581151: five
  matrix jobs
passed; general CI failed with the same test-only database.default mutation and
  cascading SQLite transaction errors.
-  Commit 466be2ef87e0536dded23eb6acb802f4b27ab512, run #43600781118: five
  matrix jobs
passed; general CI failed formatting checks in the external audit-anchor
document,
release-readiness progress document, and security-review readiness pack. Those
three
  Markdown files have since been reflowed.

The current code changes add a pure restore-target identity check and remove the
test's
database-connection mutation. The current documentation formatting corrections
are also
included in the pending skip-ci hardening commit. These concurrent runs are not
proof of
a green current head. No CI run has been requested for the assistant-authored
skip-ci
commits. The final exact-head CI remains pending by design.

Local project tests have not been executed in the current assistant environment:
the
repository requires PHP 8.5, while the available PHP CLI is 8.4 and the
repository
checkout/dependencies are not mounted. Do not describe unexecuted tests as
passing.

## 7. Owner-controlled pending inputs

Keep these pending until Shahrukh supplies, provisions, or explicitly approves
them.
Do not request secrets in chat; use the approved secret-manager/deployment
process.

- Wasabi account/billing, production backup bucket, dedicated IAM credentials,
  and the explicitly approved backup Object Lock retention period.
- Production secret-manager access/policy, backup encryption key, and dedicated
  backup/restore credentials; no application runtime credential may inherit
  migration or restore privileges.
- Production PostgreSQL provisioning and evidence for separate runtime,
  migration-owner, and billing-operations roles, including the exact least-
  privilege grants and independent negative-privilege rehearsal.
- Production Ed25519 private-key custody, trusted public-key distribution,
  rotation-transition approval, external audit checkpoint bucket/Object Lock,
  cross-account access, exact-version read-back, and independent verifier
  schedule/alert routing.
- Isolated backup/restore rehearsal environment and access, including the
  separate WHMCS/MariaDB database and uploaded-file recovery scope; RPO is
  24 hours and RTO is 4 hours. No rehearsal evidence exists yet.
- AI provider pricing verification, approved production price card, and
  provider-account/credential activation. No live billable provider call is
  authorized by this checklist.
- Production SMTP account/configuration and approved secret delivery. SMTP is
  the first live transport, Postmark is reserved for launch, and Log remains
  the current default; never place mail credentials in chat or source control.
- Production alert delivery configuration and delivery test for the approved
  Shahrukh destination, including any separately approved WhatsApp/SMS bridge.
- PHP 8.5 runtime/dependency environment for exact-head local validation.
- Independent security review against the exact final PR head SHA and final
  release authorization.

These items do not authorize a merge, deployment, production migration, or
restore. Each remains a separate evidence/approval gate.
 
## 8. Reviewer disposition

- Exact reviewed PR head SHA:
- Exact final CI run URL and conclusion:
- Reviewer / organization:
- Review date (UTC):
- Scope exclusions:
- Critical findings:
- High findings:
- Medium findings:
- Low / informational findings:
- Evidence links:
- Remediation commit SHAs:
- Retest SHA and CI result:
-  Final disposition: Not started / In progress / Changes required / Approved
  with
  conditions / Approved

Approval does not authorize merge, deployment, production migration, or a
production
restore. Those actions require separate explicit authorization from Shahrukh.
