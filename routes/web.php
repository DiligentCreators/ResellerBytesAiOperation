<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticRunController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\WhmcsAddonDownloadController;
use App\Http\Controllers\WhmcsAddonSetupController;
use App\Http\Controllers\WhmcsIntegrationController;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', EnsureTwoFactorEnabled::class])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('integrations', [WhmcsIntegrationController::class, 'index'])->name('integrations.index');
    Route::get('diagnostic-runs', [DiagnosticRunController::class, 'index'])->name('diagnostic-runs.index');
    Route::get('diagnostic-runs/{diagnosticRun}', [DiagnosticRunController::class, 'show'])->name('diagnostic-runs.show');
    Route::post('diagnostic-runs/{diagnosticRun}/reanalyze', [DiagnosticRunController::class, 'reanalyze'])->middleware('throttle:5,1')->name('diagnostic-runs.reanalyze');
    Route::get('findings', [FindingController::class, 'index'])->name('findings.index');
    Route::get('findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
    Route::get('whmcs-addon/download', WhmcsAddonDownloadController::class)->middleware('throttle:10,1')->name('whmcs-addon.download');
    Route::get('whmcs-addon/setup', WhmcsAddonSetupController::class)->name('whmcs-addon.setup');
});

require __DIR__.'/settings.php';
