<?php

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function createQueuedRecoveryTestRun(): DiagnosticRun
{
    $integration = WhmcsIntegration::create([
        'name' => 'Queue recovery test '.Str::uuid(),
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    return DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'queued',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('a', 64),
    ]);
}

it('re-dispatches stale queued runs but leaves fresh queued runs untouched', function () {
    Queue::fake();

    $staleRun = createQueuedRecoveryTestRun();
    $freshRun = createQueuedRecoveryTestRun();

    DB::table('diagnostic_runs')
        ->where('id', $staleRun->id)
        ->update(['updated_at' => now()->subMinutes(20)]);

    DB::table('diagnostic_runs')
        ->where('id', $freshRun->id)
        ->update(['updated_at' => now()->subMinutes(5)]);

    expect(Artisan::call('diagnostics:recover-queued'))->toBe(0);

    Queue::assertPushed(
        AnalyzeDiagnosticRun::class,
        fn (AnalyzeDiagnosticRun $job): bool => $job->diagnosticRunId === $staleRun->id,
    );
    Queue::assertNotPushed(
        AnalyzeDiagnosticRun::class,
        fn (AnalyzeDiagnosticRun $job): bool => $job->diagnosticRunId === $freshRun->id,
    );

    expect($staleRun->fresh()->updated_at->greaterThan(now()->subMinute()))->toBeTrue();
    expect($freshRun->fresh()->updated_at->lessThan(now()->subMinute()))->toBeTrue();
});

it('respects the bounded recovery batch limit', function () {
    Queue::fake();

    $olderRun = createQueuedRecoveryTestRun();
    $newerRun = createQueuedRecoveryTestRun();

    DB::table('diagnostic_runs')
        ->where('id', $olderRun->id)
        ->update(['updated_at' => now()->subMinutes(30)]);

    DB::table('diagnostic_runs')
        ->where('id', $newerRun->id)
        ->update(['updated_at' => now()->subMinutes(20)]);

    expect(Artisan::call('diagnostics:recover-queued', ['--limit' => 1]))->toBe(0);

    Queue::assertPushed(AnalyzeDiagnosticRun::class, 1);
    expect($olderRun->fresh()->updated_at->greaterThan(now()->subMinute()))->toBeTrue();
    expect($newerRun->fresh()->updated_at->lessThan(now()->subMinute()))->toBeTrue();
});

it('recovers abandoned analyzing runs only after the lease and queue visibility window', function () {
    Queue::fake();

    $staleRun = createQueuedRecoveryTestRun();
    $freshRun = createQueuedRecoveryTestRun();
    $freshNullLeaseRun = createQueuedRecoveryTestRun();

    DB::table('diagnostic_runs')
        ->where('id', $staleRun->id)
        ->update([
            'status' => 'analyzing',
            'analysis_started_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);

    DB::table('diagnostic_runs')
        ->where('id', $freshRun->id)
        ->update([
            'status' => 'analyzing',
            'analysis_started_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

    DB::table('diagnostic_runs')
        ->where('id', $freshNullLeaseRun->id)
        ->update([
            'status' => 'analyzing',
            'analysis_started_at' => null,
            'updated_at' => now()->subMinutes(5),
        ]);

    expect(Artisan::call('diagnostics:recover-queued'))->toBe(0);

    Queue::assertPushed(
        AnalyzeDiagnosticRun::class,
        fn (AnalyzeDiagnosticRun $job): bool => $job->diagnosticRunId === $staleRun->id,
    );
    Queue::assertNotPushed(
        AnalyzeDiagnosticRun::class,
        fn (AnalyzeDiagnosticRun $job): bool => in_array($job->diagnosticRunId, [$freshRun->id, $freshNullLeaseRun->id], true),
    );

    expect($staleRun->fresh()->status)->toBe('queued');
    expect($staleRun->fresh()->analysis_started_at)->toBeNull();
    expect($freshRun->fresh()->status)->toBe('analyzing');
    expect($freshNullLeaseRun->fresh()->status)->toBe('analyzing');
});
