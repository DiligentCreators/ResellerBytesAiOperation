<?php

namespace App\Http\Controllers;

use App\Support\WhmcsAddonPackageSigner;
use Illuminate\Http\Response;

class WhmcsAddonSigningFingerprintController extends Controller
{
    public function __invoke(WhmcsAddonPackageSigner $signer): Response
    {
        abort_unless($signer->isConfigured(), 503, 'The WHMCS addon signing key is not configured.');

        return response($signer->fingerprint(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
