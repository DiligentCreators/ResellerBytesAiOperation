# Engineering progress — release readiness

Last reviewed: 2026-11-12

## Verified repository baseline

- Repository: `DiligentCreators/ResellerBytesAiOperation`
- Review branch: `work/release-readiness-audit`
- The foundation provides a read-only WHMCS diagnostics addon, signed API ingestion, queued AI analysis, dashboard, and versioned addon package.
- The application targets Laravel 13 / PHP 8.5 with PostgreSQL and Redis; the WHMCS addon targets PHP 8.1.
- The addon is manual-first and read-only. It must not alter WHMCS business records, call provisioning/registrar APIs, or change existing 20i and ResellerClub modules.
- No deployment or live WHMCS installation change has been performed.

## Current branch changes

- Added an Ed25519 package signer that validates the configured base64-encoded secret/public key pair and fails closed if Sodium is unavailable, keys are malformed, or the key pair does not match.
- The addon ZIP now contains a detached signature, public key, public-key SHA-256 fingerprint, version, and sorted SHA-256 manifest. The signature covers the version, all addon source-file checksums, and the installation guide checksum.
- The authenticated operations dashboard separately displays the active public-key fingerprint. The download response also exposes the fingerprint in a response header. Operators must compare the archive fingerprint with the dashboard value before installation.
- Added deployment configuration placeholders and instructions to generate the Ed25519 key pair once, store the private key only in a deployment secret manager, and keep the key stable for the release line.
- Added package-download feature coverage for successful signature verification, tampered signed payload rejection, missing keys, mismatched key pairs, authentication, email verification, and MFA gating.
- Updated the addon README and embedded installation guide with signature verification and installation safety instructions.
- These changes are committed but have not yet been runtime-tested or verified by a new CI run. No production signing key has been generated or configured in this repository.

## Previously completed implementation

- Eloquent-level append-only guard and feature tests for audit events. This is not a database-level immutability boundary.
- Mandatory confirmed-TOTP enforcement on operational routes, with the security enrollment page available before enrollment.
- Atomic manual reanalysis status transition and audit-event creation under a row lock.
- Bounded stale queued-run reconciliation scheduled every five minutes. This is not a transactional outbox and can still create duplicate queue entries if workers are delayed.
- Focused release-readiness review and evidence are retained in the PR history.

## Remaining release gates

1. Configure a production Ed25519 key pair in the deployment secret manager, publish/record the trusted public-key fingerprint through the approved release channel, and test the actual downloaded ZIP end-to-end.
2. Verify the package signature and checksums with the documented PHP verifier against an extracted archive; test altered files, altered manifest, wrong fingerprint, and invalid signatures.
3. Protect the last usable operator account from accidental deletion and validate the mandatory MFA enrollment/login flow in runtime tests.
4. Test bounded queue recovery against dispatch failure, process interruption, duplicate dispatch, delayed workers, and stale leases; assess whether a transactional outbox is justified.
5. Approve a stronger audit-integrity boundary: database-level controls and/or export to separately controlled append-only storage.
6. Define and approve data-retention periods for diagnostic snapshots/findings/recommendations and audit events. Do not enable destructive automated pruning until an operator-approved policy exists.
7. Review and test the integration deletion lifecycle, including cascade effects on diagnostic history.
8. Run static/local checks on the complete change batch, then one consolidated CI workflow and resolve any failures.
9. Confirm production infrastructure settings, backup/rollback plan, maintenance window, credentials handling, and a one-shot read-only WHMCS pilot before deployment.

## Status vocabulary

- **Verified** means directly confirmed from repository or execution evidence.
- **Pending** means not yet independently verified.
- **Blocked** means a required safety precondition or external operator action is outstanding.

This document records review evidence; it does not certify the application as production-ready.
