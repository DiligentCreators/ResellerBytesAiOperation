<?php

use App\Console\Commands\AssignWhmcsIntegrationTenant;
use App\Console\Commands\CheckKelviqBilling;
use App\Console\Commands\CheckOperationalAlerts;
use App\Console\Commands\CollectOperationalMetrics;
use App\Console\Commands\CreateOperationalBackup;
use App\Console\Commands\CreateOperationsUser;
use App\Console\Commands\CreateWhmcsIntegration;
use App\Console\Commands\DispatchTenantBillingOutbox;
use App\Console\Commands\ExportAuditAnchorCheckpoint;
use App\Console\Commands\MonitorAuditAnchorCheckpoint;
use App\Console\Commands\PlaceLegalHold;
use App\Console\Commands\PruneExpiredDiagnosticRecords;
use App\Console\Commands\PruneWhmcsIntegrationNonces;
use App\Console\Commands\PublishAuditAnchorCheckpoint;
use App\Console\Commands\ReconcileTenantBillingWebhooks;
use App\Console\Commands\ReleaseLegalHold;
use App\Console\Commands\ReportDiagnosticRetentionCandidates;
use App\Console\Commands\ReportTenantBillingOperations;
use App\Console\Commands\RestoreOperationalBackup;
use App\Console\Commands\RetryTenantBillingOutboxDeadLetter;
use App\Console\Commands\RetryTenantBillingWebhookDeadLetter;
use App\Console\Commands\RevokeWhmcsIntegration;
use App\Console\Commands\RotateWhmcsIntegrationSecret;
use App\Console\Commands\VerifyAuditAnchorCheckpoint;
use App\Console\Commands\VerifyAuditIntegrity;
use App\Http\Middleware\CaptureOperationalMetrics;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireCentralPermission;
use App\Http\Middleware\SetTenantDatabaseContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CheckKelviqBilling::class,
        CheckOperationalAlerts::class,
        CreateOperationalBackup::class,
        CollectOperationalMetrics::class,
        CreateOperationsUser::class,
        CreateWhmcsIntegration::class,
        AssignWhmcsIntegrationTenant::class,
        ExportAuditAnchorCheckpoint::class,
        MonitorAuditAnchorCheckpoint::class,
        PublishAuditAnchorCheckpoint::class,
        VerifyAuditIntegrity::class,
        VerifyAuditAnchorCheckpoint::class,
        PruneWhmcsIntegrationNonces::class,
        PlaceLegalHold::class,
        PruneExpiredDiagnosticRecords::class,
        ReleaseLegalHold::class,
        ReportDiagnosticRetentionCandidates::class,
        DispatchTenantBillingOutbox::class,
        ReconcileTenantBillingWebhooks::class,
        ReportTenantBillingOperations::class,
        RetryTenantBillingWebhookDeadLetter::class,
        RetryTenantBillingOutboxDeadLetter::class,
        RestoreOperationalBackup::class,
        RotateWhmcsIntegrationSecret::class,
        RevokeWhmcsIntegration::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->validateCsrfTokens(except: [
            'webhooks/kelviq',
        ]);
        $middleware->alias(['central.permission' => RequireCentralPermission::class]);

        $middleware->api(append: [CaptureOperationalMetrics::class]);
        $middleware->web(remove: [SubstituteBindings::class]);
        $middleware->web(append: [
            SetTenantDatabaseContext::class,
            SubstituteBindings::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            $errorPages = [403, 404, 419, 429, 500, 503];

            if (
                ! app()->runningUnitTests()
                && ! $request->expectsJson()
                && ! $request->is('api/*')
                && in_array($status, $errorPages, true)
                && ($request->header('X-Inertia') || $request->acceptsHtml())
            ) {
                return Inertia::render('errors/error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
