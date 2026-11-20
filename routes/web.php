<?php

use App\Http\Controllers\Billing\BillingSettingsController;
use App\Http\Controllers\Billing\CheckoutController;
use App\Http\Controllers\Billing\CheckoutReturnController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\SandboxCheckoutController;
use App\Http\Controllers\BillingPlansController;
use App\Http\Controllers\CentralStaffController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticRunController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Platform\PlatformBillingController;
use App\Http\Controllers\Platform\PlatformBillingGatewayController;
use App\Http\Controllers\Platform\PlatformPlanController;
use App\Http\Controllers\PlatformAiSettingsController;
use App\Http\Controllers\PlatformMailSettingsController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\TenantAuditExportController;
use App\Http\Controllers\TenantMembershipController;
use App\Http\Controllers\TenantNotificationController;
use App\Http\Controllers\TenantSettingsController;
use App\Http\Controllers\Webhooks\KelviqWebhookController;
use App\Http\Controllers\WhmcsAddonDownloadController;
use App\Http\Controllers\WhmcsAddonSetupController;
use App\Http\Controllers\WhmcsAddonSigningFingerprintController;
use App\Http\Controllers\WhmcsIntegrationController;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::get('pricing', PricingController::class)->name('pricing');

Route::post('webhooks/kelviq', KelviqWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.kelviq');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::put('onboarding/workspace', [OnboardingController::class, 'updateWorkspace'])->middleware('throttle:20,1')->name('onboarding.workspace');
    Route::post('onboarding/plan', [OnboardingController::class, 'completePlan'])->middleware('throttle:20,1')->name('onboarding.plan');
    Route::post('onboarding/whmcs', [OnboardingController::class, 'completeWhmcs'])->middleware('throttle:20,1')->name('onboarding.whmcs');
    Route::get('billing/plans', BillingPlansController::class)->name('billing.plans');

    Route::get('tenants/{tenant}/billing/checkout/{plan}', [CheckoutController::class, 'show'])->name('billing.checkout.show');
    Route::post('tenants/{tenant}/billing/checkout/{plan}', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('billing.checkout.store');
    Route::get('tenants/{tenant}/billing/checkout-return/{invoice}', CheckoutReturnController::class)->name('billing.checkout.return');
    Route::get('tenants/{tenant}/billing/sandbox/{invoice}', [SandboxCheckoutController::class, 'show'])->name('billing.sandbox.checkout.show');
    Route::post('tenants/{tenant}/billing/sandbox/{invoice}/approve', [SandboxCheckoutController::class, 'approve'])->middleware('throttle:20,1')->name('billing.sandbox.checkout.approve');
    Route::post('tenants/{tenant}/billing/sandbox/{invoice}/decline', [SandboxCheckoutController::class, 'decline'])->middleware('throttle:20,1')->name('billing.sandbox.checkout.decline');
});

Route::middleware(['auth', 'verified', EnsureTwoFactorEnabled::class])->group(function () {
    Route::get('platform/staff', [CentralStaffController::class, 'index'])->name('platform.staff.index');
    Route::post('platform/staff', [CentralStaffController::class, 'store'])->middleware('throttle:10,1')->name('platform.staff.store');
    Route::put('platform/staff/{staff}', [CentralStaffController::class, 'update'])->name('platform.staff.update');
    Route::delete('platform/staff/{staff}', [CentralStaffController::class, 'destroy'])->name('platform.staff.destroy');

    Route::middleware('central.permission:settings')->group(function () {
        Route::get('platform/mail-settings', [PlatformMailSettingsController::class, 'show'])->name('platform.mail-settings.show');
        Route::put('platform/mail-settings', [PlatformMailSettingsController::class, 'update'])->middleware('throttle:10,1')->name('platform.mail-settings.update');
        Route::post('platform/mail-settings/test', [PlatformMailSettingsController::class, 'test'])->middleware('throttle:3,1')->name('platform.mail-settings.test');
        Route::get('platform/ai-settings', [PlatformAiSettingsController::class, 'show'])->name('platform.ai-settings.show');
        Route::put('platform/ai-settings', [PlatformAiSettingsController::class, 'update'])->middleware('throttle:10,1')->name('platform.ai-settings.update');
        Route::post('platform/ai-settings/generate-signing-keys', [PlatformAiSettingsController::class, 'generateSigningKeys'])->middleware('throttle:5,1')->name('platform.ai-settings.generate-signing-keys');
    });

    Route::middleware('central.permission:billing')->group(function () {
        Route::get('platform/billing', [PlatformBillingController::class, 'index'])->name('platform.billing.index');
        Route::get('platform/billing/gateway', [PlatformBillingGatewayController::class, 'show'])->name('platform.billing.gateway');
        Route::post('platform/billing/gateway/webhooks/{webhook}/replay', [PlatformBillingGatewayController::class, 'replay'])->middleware('throttle:20,1')->name('platform.billing.gateway.replay');
        Route::get('platform/billing/tenants/{tenant}', [PlatformBillingController::class, 'tenant'])->name('platform.billing.tenant');
        Route::put('platform/billing/seller', [PlatformBillingController::class, 'updateSeller'])->middleware('throttle:10,1')->name('platform.billing.seller.update');
        Route::get('platform/billing/plans', [PlatformPlanController::class, 'index'])->name('platform.billing.plans.index');
        Route::post('platform/billing/plans', [PlatformPlanController::class, 'store'])->middleware('throttle:20,1')->name('platform.billing.plans.store');
        Route::put('platform/billing/plans/{plan}', [PlatformPlanController::class, 'update'])->middleware('throttle:20,1')->name('platform.billing.plans.update');
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

    Route::get('tenants/{tenant}/billing', [BillingSettingsController::class, 'show'])->name('billing.settings.show');
    Route::put('tenants/{tenant}/billing/details', [BillingSettingsController::class, 'updateDetails'])->middleware('throttle:20,1')->name('billing.settings.details');
    Route::post('tenants/{tenant}/billing/subscriptions/{subscription}/cancel', [BillingSettingsController::class, 'cancel'])->middleware('throttle:10,1')->name('billing.subscriptions.cancel');
    Route::post('tenants/{tenant}/billing/subscriptions/{subscription}/resume', [BillingSettingsController::class, 'resume'])->middleware('throttle:10,1')->name('billing.subscriptions.resume');
    Route::get('tenants/{tenant}/billing/invoices', [InvoiceController::class, 'index'])->name('billing.invoices.index');
    Route::get('tenants/{tenant}/billing/invoices/{invoice}', [InvoiceController::class, 'show'])->name('billing.invoices.show');
    Route::get('tenants/{tenant}/billing/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('billing.invoices.print');

    Route::get('integrations', [WhmcsIntegrationController::class, 'index'])->name('integrations.index');
    Route::post('integrations', [WhmcsIntegrationController::class, 'store'])->middleware('throttle:10,1')->name('integrations.store');
    Route::post('integrations/{integration}/rotate', [WhmcsIntegrationController::class, 'rotate'])->middleware('throttle:5,1')->name('integrations.rotate');
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
