# ResellerBytes AI Operations — WHMCS Addon

This addon is the WHMCS-side, read-only diagnostic collector for WHMCS 8.13.7 and PHP 8.1+. It is intentionally manual-only in the first pilot.

## Safety boundary

The addon:

- Collects a small allowlist of technical runtime signals.
- Reads the WHMCS Version and SystemURL settings through the WHMCS settings model, and executes one read-only SELECT VERSION() query to identify the database server version.
- Checks whether configuration.php is writable; it never reads the file contents.
- Sends one signed HTTPS POST to https://ops.resellerbytes.com/api/v1/whmcs/diagnostic-snapshots.
- Does not query customer, invoice, transaction, payment, hosting, domain, or registrar records.
- Issues no SQL writes, creates no custom WHMCS tables, and does not modify business records. WHMCS stores the non-secret credentials-file path in its standard addon settings when an administrator configures the module.
- Does not execute shell commands or call WHMCS business APIs.
- Does not install a hook or register a cron task.
- Does not change the existing 20i provisioning or ResellerClub registrar modules.

The collector explicitly reports what it cannot observe, including the system-cron PHP runtime, module status, hosting-panel configuration, and certificate verification. Missing visibility is not reported as healthy.

## Credentials storage

Do not put the HMAC secret in WHMCS addon configuration fields. WHMCS 8.13's addon settings model does not document encrypted-at-rest storage for addon values.

Create a JSON credentials file outside both the WHMCS root and the web document root. The file must be readable by the PHP runtime user and have permissions 0600.

Example file contents:

    {
      "integration_id": "YOUR-INTEGRATION-UUID",
      "secret": "YOUR-64-CHARACTER-HEX-SECRET"
    }

The addon configuration stores only the absolute path to this file. It validates that:

- The resolved file exists and is readable.
- The configured path and resolved file are outside the WHMCS root and document root, including symlink-path checks.
- Both root paths must resolve; otherwise the addon fails closed.
- The file has exactly mode 0600.
- The JSON contains exactly the integration UUID and 256-bit secret.

Never commit the credentials file or paste its contents into tickets, chat, or logs.

## Installation procedure

Do not install this addon on production until the Laravel API is deployed, the integration secret has been created, and the production-pilot checklist has been approved.

1. Take and verify a restorable WHMCS files/database backup.
2. WHMCS does not provide a built-in ZIP addon-module uploader. Upload and extract the supplied ZIP into /modules/addons using the 20i hosting File Manager (no FTP is required when File Manager upload/extract is available). The resulting path must be /modules/addons/resellerbytes_ai_ops/resellerbytes_ai_ops.php.
3. Create the credentials JSON file outside the web root and set its permissions to 0600.
4. In WHMCS, activate ResellerBytes AI Operations under Addon Modules.
5. Configure the absolute credentials-file path.
6. Grant addon access only to trusted administrator roles.
7. Open the addon page and run Run diagnostics once manually.
8. Confirm the accepted snapshot and inspect its exact payload in the operations application.

Do not configure a cron or recurring run during the first pilot. A successful manual run is required before any separate scheduling decision.

## Rollback

1. Stop using the addon page.
2. Revoke the integration from the Laravel application.
3. Deactivate the addon in WHMCS.
4. Remove the addon directory after preserving any required sanitized evidence.
5. Delete the external credentials file using the approved server process.
6. Verify normal WHMCS operation and confirm the existing 20i and ResellerClub modules remain unchanged.

Deactivation does not delete or modify the external credentials file.

## Automated checks

The command php tests/credentials_file_test.php exercises the external-file, web-root and symlink exclusion, absolute-path enforcement, and exact file-permission protections. The command php tests/diagnostic_collector_test.php verifies the allowlisted payload, sanitized system URL, read-only database-version query, and explicit collector limitations. The command php tests/signed_api_client_test.php verifies the HMAC canonical request signature, request headers, fixed HTTPS destination, and TLS/redirect settings without making a network request.

The repository CI syntax-checks every addon PHP file and runs all three standalone tests using PHP 8.1, matching the WHMCS web runtime baseline.
