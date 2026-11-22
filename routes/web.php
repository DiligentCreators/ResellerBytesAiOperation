<?php

use App\Http\Controllers\Billing\BillingSettingsController;
use App\Http\Controllers\Billing\CheckoutController;
use App\Http\Controllers\Billing\CheckoutReturnController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\SandboxCheckoutController;
use App\Http\Controllers\Billing\TrialController;
use App\Http\Controllers\BillingPlansController;
use App\Http\Controllers\CaseStudiesController;
use App\Http\Controllers\CentralStaffController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticRunController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\FixCenterController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Platform\PlatformBillingController;
use App\Http\Controllers\Platform\PlatformBillingGatewayController;
use App\Http\Controllers\Platform\PlatformBillingSettingsController;
use App\Http\Controllers\Platform\PlatformPlanController;
use App\Http\Controllers\Platform\PlatformSettingsController;
use App\Http\Controllers\PlatformAiSettingsController;
use App\Http\Controllers\PlatformMailSettingsController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\Reports\FixLogExportController;
use App\Http\Controllers\Reports\FixLogPrintController;
use App\Http\Controllers\Reports\HealthTrendsController;
use App\Http\Controllers\Reports\ReportResendController;
use App\Http\Controllers\Reports\ReportsController;
use App\Http\Controllers\Reports\ReportShowController;
use App\Http\Controllers\SafeActionController;
use App\Http\Controllers\TenantAlertChannelController;
use App\Http\Controllers\TenantAuditExportController;
use App\Http\Controllers\TenantAutoFixSettingsController;
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
Route::get('product', ProductController::class)->name('product');
Route::get('case-studies', CaseStudiesController::class)->name('case-studies');
Route::inertia('terms', 'legal/terms')->name('terms');
Route::inertia('privacy', 'legal/privacy')->name('privacy');

Route::post('webhooks/kelviq', KelviqWebhookController::class)
    ->middleware('throttle:webhooks-kelviq')
    ->name('webhooks.kelviq');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::put('onboarding/workspace', [OnboardingController::class, 'updateWorkspace'])->middleware('throttle:onboarding-workspace')->name('onboarding.workspace');
    Route::post('onboarding/plan', [OnboardingController::class, 'completePlan'])->middleware('throttle:onboarding-plan')->name('onboarding.plan');
    Route::post('onboarding/whmcs', [OnboardingController::class, 'completeWhmcs'])->middleware('throttle:onboarding-whmcs')->name('onboarding.whmcs');
    Route::get('onboarding/heartbeat-status', [OnboardingController::class, 'heartbeatStatus'])->middleware('throttle:onboarding-heartbeat-status')->name('onboarding.heartbeat-status');
    Route::post('onboarding/first-scan', [OnboardingController::class, 'completeFirstScan'])->middleware('throttle:onboarding-first-scan')->name('onboarding.first-scan');
    Route::post('onboarding/getting-started/dismiss', [OnboardingController::class, 'dismissGettingStarted'])->middleware('throttle:onboarding-getting-started-dismiss')->name('onboarding.getting-started.dismiss');
    Route::get('billing/plans', BillingPlansController::class)->name('billing.plans');

    Route::get('tenants/{tenant}/billing/checkout/{plan}', [CheckoutController::class, 'show'])->name('billing.checkout.show');
    Route::post('tenants/{tenant}/billing/checkout/{plan}', [CheckoutController::class, 'store'])->middleware('throttle:billing-checkout-store')->name('billing.checkout.store');
    Route::post('tenants/{tenant}/billing/trial/{plan}', [TrialController::class, 'store'])->middleware('throttle:billing-trial-store')->name('billing.trial.store');
    Route::get('tenants/{tenant}/billing/checkout-return/{invoice}', CheckoutReturnController::class)->name('billing.checkout.return');
    Route::get('tenants/{tenant}/billing/sandbox/{invoice}', [SandboxCheckoutController::class, 'show'])->name('billing.sandbox.checkout.show');
    Route::post('tenants/{tenant}/billing/sandbox/{invoice}/approve', [SandboxCheckoutController::class, 'approve'])->middleware('throttle:billing-sandbox-approve')->name('billing.sandbox.checkout.approve');
    Route::post('tenants/{tenant}/billing/sandbox/{invoice}/decline', [SandboxCheckoutController::class, 'decline'])->middleware('throttle:billing-sandbox-decline')->name('billing.sandbox.checkout.decline');
});

