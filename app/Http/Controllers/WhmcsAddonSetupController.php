<?php

namespace App\Http\Controllers;

use App\Services\Ai\OpenAiConfiguration;
use App\Support\WhmcsAddonPackageSigner;
use Inertia\Inertia;
use Inertia\Response;

class WhmcsAddonSetupController extends Controller
{
    public function __invoke(WhmcsAddonPackageSigner $signer, OpenAiConfiguration $openAi): Response
    {
        $zipAvailable = class_exists(\ZipArchive::class);
        $signingConfigured = $signer->isConfigured();

        return Inertia::render('whmcs-addon/setup', [
            'addonVersion' => config('services.whmcs_addon.version', '1.0.0'),
            'downloadAvailable' => $zipAvailable && $signingConfigured,
            'downloadUnavailableReason' => ! $zipAvailable
                ? 'PHP ZIP extension must be enabled by the platform before the addon can be downloaded.'
                : 'Addon download is temporarily unavailable. Contact ResellerBytes support if this continues.',
            'aiConfigured' => $openAi->isConfigured(),
        ]);
    }
}
