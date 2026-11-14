<?php

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\AiBudgetPeriod;
use App\Models\AiUsageAttempt;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Diagnostics\AiBudgetService;
use App\Services\Diagnostics\WhmcsDiagnosticAnalyzer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use RuntimeException;

it('persists validated AI findings and recommendations against their source snapshot', function () {
    $integration = WhmcsIntegration::create([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'Analysis job test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => [
            'system' => ['whmcs_version' => '8.13.7'],
            'warnings' => [['code' => 'configuration_file_writable', 'severity' => 'warning']],
        ],
        'payload_sha256' => str_repeat('b', 64),
    ]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->with(
        Mockery::on(fn ($model) => $model->is($run)),
        Mockery::on(fn ($callback) => is_callable($callback)),
        Mockery::on(fn ($callback) => is_callable($callback)),
    )->andReturnUsing(function ($model, $beforeSubmit, $afterResponse) {
        $beforeSubmit();
        $afterResponse(['id' => 'resp_test_456'], [
            'input_tokens' => 100,
            'cached_input_tokens' => 0,
            'output_tokens' => 50,
            'total_tokens' => 150,
        ]);

        return [
            'summary' => 'A writable configuration file was observed.',
            'no_findings_reason' => null,
            'findings' => [
                [
                    'category' => 'security',
                    'severity' => 'warning',
                    'title' => 'Configuration file is writable',
                    'summary' => 'The PHP runtime can write to the configuration file.',
                    'evidence_paths' => ['warnings.0.code'],
                    'confidence' => 0.98,
                    'recommendations' => [
                        [
                            'recommendation' => 'Inspect owner and permissions before making a change.',
                            'rationale' => 'Avoid locking out WHMCS updates or normal runtime access.',
                            'risk_level' => 'medium',
                        ],
                    ],
                ],
            ],
            'response_id' => 'resp_test_456',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150],
        ];
    });

    (new AnalyzeDiagnosticRun($run->id, (int) $integration->owner_user_id))->handle($analyzer, app(AiBudgetService::class));

    $freshRun = $run->fresh();
    $finding = DiagnosticFinding::query()->with('recommendations')->sole();

    expect($freshRun->status)->toBe('analyzed')
        ->and($freshRun->analysis_summary)->toContain('writable')
        ->and($freshRun->analysis_response_id)->toBe('resp_test_456')
        ->and($freshRun->analysis_attempts)->toBe(1)
        ->and($finding->evidence)->toBe(['warnings.0.code' => 'configuration_file_writable'])
        ->and($finding->recommendations)->toHaveCount(1)
        ->and($finding->recommendations->first()->requires_manual_action)->toBeTrue();
});


it('does not start a billable analysis for a diagnostic run beyond its retention deadline', function () {
    $integration = WhmcsIntegration::create([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'Expired analysis guard test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'queued',
        'collected_at' => now()->subDays(366),
        'received_at' => now()->subDays(366),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('9', 64),
    ]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldNotReceive('analyze');
    $budget = Mockery::mock(AiBudgetService::class);
    $budget->shouldNotReceive('reserve');

    (new AnalyzeDiagnosticRun($run->id))->handle($analyzer, $budget);

    expect($run->fresh()->status)->toBe('queued')
        ->and($run->fresh()->analysis_attempts)->toBe(0);
});


it('releases a pre-submit reservation if the diagnostic crosses its retention cutoff before provider submission', function () {
    $integration = WhmcsIntegration::create([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'Retention boundary submission test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('8', 64),
    ]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->andReturnUsing(function ($model, $beforeSubmit) use ($run) {
        $run->forceFill(['received_at' => now('UTC')->subDays(365)])->save();
        $beforeSubmit();

        throw new RuntimeException('provider should not be called');
    });

    $budget = app(AiBudgetService::class);
    expect(fn () => (new AnalyzeDiagnosticRun($run->id))->handle($analyzer, $budget))
        ->toThrow(RuntimeException::class, 'diagnostic_retention_expired');

    $attempt = AiUsageAttempt::query()->where('diagnostic_run_id', $run->id)->sole();

    expect($attempt->status)->toBe('released')
        ->and($run->fresh()->status)->toBe('analysis_failed');

    foreach (AiBudgetPeriod::query()->get() as $period) {
        expect($period->reserved_micro_usd)->toBe(0);
    }
});

it('does not persist provider results when a diagnostic expires during analysis', function () {
    $integration = WhmcsIntegration::create([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'Retention completion boundary test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('7', 64),
    ]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->andReturnUsing(function ($model, $beforeSubmit, $afterResponse) use ($run) {
        $beforeSubmit();
        $afterResponse(['id' => 'resp_expired_during_analysis'], [
            'input_tokens' => 100,
            'cached_input_tokens' => 0,
            'output_tokens' => 30,
            'total_tokens' => 130,
        ]);

        $run->forceFill(['received_at' => now('UTC')->subDays(365)])->save();

        return [
            'summary' => 'Provider returned a result after the retention deadline.',
            'no_findings_reason' => 'No result may be retained after expiry.',
            'findings' => [],
            'response_id' => 'resp_expired_during_analysis',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30, 'total_tokens' => 130],
        ];
    });

    (new AnalyzeDiagnosticRun($run->id))->handle($analyzer, app(AiBudgetService::class));

    $attempt = AiUsageAttempt::query()->where('diagnostic_run_id', $run->id)->sole();

    expect($run->fresh()->status)->toBe('analysis_failed')
        ->and($run->fresh()->analysis_error_code)->toBe('diagnostic_retention_expired')
        ->and($run->fresh()->analysis_summary)->toBeNull()
        ->and(DiagnosticFinding::query()->where('diagnostic_run_id', $run->id)->count())->toBe(0)
        ->and($attempt->status)->toBe('settled');
});



it('refuses to analyze or mutate a run after its captured owner no longer matches', function () {
    $owner = User::factory()->create();
    $replacementOwner = User::factory()->create();
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'name' => 'Owner change during queue test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);
    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'queued',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('2', 64),
    ]);

    $job = new AnalyzeDiagnosticRun($run->id, $owner->id);
    $integration->forceFill(['owner_user_id' => $replacementOwner->id])->save();
    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldNotReceive('analyze');
    $budget = Mockery::mock(AiBudgetService::class);
    $budget->shouldNotReceive('reserve');

    $job->handle($analyzer, $budget);
    $job->failed(new RuntimeException('provider_request_failed'));

    expect($run->fresh()->status)->toBe('queued')
        ->and($run->fresh()->analysis_attempts)->toBe(0);
});