Route::middleware(['auth', 'verified', EnsureTwoFactorEnabled::class])->group(function () {
    Route::get('platform/staff', [CentralStaffController::class, 'index'])->name('platform.staff.index');
    Route::post('platform/staff', [CentralStaffController::class, 'store'])->middleware('throttle:platform-staff-store')->name('platform.staff.store');
    Route::put('platform/staff/{staff}', [CentralStaffController::class, 'update'])->middleware('throttle:platform-staff-update')->name('platform.staff.update');
    Route::delete('platform/staff/{staff}', [CentralStaffController::class, 'destroy'])->middleware('throttle:platform-staff-destroy')->name('platform.staff.destroy');

    Route::middleware('central.permission:settings')->group(function () {
        Route::get('platform/settings', [PlatformSettingsController::class, 'show'])->name('platform.settings.show');
        Route::put('platform/settings/{group}', [PlatformSettingsController::class, 'update'])->middleware('throttle:platform-settings-update')->name('platform.settings.update');
        Route::delete('platform/settings/key/{key}', [PlatformSettingsController::class, 'reset'])->middleware('throttle:platform-settings-reset')->name('platform.settings.reset');
        Route::get('platform/mail-settings', [PlatformMailSettingsController::class, 'show'])->name('platform.mail-settings.show');
        Route::put('platform/mail-settings', [PlatformMailSettingsController::class, 'update'])->middleware('throttle:platform-mail-settings-update')->name('platform.mail-settings.update');
        Route::post('platform/mail-settings/test', [PlatformMailSettingsController::class, 'test'])->middleware('throttle:platform-mail-settings-test')->name('platform.mail-settings.test');
        Route::get('platform/ai-settings', [PlatformAiSettingsController::class, 'show'])->name('platform.ai-settings.show');
        Route::put('platform/ai-settings', [PlatformAiSettingsController::class, 'update'])->middleware('throttle:platform-ai-settings-update')->name('platform.ai-settings.update');
        Route::post('platform/ai-settings/generate-signing-keys', [PlatformAiSettingsController::class, 'generateSigningKeys'])->middleware('throttle:platform-ai-settings-generate-signing-keys')->name('platform.ai-settings.generate-signing-keys');
    });

    Route::middleware('central.permission:billing')->group(function () {
        Route::get('platform/billing', [PlatformBillingController::class, 'index'])->name('platform.billing.index');
        Route::get('platform/billing/gateway', [PlatformBillingGatewayController::class, 'show'])->name('platform.billing.gateway');
        Route::post('platform/billing/gateway/webhooks/{webhook}/replay', [PlatformBillingGatewayController::class, 'replay'])->middleware('throttle:platform-billing-gateway-replay')->name('platform.billing.gateway.replay');
        Route::get('platform/billing/tenants/{tenant}', [PlatformBillingController::class, 'tenant'])->name('platform.billing.tenant');
        Route::put('platform/billing/seller', [PlatformBillingController::class, 'updateSeller'])->middleware('throttle:platform-billing-seller-update')->name('platform.billing.seller.update');
        Route::get('platform/billing/plans', [PlatformPlanController::class, 'index'])->name('platform.billing.plans.index');
    });

    Route::middleware('central.permission:billing.settings.manage')->group(function () {
        Route::get('platform/billing/settings', [PlatformBillingSettingsController::class, 'edit'])->name('platform.billing.settings.edit');
        Route::put('platform/billing/settings', [PlatformBillingSettingsController::class, 'update'])->middleware('throttle:platform-billing-settings-update')->name('platform.billing.settings.update');
        Route::post('platform/billing/settings/test-connection', [PlatformBillingSettingsController::class, 'testConnection'])->middleware('throttle:platform-billing-settings-test')->name('platform.billing.settings.test');
        Route::post('platform/billing/plans', [PlatformPlanController::class, 'store'])->middleware('throttle:platform-billing-plans-store')->name('platform.billing.plans.store');
        Route::put('platform/billing/plans/{plan}', [PlatformPlanController::class, 'update'])->middleware('throttle:platform-billing-plans-update')->name('platform.billing.plans.update');
        Route::post('platform/billing/plans/{plan}/deactivate', [PlatformPlanController::class, 'deactivate'])->middleware('throttle:platform-billing-plans-update')->name('platform.billing.plans.deactivate');
        Route::post('platform/billing/plans/{plan}/reactivate', [PlatformPlanController::class, 'reactivate'])->middleware('throttle:platform-billing-plans-update')->name('platform.billing.plans.reactivate');
        Route::delete('platform/billing/plans/{plan}', [PlatformPlanController::class, 'destroy'])->middleware('throttle:platform-billing-plans-destroy')->name('platform.billing.plans.destroy');
    });

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('reports', ReportsController::class)->name('reports.index');
    Route::get('reports/trends', HealthTrendsController::class)->name('reports.trends');
    Route::get('tenants/{tenant}/reports/fix-log/export', FixLogExportController::class)
        ->middleware('throttle:reports-fix-log-export')
        ->name('reports.fix-log.export');
    Route::get('tenants/{tenant}/reports/fix-log/print', FixLogPrintController::class)
        ->name('reports.fix-log.print');
    Route::get('tenants/{tenant}/reports/{report}', ReportShowController::class)->name('reports.show');
    Route::post('tenants/{tenant}/reports/{report}/resend', ReportResendController::class)
        ->middleware('throttle:reports-resend')
        ->name('reports.resend');
    Route::get('tenants/{tenant}/settings', [TenantSettingsController::class, 'show'])->name('tenants.settings.show');
    Route::put('tenants/{tenant}/settings/branding', [TenantSettingsController::class, 'update'])->middleware('throttle:tenants-settings-branding-update')->name('tenants.settings.branding.update');
    Route::put('tenants/{tenant}/settings/hourly-rate', [TenantSettingsController::class, 'updateHourlyRate'])->middleware('throttle:tenants-settings-branding-update')->name('tenants.settings.hourly-rate.update');
    Route::get('tenants/{tenant}/auto-fix', [TenantAutoFixSettingsController::class, 'show'])->name('tenants.auto-fix.show');
    Route::get('tenants/{tenant}/memberships', [TenantMembershipController::class, 'index'])->name('tenants.memberships.index');
    Route::post('tenants/{tenant}/memberships', [TenantMembershipController::class, 'invite'])->middleware('throttle:tenants-memberships-invite')->name('tenants.memberships.invite');
    Route::get('tenants/{tenantId}/membership/invitation/{membershipId}', [TenantMembershipController::class, 'showInvitation'])->middleware(['signed', 'throttle:tenants-memberships-invitation-show'])->name('tenants.memberships.invitation.show');
    Route::post('tenants/{tenantId}/membership/accept', [TenantMembershipController::class, 'accept'])->middleware('throttle:tenants-memberships-accept')->name('tenants.memberships.accept');
    Route::patch('tenants/{tenant}/memberships/{membership}/role', [TenantMembershipController::class, 'updateRole'])->middleware('throttle:tenants-memberships-role')->name('tenants.memberships.role');
    Route::post('tenants/{tenant}/memberships/{membership}/suspend', [TenantMembershipController::class, 'suspend'])->middleware('throttle:tenants-memberships-suspend')->name('tenants.memberships.suspend');
    Route::post('tenants/{tenant}/memberships/{membership}/revoke', [TenantMembershipController::class, 'revoke'])->middleware('throttle:tenants-memberships-revoke')->name('tenants.memberships.revoke');
    Route::get('tenants/{tenant}/notifications', [TenantNotificationController::class, 'index'])->middleware('throttle:tenants-notifications-index')->name('tenants.notifications.index');
    Route::post('tenants/{tenant}/notifications/{notification}/read', [TenantNotificationController::class, 'markRead'])->middleware('throttle:tenants-notifications-read')->name('tenants.notifications.read');
    Route::get('tenants/{tenant}/alerts', [TenantAlertChannelController::class, 'index'])->middleware('throttle:tenants-alerts-index')->name('tenants.alerts.index');
    Route::post('tenants/{tenant}/alerts/channels', [TenantAlertChannelController::class, 'store'])->middleware('throttle:tenants-alerts-store')->name('tenants.alerts.channels.store');
    Route::put('tenants/{tenant}/alerts/channels/{channel}', [TenantAlertChannelController::class, 'update'])->middleware('throttle:tenants-alerts-update')->name('tenants.alerts.channels.update');
    Route::delete('tenants/{tenant}/alerts/channels/{channel}', [TenantAlertChannelController::class, 'destroy'])->middleware('throttle:tenants-alerts-destroy')->name('tenants.alerts.channels.destroy');
    Route::post('tenants/{tenant}/alerts/channels/{channel}/test', [TenantAlertChannelController::class, 'test'])->middleware('throttle:tenants-alerts-test')->name('tenants.alerts.channels.test');
    Route::get('tenants/{tenant}/audit-events/export', TenantAuditExportController::class)->middleware('throttle:tenants-audit-events-export')->name('tenants.audit-events.export');

    Route::get('tenants/{tenant}/billing', [BillingSettingsController::class, 'show'])->name('billing.settings.show');
    Route::put('tenants/{tenant}/billing/details', [BillingSettingsController::class, 'updateDetails'])->middleware('throttle:billing-settings-details')->name('billing.settings.details');
    Route::post('tenants/{tenant}/billing/subscriptions/{subscription}/cancel', [BillingSettingsController::class, 'cancel'])->middleware('throttle:billing-subscriptions-cancel')->name('billing.subscriptions.cancel');
    Route::post('tenants/{tenant}/billing/subscriptions/{subscription}/resume', [BillingSettingsController::class, 'resume'])->middleware('throttle:billing-subscriptions-resume')->name('billing.subscriptions.resume');
    Route::delete('tenants/{tenant}/billing/subscriptions/{subscription}/scheduled-change', [BillingSettingsController::class, 'cancelScheduledChange'])->middleware('throttle:billing-subscriptions-cancel')->name('billing.subscriptions.scheduled-change.cancel');
    Route::get('tenants/{tenant}/billing/invoices', [InvoiceController::class, 'index'])->name('billing.invoices.index');
    Route::get('tenants/{tenant}/billing/invoices/{invoice}', [InvoiceController::class, 'show'])->name('billing.invoices.show');
    Route::get('tenants/{tenant}/billing/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('billing.invoices.print');

    Route::get('integrations', [WhmcsIntegrationController::class, 'index'])->name('integrations.index');
    Route::post('integrations', [WhmcsIntegrationController::class, 'store'])->middleware('throttle:integrations-store')->name('integrations.store');
    Route::post('integrations/{integration}/rotate', [WhmcsIntegrationController::class, 'rotate'])->middleware('throttle:integrations-rotate')->name('integrations.rotate');
    Route::put('integrations/{integration}/host-connection', [WhmcsIntegrationController::class, 'updateHostConnection'])->name('integrations.host-connection.update');
    Route::put('integrations/{integration}/auto-collect', [WhmcsIntegrationController::class, 'updateAutoCollect'])->name('integrations.auto-collect.update');
    Route::get('diagnostic-runs', [DiagnosticRunController::class, 'index'])->name('diagnostic-runs.index');
    Route::get('diagnostic-runs/{diagnosticRun}', [DiagnosticRunController::class, 'show'])->name('diagnostic-runs.show');
    Route::post('diagnostic-runs/{diagnosticRun}/reanalyze', [DiagnosticRunController::class, 'reanalyze'])->middleware('throttle:diagnostic-runs-reanalyze')->name('diagnostic-runs.reanalyze');
    Route::get('fix-center', [FixCenterController::class, 'index'])->name('fix-center.index');
    Route::post('fix-center/{finding}/guided-steps', [FixCenterController::class, 'markStepDone'])->middleware('throttle:60,1')->name('fix-center.guided-steps.store');
    Route::post('fix-center/{finding}/complete-guide', [FixCenterController::class, 'completeGuide'])->middleware('throttle:30,1')->name('fix-center.complete-guide');
    Route::post('fix-center/{finding}/ignore', [FixCenterController::class, 'ignore'])->middleware('throttle:30,1')->name('fix-center.ignore');
    Route::post('fix-center/{finding}/unignore', [FixCenterController::class, 'unignore'])->middleware('throttle:30,1')->name('fix-center.unignore');
    Route::get('findings', [FindingController::class, 'index'])->name('findings.index');
    Route::get('findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
    Route::patch('findings/{finding}', [FindingController::class, 'update'])->middleware('throttle:60,1')->name('findings.update');
    Route::post('findings/{finding}/recheck', [FindingController::class, 'recheck'])->middleware('throttle:30,1')->name('findings.recheck');
    Route::get('safe-actions', [SafeActionController::class, 'index'])->name('safe-actions.index');
    Route::post('safe-actions', [SafeActionController::class, 'store'])->middleware('throttle:30,1')->name('safe-actions.store');
    Route::get('safe-actions/{safeAction}', [SafeActionController::class, 'show'])->name('safe-actions.show');
    Route::post('safe-actions/{safeAction}/approve', [SafeActionController::class, 'approve'])->middleware('throttle:20,1')->name('safe-actions.approve');
    Route::get('whmcs-addon/download', WhmcsAddonDownloadController::class)->middleware('throttle:whmcs-addon-download')->name('whmcs-addon.download');
    Route::get('whmcs-addon/signing-key-fingerprint', WhmcsAddonSigningFingerprintController::class)->name('whmcs-addon.signing-key-fingerprint');
    Route::get('whmcs-addon/setup', WhmcsAddonSetupController::class)->name('whmcs-addon.setup');
});

require __DIR__.'/settings.php';
