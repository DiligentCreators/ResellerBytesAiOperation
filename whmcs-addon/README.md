# ResellerBytes AI Operations — WHMCS Addon

This addon is the WHMCS-side, read-only diagnostic collector for WHMCS 8.13.7 and PHP 8.1+. It is intentionally manual-only in the first pilot.

## Safety boundary

The addon:

- Collects a small allowlist of technical runtime signals.
- Reads only the WHMCS Version and SystemURL settings through the WHMCS settings model.
- Checks whether configuration.php is writable; it never reads the file contents.
- Sends one signed HTTPS POST to https://ops.resellerbytes.com/api/v1/whmcs/diagnostic-snapshots.
- Does not query customer, invoice, transaction, payment, hosting, domain, or registrar records.
- Does not write to the WHMCS database, execute shell commands, or call WHMCS business APIs.
- Does not install a hook or register a cron task.
- Does not change the existing 20i provisioning or ResellerClub registrar modules.

The collector explicitly reports what it cannot observe, including the system-cron PHP runtime, database server version, module status, hosting-panel configuration, and certificate verification. Missing visibility is not reported as healthy.

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
- The file is outside the WHMCS root and document root.
- The file has no group or world permissions.
- The JSON contains exactly the integration UUID and 256-bit secret.

Never commit the credentials file or paste its contents into tickets, chat, or logs.

## Installation procedure

Do not install this addon on production until the Laravel API is deployed, the integration secret has been created, and the production-pilot checklist has been approved.

1. Take and verify a restorable WHMCS files/database backup.
2. Upload modules/addons/resellerbytes_ai_ops to the WHMCS /modules/addons/ directory.
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

The command php tests/credentials_file_test.php exercises the external-file, web-root exclusion, and file-permission protections.

The repository CI separately syntax-checks every addon PHP file using PHP 8.1, matching the WHMCS web runtime baseline.
