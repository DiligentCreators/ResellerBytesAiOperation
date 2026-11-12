<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class WhmcsAddonSetupController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('whmcs-addon/setup', [
            'addonVersion' => config('services.whmcs_addon.version', '1.0.0'),
            'downloadAvailable' => class_exists(\ZipArchive::class),
            'aiConfigured' => filled(config('services.openai.key')),
        ]);
    }
}
