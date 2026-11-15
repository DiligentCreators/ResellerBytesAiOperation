<?php

use App\Http\Controllers\CentralStaffController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticRunController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\PlatformMailSettingsController;
use App\Http\Controllers\TenantAuditExportController;
use App\Http\Controllers\TenantMembershipController;
use App\Http\Controllers\TenantNotificationController;
use App\Http\Controllers\TenantSettingsController;
use App\Http\Controllers\WhmcsAddonDownloadController;
use App\Http\Controllers\WhmcsAddonSetupController;
use App\Http\Controllers\WhmcsAddonSigningFingerprintController;
use App\Http\Controllers\WhmcsIntegrationController;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', EnsureTwoFactorEnabled::class])->group(function () {
    Route::get('platform/staff', [CentralStaffController::class, 'index'])->name('platform.staff.index');
    Route::post('platform/staff', [CentralStaffController::class, 'store'])->middleware('throttle:10,1')->name('platform.staff.store');
    Route::put('platform/staff/{staff}', [CentralStaffController::class, 'update'])->name('platform.staff.update');
    Route::delete('platform/staff/{staff}', [CentralStaffController::class, 'destroy'])->name('platform.staff.destroy');

    Route::middleware('central.permission:settings')->group(function () {
        Route::get('platform/mail-settings', [PlatformMailSettingsController::class, 'show'])->name('platform.mail-settings.show');
        Route::put('platform/mail-settings', [PlatformMailSettingsController::class, 'update'])->middleware('throttle:10,1')->name('platform.mail-settings.update');
        Route::post('platform/mail-settings/test', [PlatformMailSettingsController::class, 'test'])->middleware('throttle:3,1')->name('platform.mail-settings.test');
    });

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('tenants/{tenant}/settings', [TenantSettingsController::class, 'show'])->name('tenants.settings.show');
    Route::put('tenants/{tenant}/settings/branding', [TenantSettingsController::class, 'update'])->middleware('throttle:20,1')->name('tenants.settings.branding.update');
    Route::get('tenants/{tenant}/memberships', [TenantMembershipController::class, 'index'])->name('tenants.memberships.index');
    Route::post('tenants/{tenant}/memberships', [TenantMembershipController::class, 'invite'])->middleware('throttle:10,1')->name('tenants.memberships.invite');
    Route::get('tenants/{tenantId}/membership/invitation/{membershipId}', [TenantMembershipController::class, 'showInvitation'])->middleware(['signed', 'throttle:20,1'])->name('tenants.memberships.invitation.show');
    Route::post('tenants/{tenantId}/membership/accept', [TenantMembershipController::class, 'accept'])->middleware('throttle:10,1')->name('tenants.memberships.accept');
    Route::patch('tenants/{tenant}/memberships/{membership}/role', [TenantMembershipController::class, 'updateRole'])->name('tenants.memberships.role');
    Route::post('tenants/{tenant}/memberships/{membership}/suspend', [TenantMembershipController::class, 'suspend'])->name('tenants.memberships.suspend');
    Route::post('tenants/{tenant}/memberships/{membership}/revoke', [TenantMembershipController::class, 'revoke'])->name('tenants.memberships.revoke');
    Route::get('tenants/{tenant}/notifications', [TenantNotificationController::class, 'index'])->middleware('throttle:30,1')->name('tenants.notifications.index');
    Route::post('tenants/{tenant}/notifications/{notification}/read', [TenantNotificationController::class, 'markRead'])->middleware('throttle:30,1')->name('tenants.notifications.read');
    Route::get('tenants/{tenant}/audit-events/export', TenantAuditExportController::class)->middleware('throttle:3,1')->name('tenants.audit-events.export');
    Route::get('integrations', [WhmcsIntegrationController::class, 'index'])->name('integrations.index');
    Route::get('diagnostic-runs', [DiagnosticRunController::class, 'index'])->name('diagnostic-runs.index');
    Route::get('diagnostic-runs/{diagnosticRun}', [DiagnosticRunController::class, 'show'])->name('diagnostic-runs.show');
    Route::post('diagnostic-runs/{diagnosticRun}/reanalyze', [DiagnosticRunController::class, 'reanalyze'])->middleware('throttle:5,1')->name('diagnostic-runs.reanalyze');
    Route::get('findings', [FindingController::class, 'index'])->name('findings.index');
    Route::get('findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
    Route::get('whmcs-addon/download', WhmcsAddonDownloadController::class)->middleware('throttle:10,1')->name('whmcs-addon.download');
    Route::get('whmcs-addon/signing-key-fingerprint', WhmcsAddonSigningFingerprintController::class)->name('whmcs-addon.signing-key-fingerprint');
    Route::get('whmcs-addon/setup', WhmcsAddonSetupController::class)->name('whmcs-addon.setup');
});

require __DIR__.'/settings.php';
