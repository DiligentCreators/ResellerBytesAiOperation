<?php

namespace App\Http\Controllers;

use App\Support\WhmcsAddonPackageSigner;
use Inertia\Inertia;
use Inertia\Response;

class WhmcsAddonSetupController extends Controller
{
    public function __invoke(WhmcsAddonPackageSigner $signer): Response
    {
        $zipAvailable = class_exists(\ZipArchive::class);
        $signingConfigured = $signer->isConfigured();

        return Inertia::render('whmcs-addon/setup', [
            'addonVersion' => config('services.whmcs_addon.version', '1.0.0'),
            'downloadAvailable' => $zipAvailable && $signingConfigured,
            'downloadUnavailableReason' => ! $zipAvailable
                ? 'PHP ext-zip must be enabled before the addon can be packaged.'
                : 'Configure the Ed25519 signing key pair before downloading the addon.',
            'aiConfigured' => filled(config('services.openai.key')),
        ]);
    }
}
