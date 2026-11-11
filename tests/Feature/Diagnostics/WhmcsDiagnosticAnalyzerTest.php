<?php

use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\WhmcsDiagnosticAnalyzer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

function makeDiagnosticRunForAiTest(array $payload = []): DiagnosticRun
{
    $integration = WhmcsIntegration::create([
        'name' => 'AI analyzer test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    return DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => array_replace_recursive([
            'system' => [
                'whmcs_version' => '8.13.7',
                'web_php_version' => '8.1.34',
                'error_display_disabled' => true,
            ],
            'warnings' => [
                ['code' => 'configuration_file_writable', 'severity' => 'warning'],
            ],
        ], $payload),
        'payload_sha256' => str_repeat('a', 64),
    ]);
}

function openAiResponseForAnalysis(array $analysis): array
{
    return [
        'id' => 'resp_test_123',
        'status' => 'completed',
        'output' => [
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => json_encode($analysis, JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 1200,
            'output_tokens' => 400,
            'total_tokens' => 1600,
        ],
    ];
}

beforeEach(function () {
    config([
        'services.openai.key' => 'test-api-key',
        'services.openai.model' => 'gpt-5.6-sol',
        'services.openai.timeout' => 45,
    ]);
});

it('requests strict structured analysis without storing the response remotely', function () {
    $run = makeDiagnosticRunForAiTest();
    $analysis = [
        'summary' => 'The snapshot shows a writable WHMCS configuration file.',
        'no_findings_reason' => null,
        'findings' => [
            [
                'category' => 'security',
                'severity' => 'warning',
                'title' => 'WHMCS configuration file is writable',
                'summary' => 'The runtime can write to configuration.php.',
                'evidence_paths' => ['warnings.0.code'],
                'confidence' => 0.98,
                'recommendations' => [
                    [
                        'recommendation' => 'Review file ownership and permissions before applying a least-privilege mode.',
                        'rationale' => 'The collector observed that the configuration file is writable by the PHP runtime.',
                        'risk_level' => 'medium',
                    ],
                ],
            ],
        ],
    ];

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(openAiResponseForAnalysis($analysis), 200),
    ]);

    $result = app(WhmcsDiagnosticAnalyzer::class)->analyze($run);

    expect($result['summary'])->toContain('writable')
        ->and($result['findings'])->toHaveCount(1)
        ->and($result['findings'][0]['evidence_paths'])->toBe(['warnings.0.code'])
        ->and($result['response_id'])->toBe('resp_test_123')
        ->and($result['usage']['total_tokens'])->toBe(1600);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request['model'] === 'gpt-5.6-sol'
            && $request['store'] === false
            && $request['text']['format']['type'] === 'json_schema'
            && $request['text']['format']['strict'] === true;
    });
});

it('rejects model findings that cite evidence paths absent from the snapshot', function () {
    $run = makeDiagnosticRunForAiTest();
    $analysis = [
        'summary' => 'An issue was detected.',
        'no_findings_reason' => null,
        'findings' => [
            [
                'category' => 'security',
                'severity' => 'critical',
                'title' => 'Invented issue',
                'summary' => 'This is not supported by the snapshot.',
                'evidence_paths' => ['system.customer_database_password'],
                'confidence' => 0.99,
                'recommendations' => [],
            ],
        ],
    ];

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(openAiResponseForAnalysis($analysis), 200),
    ]);

    expect(fn () => app(WhmcsDiagnosticAnalyzer::class)->analyze($run))
        ->toThrow(RuntimeException::class, 'provider_evidence_reference_invalid');
});

it('reports an explicit no-findings reason when the snapshot contains no actionable issue', function () {
    $run = makeDiagnosticRunForAiTest();
    $analysis = [
        'summary' => 'No actionable issue is supported by the available signals.',
        'no_findings_reason' => 'The snapshot contains only limited runtime signals and no warning indicators.',
        'findings' => [],
    ];

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(openAiResponseForAnalysis($analysis), 200),
    ]);

    $result = app(WhmcsDiagnosticAnalyzer::class)->analyze($run);

    expect($result['findings'])->toBeEmpty()
        ->and($result['no_findings_reason'])->toContain('limited runtime signals');
});

it('does not call the provider when no API key is configured', function () {
    config(['services.openai.key' => null]);
    Http::preventStrayRequests();

    expect(fn () => app(WhmcsDiagnosticAnalyzer::class)->analyze(makeDiagnosticRunForAiTest()))
        ->toThrow(RuntimeException::class, 'provider_not_configured');
});
