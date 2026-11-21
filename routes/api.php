<?php

use App\Http\Controllers\Api\ListPendingSafeActionsController;
use App\Http\Controllers\Api\StoreDiagnosticSnapshotController;
use App\Http\Controllers\Api\StoreHeartbeatController;
use App\Http\Controllers\Api\StoreSafeActionResultController;
use App\Http\Middleware\AuthenticateWhmcsIntegration;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/whmcs')
    ->middleware(['throttle:api-whmcs-diagnostic-snapshots', AuthenticateWhmcsIntegration::class])
    ->group(function (): void {
        Route::post('diagnostic-snapshots', StoreDiagnosticSnapshotController::class)
            ->name('api.whmcs.diagnostic-snapshots.store');

        Route::post('heartbeats', StoreHeartbeatController::class)
            ->name('api.whmcs.heartbeats.store');

        Route::get('safe-actions/pending', ListPendingSafeActionsController::class)
            ->name('api.whmcs.safe-actions.pending');

        Route::post('safe-actions/{safeAction}/result', StoreSafeActionResultController::class)
            ->name('api.whmcs.safe-actions.result');
    });
