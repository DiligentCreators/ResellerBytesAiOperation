# Engineering progress — release readiness

Last reviewed: 2026-11-12

## Verified repository baseline

- Repository: `DiligentCreators/ResellerBytesAiOperation`
- Main baseline: `757e2bf75907e1d6d970c921faadee24c7445b47`
- Review branch: `work/release-readiness-audit`
- The foundation provides a read-only WHMCS diagnostics addon, signed API ingestion, queued AI analysis, dashboard, and versioned addon package.
- The application targets Laravel 13 / PHP 8.5 with PostgreSQL and Redis; the WHMCS addon targets PHP 8.1.
- The addon is manual-first and read-only. It must not alter WHMCS business records, call provisioning/registrar APIs, or change existing 20i and ResellerClub modules.
- No deployment or live WHMCS installation change has been performed.

## CI evidence

- GitHub Actions run [43207784623](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43207784623) completed successfully for an earlier commit; both `whmcs-addon-php81` and `ci` passed.
- Main-branch run [43126184415](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43126184415) also completed successfully for its commit.
- Latest reviewed run [43228161983](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43228161983) is **not green**: `whmcs-addon-php81` passed, while `ci` failed at Pint's `fully_qualified_strict_types` formatting check for `whmcs-addon/tests/signed_api_client_test.php`.
- The frontend build and earlier formatting/lint steps completed in that failed run; the workflow stopped at the Pint test before the remaining test suite could establish a pass.
- The strict-types formatting issue was still present in the PR merge-check run [43230275243](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43230275243): `whmcs-addon-php81` passed, while `ci` failed at the same Pint rule in `whmcs-addon/tests/signed_api_client_test.php`. The test now removes redundant leading backslashes from global `RuntimeException` references. This formatting correction is pending verification; no new CI has been intentionally triggered.
- These results apply only to the commits and checks identified above; they do not certify production readiness.

## Source review observations

- HMAC request signing uses a fixed HTTPS endpoint, a canonical method/path/timestamp/nonce/body-digest message, a random nonce, TLS peer/host verification, disabled redirects, and a bounded response body.
- Server middleware validates timestamp skew, signature, integration status, request size, query-string absence, and one-time nonce use.
- The addon credential loader requires an absolute path outside the WHMCS/document roots, rejects symlinks, enforces exact mode `0600`, bounds file size, and validates the JSON structure.
- The collector uses an allowlist of technical signals and does not transmit the configured system URL or customer/billing records.
- The AI analyzer treats the snapshot as untrusted evidence, requests strict structured output, validates finding fields, and rejects evidence paths that do not resolve to observed snapshot values.
- The nonce-pruning command deletes expired request nonces in bounded batches. No diagnostic-run, finding, recommendation, or audit-event retention/deletion policy or scheduled pruning mechanism was found.
- Diagnostic runs are configured to cascade-delete with their parent integration. This destructive lifecycle behavior needs explicit product/operator confirmation and test coverage before exposing integration deletion as a routine operation.
- No confirmed production-blocking defect was established in this focused source pass. The review is not a substitute for a full independent penetration test.

## Work completed on this branch

- Expanded the standalone signed API client test to exercise HTTP 500 responses, malformed JSON, empty responses, and cURL transport failures in addition to successful signing, TLS/redirect settings, and oversized response handling.
- Corrected the Pint strict-types formatting failure in the signed API client test. This correction is pending CI verification.
- Pull request: [#2 — release-readiness review](https://github.com/DiligentCreators/ResellerBytesAiOperation/pull/2).

## Remaining release gates

1. Complete a batched source review of authentication/authorization, audit event integrity, queue failure recovery, AI evidence handling, and package contents/checksum generation.
2. Define and approve data-retention periods for diagnostic snapshots/findings/recommendations and audit events. Do not enable destructive automated pruning until an operator-approved policy exists; implement dry-run, bounded batches, opt-in configuration, and tests as part of that approved change.
3. Review and test the integration deletion lifecycle, including the cascade effect on diagnostic history.
4. Perform static/local checks on the complete change batch, then run one consolidated CI workflow and resolve any failures before another run.
5. Confirm production infrastructure settings: PHP extensions, PostgreSQL/Redis connectivity, queue worker, scheduler, SMTP, operator MFA/passkey, and spend limits.
6. Require an operator-approved restorable backup, maintenance window, rollback owner, exact addon ZIP/checksum review, external credentials file with mode `0600`, and one-shot read-only WHMCS pilot before any deployment.

## Status vocabulary

- **Verified** means directly confirmed from repository or execution evidence.
- **Pending** means not yet independently verified.
- **Blocked** means a required safety precondition or external operator action is outstanding.

This document records review evidence; it does not certify the application as production-ready.
