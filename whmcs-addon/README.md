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

## Package authenticity

The operations application signs the version and sorted SHA-256 manifest with an Ed25519 key. The ZIP contains:

- SHA256SUMS.txt — SHA-256 checksums for every addon source file.
- SIGNATURE.ed25519 — detached Ed25519 signature over the version and exact manifest bytes.
- SIGNING-PUBLIC-KEY.txt — base64-encoded public key.
- SIGNING-KEY-FINGERPRINT.txt — SHA-256 fingerprint of the raw public key.

Before installation, compare the fingerprint in the archive with the fingerprint returned separately by the authenticated /whmcs-addon/signing-key-fingerprint endpoint (operator login and MFA required). A matching fingerprint establishes which public key to trust; the signature then authenticates the version and file manifest. Do not install if the values differ or signature verification fails.

To verify the signature with PHP 8.1+ and Sodium after extracting the archive, run this from the extracted package directory (the command is intentionally explicit and does not execute addon code):

    php -r '$m=file_get_contents("SHA256SUMS.txt"); $v=trim(file_get_contents("VERSION.txt")); $s=base64_decode(trim(file_get_contents("SIGNATURE.ed25519")),true); $p=base64_decode(trim(file_get_contents("SIGNING-PUBLIC-KEY.txt")),true); $f=trim(file_get_contents("SIGNING-KEY-FINGERPRINT.txt")); if(!is_string($m)||!is_string($s)||!is_string($p)||!hash_equals($f,hash("sha256",$p))||!sodium_crypto_sign_verify_detached($s,"resellerbytes-ai-ops-package-v1\nversion=".$v."\n".$m."\n",$p)){fwrite(STDERR,"Package signature verification FAILED\n"); exit(1);} foreach(explode("\n",trim($m)) as $line){if(!preg_match("/^([a-f0-9]{64})  (.+)$/",$line,$x)){exit(1);} $path="resellerbytes_ai_ops/".$x[2]; if(!is_file($path)||!hash_equals($x[1],hash_file("sha256",$path))){fwrite(STDERR,"Checksum mismatch: ".$path."\n"); exit(1);}} echo "Package signature and file checksums verified.\n";'

This verifies integrity and signature consistency. The public-key fingerprint must still be compared with the fingerprint returned by the authenticated signing-key fingerprint endpoint; a fingerprint stored only inside the ZIP is not a trust anchor.

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
2. WHMCS does not provide a built-in ZIP addon-module uploader. Use 20i File Manager to upload without FTP. If the panel offers Extract, extract the ZIP into /modules/addons. Otherwise extract it locally, create /modules/addons/resellerbytes_ai_ops and its lib subfolder, then upload the four PHP files through File Manager while preserving paths. The resulting path must be /modules/addons/resellerbytes_ai_ops/resellerbytes_ai_ops.php.
3. Verify the ZIP signature and compare its signing-key fingerprint with the authenticated operations dashboard before extracting or installing.
4. Create the credentials JSON file outside the web root and set its permissions to 0600.
5. In WHMCS, activate ResellerBytes AI Operations under Addon Modules.
6. Configure the absolute credentials-file path.
7. Grant addon access only to trusted administrator roles.
8. Open the addon page and run Run diagnostics once manually.
9. Confirm the accepted snapshot and inspect its exact payload in the operations application.

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
