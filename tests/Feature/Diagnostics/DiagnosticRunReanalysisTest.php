<?php

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\AuditEvent;
use App\Models\DiagnosticRun;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function createReanalysisTestRun(string $status = 'received'): DiagnosticRun
{
    $integration = WhmcsIntegration::create([
        'name' => 'Reanalysis test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    return DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => $status,
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('d', 64),
    ]);
}

it('allows a verified operator with confirmed two-factor authentication to queue analysis for a received snapshot', function () {
    config(['services.openai.key' => 'test-api-key']);
    Queue::fake();

    $user = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $run = createReanalysisTestRun();

    $this->actingAs($user)
        ->post('/diagnostic-runs/'.$run->id.'/reanalyze')
        ->assertRedirect();

    Queue::assertPushed(AnalyzeDiagnosticRun::class, fn (AnalyzeDiagnosticRun $job) => $job->diagnosticRunId === $run->id);
    expect($run->fresh()->status)->toBe('queued');
    expect(AuditEvent::where('event_type', 'diagnostic_run.analysis_requested')->count())->toBe(1);
});

it('does not allow analysis to be queued when the AI provider is not configured', function () {
    config(['services.openai.key' => null]);
    Queue::fake();

    $user = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $run = createReanalysisTestRun();

    $this->actingAs($user)
        ->post('/diagnostic-runs/'.$run->id.'/reanalyze')
        ->assertServiceUnavailable();

    Queue::assertNothingPushed();
});

it('blocks diagnostic reanalysis until two-factor authentication is confirmed', function () {
    config(['services.openai.key' => 'test-api-key']);
    Queue::fake();

    $user = User::factory()->create();
    $run = createReanalysisTestRun();

    $this->actingAs($user)
        ->post('/diagnostic-runs/'.$run->id.'/reanalyze')
        ->assertRedirect(route('security.edit'));

    Queue::assertNothingPushed();
    expect($run->fresh()->status)->toBe('received');
});
