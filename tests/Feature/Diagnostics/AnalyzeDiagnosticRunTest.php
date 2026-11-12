<?php

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\AiBudgetService;
use App\Services\Diagnostics\WhmcsDiagnosticAnalyzer;
use Illuminate\Support\Str;
use RuntimeException;

it('persists validated AI findings and recommendations against their source snapshot', function () {
    $integration = WhmcsIntegration::create([
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

    (new AnalyzeDiagnosticRun($run->id))->handle($analyzer, app(AiBudgetService::class));

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

it('marks a failed analysis attempt without modifying the source diagnostic payload', function () {
    $integration = WhmcsIntegration::create([
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
