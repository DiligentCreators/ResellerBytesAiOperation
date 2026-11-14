<?php

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\AiBudgetPeriod;
use App\Models\AiUsageAttempt;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\AiBudgetService;
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

it('marks a stale submitted provider attempt ambiguous and holds it for operator review', function () {
    Queue::fake();

    $run = createQueuedRecoveryTestRun();
    $run->forceFill([
        'status' => 'analyzing',
        'analysis_started_at' => now()->subMinutes(20),
        'analysis_attempts' => 1,
    ])->save();

    $budget = app(AiBudgetService::class);
    $attempt = $budget->reserve($run->fresh(), 'gpt-5.6-sol', 'queue-recovery.v1');
    $budget->markSubmitted($attempt);

    expect(Artisan::call('diagnostics:recover-queued'))->toBe(0);

    $freshAttempt = AiUsageAttempt::query()->findOrFail($attempt->id);
    $freshRun = $run->fresh();

    expect($freshAttempt->status)->toBe('ambiguous')
        ->and($freshAttempt->failure_code)->toBe('worker_lost_after_submit')
        ->and($freshRun->status)->toBe('analysis_failed')
        ->and($freshRun->analysis_error_code)->toBe('provider_outcome_ambiguous');

    Queue::assertNothingPushed();

    foreach (AiBudgetPeriod::query()->get() as $period) {
        expect($period->reserved_micro_usd)->toBe($freshAttempt->reserved_micro_usd)
            ->and($period->committed_micro_usd)->toBe(0);
    }
});

it('releases a stale pre-submit reservation before redispatching the diagnostic run', function () {
    Queue::fake();

    $run = createQueuedRecoveryTestRun();
    $run->forceFill([
        'status' => 'analyzing',
        'analysis_started_at' => now()->subMinutes(20),
        'analysis_attempts' => 1,
    ])->save();

    $budget = app(AiBudgetService::class);
    $attempt = $budget->reserve($run->fresh(), 'gpt-5.6-sol', 'queue-recovery.v1');

    expect(Artisan::call('diagnostics:recover-queued'))->toBe(0);

    expect($attempt->fresh()->status)->toBe('released')
        ->and($run->fresh()->status)->toBe('queued');

    Queue::assertPushed(AnalyzeDiagnosticRun::class, 1);

    foreach (AiBudgetPeriod::query()->get() as $period) {
        expect($period->reserved_micro_usd)->toBe(0);
    }
});

it('does not repeat a provider charge when a worker dies after usage settlement', function () {
    Queue::fake();

    $run = createQueuedRecoveryTestRun();
    $run->forceFill([
        'status' => 'analyzing',
        'analysis_started_at' => now()->subMinutes(20),
        'analysis_attempts' => 1,
    ])->save();

    $budget = app(AiBudgetService::class);
    $attempt = $budget->reserve($run->fresh(), 'gpt-5.6-sol', 'queue-recovery.v1');
    $budget->markSubmitted($attempt);
    $budget->settle($attempt, [
        'input_tokens' => 100,
        'cached_input_tokens' => 0,
        'output_tokens' => 50,
        'total_tokens' => 150,
    ], 'resp_settled_before_worker_crash');

    expect(Artisan::call('diagnostics:recover-queued'))->toBe(0);

    expect($attempt->fresh()->status)->toBe('settled')
        ->and($run->fresh()->status)->toBe('analysis_failed')
        ->and($run->fresh()->analysis_error_code)->toBe('analysis_interrupted_after_settlement');

    Queue::assertNothingPushed();
});
