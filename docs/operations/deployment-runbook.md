# Deployment runbook

This runbook is for the ResellerBytes AI Operations application on the existing Laravel Forge VPS. The Laravel application uses PHP 8.5, PostgreSQL, and Redis. The WHMCS installation remains on 20i and continues to use its existing MariaDB, 20i hosting module, and ResellerClub registrar module.

## 1. Before deployment

- Confirm the feature branch has passed CI, security review, and the pull request has been merged to main.
- Confirm Forge has PHP 8.5 CLI and FPM available for the Laravel application.
- Confirm PHP extensions: pdo_pgsql, redis, zip, curl, mbstring, openssl, fileinfo, tokenizer, xml, ctype, bcmath, and intl.
- Confirm PostgreSQL is local/private and a dedicated least-privilege database user exists.
- Confirm Redis is local/private, authenticated where appropriate, persisted, monitored, and configured with noeviction.
- Confirm the application domain and HTTPS certificate are ready.
- Do not make changes to the WHMCS/20i server during Laravel deployment.

## 2. Laravel environment

Configure Forge environment variables. Do not commit .env or secrets.

Required application settings:
- APP_ENV=production
- APP_DEBUG=false
- APP_URL=https://ops.resellerbytes.com
- DB_CONNECTION=pgsql
- DB_HOST=127.0.0.1
- DB_PORT=5432
- DB_DATABASE=resellerbytes_ai_operation
- DB_USERNAME and DB_PASSWORD for the dedicated PostgreSQL user
- CACHE_STORE=redis
- SESSION_DRIVER=redis
- QUEUE_CONNECTION=redis
- REDIS_HOST=127.0.0.1
- REDIS_PORT=6379
- REDIS_DB=0
- REDIS_CACHE_DB=1
- OPENAI_API_KEY from a dedicated OpenAI API project
- OPENAI_MODEL=gpt-6.1-sol
- OPENAI_REASONING_EFFORT=medium
- WHMCS_ADDON_VERSION=1.0.0

Set a sensible OpenAI project spend limit and monitor usage. AI analysis must never receive credentials, customer records, invoices, payment details, or raw configuration files.

## 3. Deploy the Laravel application

1. Deploy the reviewed main branch through Forge.
2. Run composer install --no-dev --prefer-dist --optimize-autoloader.
3. Run npm ci and npm run build.
4. Confirm the production .env and APP_KEY are present. Do not regenerate an existing production key.
5. Run php artisan migrate --force.
6. Run php artisan optimize.
7. Confirm /up returns healthy and the dashboard login page loads over HTTPS.
8. Confirm public registration is unavailable.
9. Create the first operator with php artisan ops:user:create "Operations Admin" operator@example.com. Enter a strong password at the hidden prompts. Do not pass passwords as command-line arguments.
10. Sign in and enable two-factor authentication or a passkey.

## 4. Queue and scheduler

Configure a supervised Forge queue worker using the Redis queue:

    php artisan queue:work redis --queue=default --sleep=1 --tries=3 --timeout=90

The Redis queue retry_after is 120 seconds; keep worker timeout below it. Restart workers after deployment with php artisan queue:restart.

Configure Forge's scheduler to run php artisan schedule:run every minute. The application prunes expired HMAC nonces every 15 minutes.

Verify Redis recovery, queue processing, scheduler execution, and failure logging before connecting WHMCS.

## 5. Create the WHMCS integration

1. In Forge SSH, run php artisan whmcs:integration:create "ResellerBytes Production WHMCS".
2. Copy the integration UUID and one-time 256-bit secret directly into an approved password manager.
3. Never paste the secret into chat, tickets, logs, source control, or WHMCS addon settings.
4. Create a JSON file using the 20i hosting File Manager outside both the WHMCS installation root and the public document root:

    {
      "integration_id": "YOUR-INTEGRATION-UUID",
      "secret": "YOUR-64-CHARACTER-HEX-SECRET"
    }

5. Set the file's exact permissions to 0600 and ensure the WHMCS PHP runtime user can read it.
6. Record the absolute path. The addon configuration stores only this path.

## 6. Download and install the WHMCS addon

1. Sign in to the ResellerBytes AI Operations dashboard.
2. Open WHMCS Addon & Setup and download the versioned ZIP package.
3. WHMCS does not provide a built-in addon ZIP uploader. Use the 20i hosting File Manager to upload and extract the ZIP into the WHMCS modules/addons directory. FTP is not required if File Manager upload and extraction are available.
4. Confirm modules/addons/resellerbytes_ai_ops/resellerbytes_ai_ops.php exists.
5. In WHMCS Admin, open Configuration > System Settings > Addon Modules.
6. Activate ResellerBytes AI Operations.
7. Configure the absolute external credentials-file path.
8. Restrict Addon Access Control to trusted administrator roles.
9. Open Addons > ResellerBytes AI Operations and run diagnostics once manually.
10. Confirm the snapshot is visible in the operations dashboard, AI analysis completes, and the evidence matches the submitted sanitized payload.

Do not enable cron or recurring collection during the initial pilot.

## 7. Rollback

1. Stop manual diagnostic submissions.
2. Revoke the integration in the operations application, or run php artisan whmcs:integration:revoke INTEGRATION_UUID.
3. Deactivate the addon in WHMCS Admin.
4. Remove the addon folder using 20i File Manager.
5. Delete the external credentials JSON file through the approved server process.
6. Confirm WHMCS, the 20i provisioning module, and ResellerClub registrar module continue to operate normally.
7. Preserve sanitized audit evidence required for incident review.

Never rollback migrations by dropping production tables. Use forward corrective migrations only.
