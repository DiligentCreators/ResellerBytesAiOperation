<?php

namespace App\Http\Controllers;

use App\Support\WhmcsAddonPackageSigner;
use Inertia\Inertia;
use Inertia\Response;

class WhmcsAddonSetupController extends Controller
{
    public function __invoke(WhmcsAddonPackageSigner $signer): Response
    {
        return Inertia::render('whmcs-addon/setup', [
            'addonVersion' => config('services.whmcs_addon.version', '1.0.0'),
            'downloadAvailable' => class_exists(\ZipArchive::class) && $signer->isConfigured(),
            'aiConfigured' => filled(config('services.openai.key')),
        ]);
    }
}
