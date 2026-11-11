<?php

namespace App\Http\Controllers;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class WhmcsAddonDownloadController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class), 503, 'The WHMCS addon package service is unavailable.');

        $sourceDirectory = base_path('whmcs-addon/modules/addons/resellerbytes_ai_ops');

        abort_unless(is_dir($sourceDirectory), 503, 'The WHMCS addon source package is unavailable.');

        $temporaryPath = tempnam(sys_get_temp_dir(), 'rb-whmcs-addon-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create a temporary addon archive.');
        }

        $archive = new ZipArchive;
        $opened = $archive->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            @unlink($temporaryPath);

            throw new RuntimeException('Unable to create the WHMCS addon archive.');
        }

        try {
            $manifest = [];
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }

                $absolutePath = $file->getPathname();
                $relativePath = substr($absolutePath, strlen($sourceDirectory) + 1);
                if (! $archive->addFile($absolutePath, 'resellerbytes_ai_ops/'.$relativePath)) {
                    throw new RuntimeException('Unable to add an addon file to the archive.');
                }

                $checksum = hash_file('sha256', $absolutePath);

                if ($checksum === false) {
                    throw new RuntimeException('Unable to calculate an addon file checksum.');
                }

                $manifest[] = $checksum.'  '.$relativePath;
            }

            if ($manifest === []) {
                throw new RuntimeException('The WHMCS addon source package is empty.');
            }

            sort($manifest, SORT_STRING);
            if (! $archive->addFromString('INSTALLATION.txt', $this->installationInstructions())
                || ! $archive->addFromString('VERSION.txt', (string) config('services.whmcs_addon.version', '1.0.0'))
                || ! $archive->addFromString('SHA256SUMS.txt', implode("\n", $manifest)."\n")) {
                throw new RuntimeException('Unable to write the addon archive manifest.');
            }

            if (! $archive->close()) {
                throw new RuntimeException('Unable to finalize the WHMCS addon archive.');
            }
        } catch (\Throwable $exception) {
            $archive->close();
            @unlink($temporaryPath);

            throw $exception;
        }

        $version = preg_replace('/[^0-9A-Za-z._-]/', '-', (string) config('services.whmcs_addon.version', '1.0.0'));

        return response()
            ->download(
                $temporaryPath,
                'resellerbytes-ai-ops-whmcs-v'.$version.'.zip',
                [
                    'Content-Type' => 'application/zip',
                    'Cache-Control' => 'private, no-store, max-age=0',
                    'X-Content-Type-Options' => 'nosniff',
                ],
            )
            ->deleteFileAfterSend(true);
    }

    private function installationInstructions(): string
    {
        return <<<'TEXT'
ResellerBytes AI Operations — WHMCS Addon
=========================================

Compatibility
- WHMCS 8.13.7
- PHP 8.1 or later
- cURL PHP extension enabled

Important: WHMCS does not provide a built-in ZIP addon-module uploader.
Use the 20i File Manager to upload files without an FTP client. If your
File Manager offers an Extract action, upload and extract this ZIP into
modules/addons. If it does not, extract the ZIP on your computer, create
the resellerbytes_ai_ops folder and its lib subfolder in modules/addons,
then upload the four PHP files through File Manager while preserving paths.

Installation
1. Take and verify a restorable WHMCS files and database backup.
2. Open your hosting control panel's File Manager.
3. Open the WHMCS installation directory, then modules/addons.
4. If File Manager offers Extract, upload and extract this ZIP into modules/addons. Otherwise, extract it locally and upload the four PHP files while preserving the folder structure.
5. Confirm this exact path exists:
   modules/addons/resellerbytes_ai_ops/resellerbytes_ai_ops.php
6. In WHMCS Admin, open Configuration > System Settings > Addon Modules.
7. Activate "ResellerBytes AI Operations".
8. Configure the addon and set an absolute path to the external credentials JSON file.
9. In Access Control, allow only trusted administrator roles and save changes.
10. Open Addons > ResellerBytes AI Operations and run diagnostics once manually.

Credentials
- Keep the JSON credentials file outside both the WHMCS installation root
  and the public document root.
- The file must contain only integration_id and secret.
- Set exact file permissions to 0600 and ensure the PHP runtime user can read it.
- Never paste the secret into the WHMCS addon configuration field.

First-run safety
- The first release is manual-only and read-only.
- Do not configure cron or recurring execution.
- The addon does not modify customer, invoice, payment, hosting, domain,
  20i provisioning, or ResellerClub registrar records.

Rollback
1. Revoke the integration in the ResellerBytes AI Operations dashboard.
2. Deactivate the addon in WHMCS.
3. Remove its directory using File Manager.
4. Delete the external credentials file through your approved server process.
5. Verify normal WHMCS operation and existing provisioning modules.
TEXT;
    }
}
