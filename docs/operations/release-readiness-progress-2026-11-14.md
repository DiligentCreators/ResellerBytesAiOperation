# Release-readiness progress — 2026-11-14

## Live branch verification

- Repository: `DiligentCreators/ResellerBytesAiOperation`
- Pull request: [#2 — Release readiness](https://github.com/DiligentCreators/ResellerBytesAiOperation/pull/2)
- Branch: `work/release-readiness-audit`
- Latest reviewed head at the time of this note: `b1ec1d39fa54ea15e4e29a77984014b9d25a1384`
- PR remains open and Draft. No merge, production migration, deployment, or live WHMCS change has been performed.

## Previously verified CI baseline

GitHub Actions run [43506527149](https://github.com/DiligentCreators/ResellerBytesAiOperation/actions/runs/43506527149) passed on the earlier PR head `4d461b08a1a3f95890acbf078e88a394dd36d674`. All four jobs were individually confirmed successful:

- General CI
- PostgreSQL AI-budget and audit-chain concurrency
- MySQL 8.4 integration-retention trigger
- WHMCS addon PHP 8.1 compatibility

This run does not verify the later SQL Server/MariaDB additions in this branch.

## Changes added after that baseline

- Added an isolated SQL Server 2022 integration script that creates a minimal test table, invokes the production WHMCS integration-retention migration, verifies query-builder DELETE rejection and row preservation, then removes its test trigger/table.
- Added a SQL Server 2022 GitHub Actions service job using the Microsoft SQL Server PHP drivers.
- Added a MariaDB 11.4 GitHub Actions service job and expanded the existing MySQL retention test so it also accepts the MariaDB PDO driver.
- Exposed explicit SQL Server TLS encryption and trust-server-certificate configuration through environment settings; CI enables encryption and trusts only the ephemeral self-signed service certificate.
- Updated the retention policy and production-pilot checklist to identify MySQL 8.4, MariaDB 11.4, and SQL Server 2022 engine-specific CI gates.
- Retention reporting now calculates its threshold from an explicit UTC clock and has an inclusive-cutoff boundary test. It remains report-only. No diagnostic, finding, recommendation, integration, or audit data is deleted by these changes.

## Verification status of this change batch

- Latest branch head: `229ac92c8121bf867016bb2037d841537592b80e`.
- The earlier four-job green run is not evidence for this later head.
- The new SQL Server and MariaDB jobs have been added but their results must be checked on the exact latest PR SHA before claiming engine verification. The latest PR SHA currently has no associated workflow run returned by GitHub; CI execution is still pending.
- SQL Server coverage is a focused trigger integration check, not a full Laravel migration-suite compatibility claim for SQL Server.
- No production credentials or external cloud account are required for these repository changes.

## Remaining work

1. Verify all six CI jobs on the exact latest branch SHA; fix any failures and rerun until green.
2. Continue release checklist and security review preparation without enabling destructive retention.
3. Keep these items in the operator decision queue: Object Lock/cloud account, 365-day retention approval, signing keys and production DB roles, legal-hold policy, and backup/restore rehearsal access.
4. Keep PR #2 Draft. Do not merge, deploy, or run production migrations.
