<?php

use App\Http\Controllers\Api\ListPendingSafeActionsController;
use App\Http\Controllers\Api\StoreDiagnosticSnapshotController;
use App\Http\Controllers\Api\StoreHeartbeatController;
use App\Http\Controllers\Api\StoreSafeActionResultController;
use App\Http\Middleware\AuthenticateWhmcsIntegration;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/whmcs')
    ->middleware([AuthenticateWhmcsIntegration::class])
    ->group(function (): void {
        Route::post('diagnostic-snapshots', StoreDiagnosticSnapshotController::class)
            ->middleware('throttle:api-whmcs-diagnostic-snapshots')
            ->name('api.whmcs.diagnostic-snapshots.store');

        Route::post('heartbeats', StoreHeartbeatController::class)
            ->middleware('throttle:api-whmcs-heartbeats')
            ->name('api.whmcs.heartbeats.store');

        Route::get('safe-actions/pending', ListPendingSafeActionsController::class)
            ->middleware('throttle:api-whmcs-diagnostic-snapshots')
            ->name('api.whmcs.safe-actions.pending');

        Route::post('safe-actions/{safeAction}/result', StoreSafeActionResultController::class)
            ->middleware('throttle:api-whmcs-diagnostic-snapshots')
            ->name('api.whmcs.safe-actions.result');
    });
