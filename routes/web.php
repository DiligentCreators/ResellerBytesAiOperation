<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WhmcsAddonDownloadController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('whmcs-addon/download', WhmcsAddonDownloadController::class)->name('whmcs-addon.download');
    Route::inertia('whmcs-addon/setup', 'whmcs-addon/setup')->name('whmcs-addon.setup');
});

require __DIR__.'/settings.php';
