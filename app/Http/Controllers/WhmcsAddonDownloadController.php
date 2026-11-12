<?php

namespace App\Http\Controllers;

use App\Support\WhmcsAddonPackageSigner;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class WhmcsAddonDownloadController extends Controller
{
    public function __invoke(WhmcsAddonPackageSigner $signer): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class), 503, 'The WHMCS addon package service is unavailable.');
        abort_unless($signer->isConfigured(), 503, 'The WHMCS addon signing key is not configured.');

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

            $version = (string) config('services.whmcs_addon.version', '1.0.0');
            $manifestContents = implode("\n", $manifest)."\n";
            $signedPayload = $this->canonicalSignedPayload($version, $manifest);
            $signature = $signer->sign($signedPayload);
            $publicKey = $signer->publicKeyBase64();
            $fingerprint = $signer->fingerprint();

            if (! $archive->addFromString('INSTALLATION.txt', $this->installationInstructions())
                || ! $archive->addFromString('VERSION.txt', $version)
                || ! $archive->addFromString('SHA256SUMS.txt', $manifestContents)
                || ! $archive->addFromString('SIGNATURE.ed25519', $signature."\n")
                || ! $archive->addFromString('SIGNING-PUBLIC-KEY.txt', $publicKey."\n")
                || ! $archive->addFromString('SIGNING-KEY-FINGERPRINT.txt', $fingerprint."\n")) {
                throw new RuntimeException('Unable to write the addon archive manifests and signature.');
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

        $download = response()->download(
            $temporaryPath,
            'resellerbytes-ai-ops-whmcs-v'.$version.'.zip',
            [
                'Content-Type' => 'application/zip',
                'X-Content-Type-Options' => 'nosniff',
                'X-Addon-Signing-Key-SHA256' => $fingerprint,
            ],
        );

        $download->setPrivate();
        $download->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $download->deleteFileAfterSend(true);
    }

    /**
     * @param  array<int, string>  $manifest
     */
    private function canonicalSignedPayload(string $version, array $manifest): string
    {
        return implode("\n", [
            'resellerbytes-ai-ops-package-v1',
            'version='.$version,
            ...$manifest,
        ])."\n";
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

Package authenticity
- This archive includes an Ed25519 signature over the version and sorted SHA-256 file manifest.
- Verify SIGNATURE.ed25519 with SIGNING-PUBLIC-KEY.txt and compare the SHA-256 public-key fingerprint with the authenticated signing-key fingerprint endpoint at /whmcs-addon/signing-key-fingerprint before installation.
- Do not install the addon if signature verification fails or the fingerprint does not match.

Important: WHMCS does not provide a built-in ZIP addon-module uploader.
Use the 20i File Manager to upload files without an FTP client. If your
File Manager offers an Extract action, upload and extract this ZIP into
modules/addons. If it does not, extract the ZIP on your computer, create
the resellerbytes_ai_ops folder and its lib subfolder in modules/addons,
then upload the four PHP files through File Manager while preserving paths.

Installation
1. Take and verify a restorable WHMCS files and database backup.
2. Verify the package signature and fingerprint returned by the authenticated signing-key fingerprint endpoint.
3. Open your hosting control panel's File Manager.
4. Open the WHMCS installation directory, then modules/addons.
5. If File Manager offers Extract, upload and extract this ZIP into modules/addons. Otherwise, extract the ZIP locally and upload the four PHP files while preserving paths.
6. Confirm this exact path exists:
   modules/addons/resellerbytes_ai_ops/resellerbytes_ai_ops.php
7. In WHMCS Admin, open Configuration > System Settings > Addon Modules.
8. Activate "ResellerBytes AI Operations".
9. Configure the addon and set an absolute path to the external credentials JSON file.
10. In Access Control, allow only trusted administrator roles and save changes.
11. Open Addons > ResellerBytes AI Operations and run diagnostics once manually.

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