it('marks a failed analysis attempt without modifying the source diagnostic payload', function () {
    $integration = WhmcsIntegration::create([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'Analysis failure test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('c', 64),
    ]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->andThrow(new RuntimeException('provider_request_failed'));

    expect(fn () => (new AnalyzeDiagnosticRun($run->id))->handle($analyzer, app(AiBudgetService::class)))
        ->toThrow(RuntimeException::class, 'provider_request_failed');

    expect($run->fresh()->status)->toBe('analysis_failed')
        ->and($run->fresh()->analysis_error_code)->toBe('provider_request_failed')
        ->and($run->fresh()->payload)->toBe(['system' => ['whmcs_version' => '8.13.7']]);
});

it('reclaims an analysis lease after the queue retry visibility timeout', function () {
    $integration = WhmcsIntegration::create([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'Stale analysis lease test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'analyzing',
        'analysis_started_at' => now()->subSeconds(121),
        'analysis_attempts' => 1,
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('d', 64),
    ]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->with(
        Mockery::type(DiagnosticRun::class),
        Mockery::on(fn ($callback) => is_callable($callback)),
        Mockery::on(fn ($callback) => is_callable($callback)),
    )->andReturnUsing(function ($model, $beforeSubmit, $afterResponse) {
        $beforeSubmit();
        $afterResponse(['id' => 'resp_recovered_123'], [
            'input_tokens' => 100,
            'cached_input_tokens' => 0,
            'output_tokens' => 30,
            'total_tokens' => 130,
        ]);

        return [
            'summary' => 'The available snapshot contains limited runtime evidence.',
            'no_findings_reason' => 'No actionable issue is supported by the available evidence.',
            'findings' => [],
            'response_id' => 'resp_recovered_123',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30, 'total_tokens' => 130],
        ];
    });

    (new AnalyzeDiagnosticRun($run->id))->handle($analyzer, app(AiBudgetService::class));

    expect($run->fresh()->status)->toBe('analyzed')
        ->and($run->fresh()->analysis_attempts)->toBe(2);
});

it('does not start a duplicate analysis while the current worker lease is active', function () {
    $integration = WhmcsIntegration::create([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'Active analysis lease test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'analyzing',
        'analysis_started_at' => now()->subSeconds(30),
        'analysis_attempts' => 1,
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('e', 64),
    ]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldNotReceive('analyze');

    (new AnalyzeDiagnosticRun($run->id))->handle($analyzer, app(AiBudgetService::class));

    expect($run->fresh()->status)->toBe('analyzing')
        ->and($run->fresh()->analysis_attempts)->toBe(1);
});

it('keeps a submitted provider timeout ambiguous and blocks an automatic duplicate charge', function () {
    $integration = WhmcsIntegration::create([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'Ambiguous provider timeout test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('f', 64),
    ]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->andReturnUsing(function ($model, $beforeSubmit) {
        $beforeSubmit();

        throw new RuntimeException('provider_request_failed');
    });

    $job = new AnalyzeDiagnosticRun($run->id);
    $budget = app(AiBudgetService::class);

    expect(fn () => $job->handle($analyzer, $budget))
        ->toThrow(RuntimeException::class, 'provider_request_failed');

    $attempt = AiUsageAttempt::query()->where('diagnostic_run_id', $run->id)->sole();
    $periods = AiBudgetPeriod::query()->get();

    expect($attempt->status)->toBe('ambiguous')
        ->and($attempt->reserved_micro_usd)->toBeGreaterThan(0)
        ->and($periods)->toHaveCount(2);

    foreach ($periods as $period) {
        expect($period->reserved_micro_usd)->toBe($attempt->reserved_micro_usd)
            ->and($period->committed_micro_usd)->toBe(0);
    }

    expect(fn () => $job->handle($analyzer, $budget))
        ->toThrow(RuntimeException::class, 'budget_attempt_unresolved');

    expect(AiUsageAttempt::query()->where('diagnostic_run_id', $run->id)->count())->toBe(1)
        ->and($attempt->fresh()->status)->toBe('ambiguous')
        ->and($run->fresh()->status)->toBe('analysis_failed');
});


it('settles confirmed provider usage before surfacing an unsuccessful HTTP response', function () {
    $integration = WhmcsIntegration::create([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'Confirmed usage on provider error test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('a', 64),
    ]);

    config()->set('services.openai.key', 'test-provider-key');
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_confirmed_usage_error',
            'status' => 'failed',
            'usage' => [
                'input_tokens' => 100,
                'input_tokens_details' => ['cached_tokens' => 20],
                'output_tokens' => 30,
                'total_tokens' => 130,
            ],
            'error' => ['code' => 'provider_processing_error'],
        ], 500),
    ]);

    $settled = null;
    expect(fn () => app(WhmcsDiagnosticAnalyzer::class)->analyze(
        $run,
        null,
        function (array $body, array $usage) use (&$settled): void {
            $settled = [$body['id'], $usage];
        },
    ))->toThrow(RuntimeException::class, 'provider_request_failed');

    expect($settled)->toBe([
        'resp_confirmed_usage_error',
        [
            'input_tokens' => 100,
            'cached_input_tokens' => 20,
            'output_tokens' => 30,
            'total_tokens' => 130,
        ],
    ]);
});


