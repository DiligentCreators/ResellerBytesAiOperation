<?php

use App\Console\Commands\CreateOperationsUser;
use App\Console\Commands\CreateWhmcsIntegration;
use App\Console\Commands\ExportAuditAnchorCheckpoint;
use App\Console\Commands\MonitorAuditAnchorCheckpoint;
use App\Console\Commands\PlaceLegalHold;
use App\Console\Commands\PruneExpiredDiagnosticRecords;
use App\Console\Commands\PruneWhmcsIntegrationNonces;
use App\Console\Commands\ReleaseLegalHold;
use App\Console\Commands\ReportDiagnosticRetentionCandidates;
use App\Console\Commands\RevokeWhmcsIntegration;
use App\Console\Commands\RotateWhmcsIntegrationSecret;
use App\Console\Commands\VerifyAuditAnchorCheckpoint;
use App\Console\Commands\VerifyAuditIntegrity;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateOperationsUser::class,
        CreateWhmcsIntegration::class,
        ExportAuditAnchorCheckpoint::class,
        MonitorAuditAnchorCheckpoint::class,
        VerifyAuditIntegrity::class,
        VerifyAuditAnchorCheckpoint::class,
        PruneWhmcsIntegrationNonces::class,
        PlaceLegalHold::class,
        PruneExpiredDiagnosticRecords::class,
        ReleaseLegalHold::class,
        ReportDiagnosticRetentionCandidates::class,
        RotateWhmcsIntegrationSecret::class,
        RevokeWhmcsIntegration::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
