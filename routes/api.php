<?php

use App\Http\Controllers\Api\StoreDiagnosticSnapshotController;
use App\Http\Middleware\AuthenticateWhmcsIntegration;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/whmcs')
    ->middleware(['throttle:api-whmcs-diagnostic-snapshots', AuthenticateWhmcsIntegration::class])
    ->group(function (): void {
        Route::post('diagnostic-snapshots', StoreDiagnosticSnapshotController::class)
            ->name('api.whmcs.diagnostic-snapshots.store');
    });