it('does not analyze or mutate a run after its captured tenant assignment changes', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::create([
        'name' => 'Captured Tenant Context',
        'slug' => 'captured-tenant-context',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $replacementTenant = Tenant::create([
        'name' => 'Replacement Tenant Context',
        'slug' => 'replacement-tenant-context',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Tenant context reassignment test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);
    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'queued',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('e', 64),
    ]);

    $job = new AnalyzeDiagnosticRun($run->id, $owner->id, $tenant->id);
    $integration->forceFill(['tenant_id' => $replacementTenant->id])->save();
    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldNotReceive('analyze');
    $budget = Mockery::mock(AiBudgetService::class);
    $budget->shouldNotReceive('reserve');

    $job->handle($analyzer, $budget);
    $job->failed(new RuntimeException('provider_request_failed'));

    expect($run->fresh()->status)->toBe('queued')
        ->and($run->fresh()->analysis_attempts)->toBe(0);
});

it('does not start tenant analysis while the captured tenant is suspended', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::create([
        'name' => 'Suspended Analysis Tenant',
        'slug' => 'suspended-analysis-tenant',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Suspended tenant analysis test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);
    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'queued',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('f', 64),
    ]);

    $tenant->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldNotReceive('analyze');
    $budget = Mockery::mock(AiBudgetService::class);
    $budget->shouldNotReceive('reserve');

    (new AnalyzeDiagnosticRun($run->id, $owner->id, $tenant->id))->handle($analyzer, $budget);

    expect($run->fresh()->status)->toBe('queued')
        ->and($run->fresh()->analysis_attempts)->toBe(0);
});


it('denies tenant AI analysis without an active subscription feature entitlement before budget reservation', function () {
    $tenant = Tenant::create(['name' => 'Unentitled Analysis Tenant', 'slug' => 'unentitled-analysis-tenant', 'status' => Tenant::STATUS_ACTIVE]);
    $owner = User::factory()->create();
    $integration = WhmcsIntegration::create(['owner_user_id' => $owner->id, 'tenant_id' => $tenant->id, 'name' => 'Unentitled analysis integration', 'status' => 'active', 'secret' => bin2hex(random_bytes(32)), 'secret_version' => 1]);
    $run = DiagnosticRun::create(['integration_id' => $integration->id, 'tenant_id' => $tenant->id, 'snapshot_uuid' => (string) Str::uuid(), 'schema_version' => '1.0', 'addon_version' => '1.0.0', 'status' => 'received', 'collected_at' => now(), 'received_at' => now(), 'payload' => ['system' => ['whmcs_version' => '8.13.7']], 'payload_sha256' => str_repeat('d', 64)]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldNotReceive('analyze');
    $budget = Mockery::mock(AiBudgetService::class);
    $budget->shouldNotReceive('reserve');

    (new AnalyzeDiagnosticRun($run->id, $owner->id, $tenant->id))->handle($analyzer, $budget);

    expect($run->fresh()->status)->toBe('analysis_failed')
        ->and($run->fresh()->analysis_error_code)->toBe('tenant_entitlement_denied')
        ->and(AiUsageAttempt::query()->count())->toBe(0);
});
