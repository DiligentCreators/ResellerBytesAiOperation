<?php

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRecommendation;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\AiBudgetService;
use App\Services\Diagnostics\DeterministicFindingDetector;
use App\Services\Diagnostics\FindingContinuityService;
use App\Services\Diagnostics\Rules\PhpVersionEolRule;
use App\Services\Diagnostics\WhmcsDiagnosticAnalyzer;
use App\Services\Diagnostics\WhmcsIssueCatalog;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function makeDedupeWorkspace(array $payload = []): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Dedupe Workspace',
        'slug' => 'dedupe-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'status' => 'active',
        'plan_code' => 'growth',
        'billing_mode' => 'manual',
        'currency' => 'USD',
        'unit_amount_minor' => 14900,
        'entitlements' => [
            'diagnostics.analysis' => true,
            'safe_actions.enabled' => true,
            'fixes.guided' => true,
            'fixes.one_click' => true,
            'fixes.bulk' => true,
            'limits' => ['integrations' => 5, 'members' => 10, 'diagnostics_per_period' => 100, 'retention_days' => 30],
        ],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Dedupe WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.1.0',
    ]);

    $defaultPayload = [
        'system' => [
            'whmcs_version' => '8.13.7',
            'web_php_version' => '8.1.34',
            'error_display_disabled' => false,
            'configuration_file_writable' => false,
        ],
    ];

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => array_replace_recursive($defaultPayload, $payload),
        'payload_sha256' => hash('sha256', 'dedupe-payload-'.uniqid()),
        'analysis_summary' => 'ok',
    ]);

    return compact('owner', 'tenant', 'integration', 'run');
}

it('maps two AI PHP error-display titles onto one Fix Center issue', function () {
    $workspace = makeDedupeWorkspace([
        'system' => [
            'error_display_disabled' => true,
            'web_php_version' => '8.3.0',
        ],
    ]);
    $continuity = app(FindingContinuityService::class);

    $first = $continuity->resolveAiFindingIdentity([
        'category' => 'security',
        'title' => 'PHP error display appears enabled',
        'summary' => 'Errors may leak paths.',
        'evidence_paths' => ['system.error_display_disabled'],
        'severity' => 'warning',
        'confidence' => 0.9,
        'recommendations' => [],
    ]);
    $second = $continuity->resolveAiFindingIdentity([
        'category' => 'php',
        'title' => 'PHP error display is enabled',
        'summary' => 'Display errors is on.',
        'evidence_paths' => ['system.error_display_disabled'],
        'severity' => 'warning',
        'confidence' => 0.85,
        'recommendations' => [],
    ]);

    expect($first['issue_key'])->toBe('php.display_errors_on')
        ->and($second['issue_key'])->toBe('php.display_errors_on')
        ->and($first['fingerprint'])->toBe($second['fingerprint'])
        ->and($first['finding_data']['title'])->toBe(WhmcsIssueCatalog::getString('php.display_errors_on', 'title'));

    // Without mapping these titles/categories would diverge; after mapping they share one fingerprint.
    $legacyFirst = $continuity->fingerprintFor([
        'category' => 'security',
        'title' => 'PHP error display appears enabled',
        'evidence_paths' => ['system.error_display_disabled'],
    ]);
    $legacySecond = $continuity->fingerprintFor([
        'category' => 'php',
        'title' => 'PHP error display is enabled',
        'evidence_paths' => ['system.error_display_disabled'],
    ]);
    expect($legacyFirst)->not->toBe($legacySecond);

    $priorRun = DiagnosticRun::create([
        'integration_id' => $workspace['integration']->id,
        'tenant_id' => $workspace['tenant']->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now()->subHour(),
        'received_at' => now()->subHour(),
        'payload' => $workspace['run']->payload,
        'payload_sha256' => hash('sha256', 'prior-display-errors'),
        'analysis_summary' => 'prior',
    ]);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $priorRun->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => $first['fingerprint'],
        'issue_key' => $first['issue_key'],
        'source' => DiagnosticFinding::SOURCE_AI,
        'category' => $first['finding_data']['category'],
        'severity' => 'warning',
        'title' => $first['finding_data']['title'],
        'summary' => $first['finding_data']['summary'],
        'evidence' => ['system.error_display_disabled' => false],
        'confidence' => 0.9,
        'status' => DiagnosticFinding::STATUS_OPEN,
        'created_at' => now()->subHour(),
    ]);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => $second['fingerprint'],
        'issue_key' => $second['issue_key'],
        'source' => DiagnosticFinding::SOURCE_AI,
        'category' => $second['finding_data']['category'],
        'severity' => 'warning',
        'title' => $second['finding_data']['title'],
        'summary' => $second['finding_data']['summary'],
        'evidence' => ['system.error_display_disabled' => false],
        'confidence' => 0.85,
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=open')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fix-center/index')
            ->where('counts.open', 1)
            ->has('issues.data', 1)
            ->where('issues.data.0.title', WhmcsIssueCatalog::getString('php.display_errors_on', 'title')));
});

it('maps two AI EOL PHP findings with divergent category and evidence onto one issue', function () {
    $workspace = makeDedupeWorkspace([
        'system' => [
            'error_display_disabled' => true,
            'web_php_version' => '8.3.0',
        ],
    ]);
    $continuity = app(FindingContinuityService::class);

    $first = $continuity->resolveAiFindingIdentity([
        'category' => 'runtime',
        'title' => 'Web runtime uses end-of-life PHP 8.1 (8.1.34)',
        'summary' => 'PHP 8.1 is EOL.',
        'evidence_paths' => ['system.web_php_version', 'php.memory_limit'],
        'severity' => 'critical',
        'confidence' => 0.95,
        'recommendations' => [],
    ]);
    $second = $continuity->resolveAiFindingIdentity([
        'category' => 'php',
        'title' => 'Web runtime uses end-of-life PHP 8.1 (8.1.34)',
        'summary' => 'Upgrade PHP.',
        'evidence_paths' => ['system.web_php_version'],
        'severity' => 'critical',
        'confidence' => 0.92,
        'recommendations' => [],
    ]);

    expect($first['issue_key'])->toBe('php.version_eol')
        ->and($second['issue_key'])->toBe('php.version_eol')
        ->and($first['fingerprint'])->toBe($second['fingerprint']);

    $legacyFirst = $continuity->fingerprintFor([
        'category' => 'runtime',
        'title' => 'Web runtime uses end-of-life PHP 8.1 (8.1.34)',
        'evidence_paths' => ['system.web_php_version', 'php.memory_limit'],
    ]);
    $legacySecond = $continuity->fingerprintFor([
        'category' => 'php',
        'title' => 'Web runtime uses end-of-life PHP 8.1 (8.1.34)',
        'evidence_paths' => ['system.web_php_version'],
    ]);
    expect($legacyFirst)->not->toBe($legacySecond);

    $priorRun = DiagnosticRun::create([
        'integration_id' => $workspace['integration']->id,
        'tenant_id' => $workspace['tenant']->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now()->subHour(),
        'received_at' => now()->subHour(),
        'payload' => $workspace['run']->payload,
        'payload_sha256' => hash('sha256', 'prior-eol'),
        'analysis_summary' => 'prior',
    ]);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $priorRun->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => $first['fingerprint'],
        'issue_key' => $first['issue_key'],
        'source' => DiagnosticFinding::SOURCE_AI,
        'category' => $first['finding_data']['category'],
        'severity' => 'critical',
        'title' => $first['finding_data']['title'],
        'summary' => $first['finding_data']['summary'],
        'evidence' => ['system.web_php_version' => '8.1.34'],
        'confidence' => 0.95,
        'status' => DiagnosticFinding::STATUS_OPEN,
        'created_at' => now()->subHour(),
    ]);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => $second['fingerprint'],
        'issue_key' => $second['issue_key'],
        'source' => DiagnosticFinding::SOURCE_AI,
        'category' => $second['finding_data']['category'],
        'severity' => 'critical',
        'title' => $second['finding_data']['title'],
        'summary' => $second['finding_data']['summary'],
        'evidence' => ['system.web_php_version' => '8.1.34'],
        'confidence' => 0.92,
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=open')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fix-center/index')
            ->where('counts.open', 1)
            ->has('issues.data', 1));
});

it('persists only one AI row when AnalyzeDiagnosticRun receives duplicate mapped titles', function () {
    $owner = User::factory()->create();
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'name' => 'AI title dedupe',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $payload = [
        'system' => [
            'whmcs_version' => '8.13.7',
            'web_php_version' => '8.3.0',
            'error_display_disabled' => true,
            'configuration_file_writable' => false,
        ],
        'php' => [
            'extensions' => [
                'curl' => true,
                'json' => true,
                'mbstring' => true,
                'openssl' => true,
                'pdo' => true,
                'pdo_mysql' => true,
                'xml' => true,
                'gd' => true,
                'iconv' => true,
                'zip' => true,
                'zlib' => true,
                'fileinfo' => true,
                'ioncube_loader' => true,
            ],
        ],
    ];

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => $payload,
        'payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->andReturnUsing(function ($model, $beforeSubmit, $afterResponse) {
        $beforeSubmit();
        $afterResponse(['id' => 'resp_dedupe_titles'], [
            'input_tokens' => 10,
            'cached_input_tokens' => 0,
            'output_tokens' => 5,
            'total_tokens' => 15,
        ]);

        return [
            'summary' => 'Error display findings.',
            'no_findings_reason' => null,
            'findings' => [
                [
                    'category' => 'security',
                    'severity' => 'warning',
                    'title' => 'PHP error display appears enabled',
                    'summary' => 'First AI wording.',
                    'evidence_paths' => ['system.error_display_disabled'],
                    'confidence' => 0.9,
                    'recommendations' => [
                        [
                            'recommendation' => 'Disable display_errors.',
                            'rationale' => 'Leak risk',
                            'risk_level' => 'medium',
                        ],
                    ],
                ],
                [
                    'category' => 'php',
                    'severity' => 'warning',
                    'title' => 'PHP error display is enabled',
                    'summary' => 'Second AI wording.',
                    'evidence_paths' => ['system.error_display_disabled'],
                    'confidence' => 0.85,
                    'recommendations' => [
                        [
                            'recommendation' => 'Disable display_errors.',
                            'rationale' => 'Leak risk',
                            'risk_level' => 'medium',
                        ],
                    ],
                ],
            ],
            'response_id' => 'resp_dedupe_titles',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ];
    });

    (new AnalyzeDiagnosticRun($run->id, (int) $integration->owner_user_id))->handle($analyzer, app(AiBudgetService::class));

    // error_display_disabled is true so the rule does not fire; only the mapped AI row remains.
    $findings = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $run->id)
        ->where('issue_key', 'php.display_errors_on')
        ->get();

    expect($findings)->toHaveCount(1)
        ->and($findings->first()->source)->toBe(DiagnosticFinding::SOURCE_AI)
        ->and($findings->first()->title)->toBe(WhmcsIssueCatalog::getString('php.display_errors_on', 'title'));
});

it('keeps the rule finding when AI and rule both cover php.display_errors_on in the same run', function () {
    $workspace = makeDedupeWorkspace([
        'system' => [
            'error_display_disabled' => false,
            'web_php_version' => '8.3.0',
        ],
    ]);
    $continuity = app(FindingContinuityService::class);
    $identity = $continuity->resolveAiFindingIdentity([
        'category' => 'security',
        'title' => 'PHP error display appears enabled',
        'summary' => 'AI noticed display_errors is on.',
        'evidence_paths' => ['system.error_display_disabled'],
        'severity' => 'warning',
        'confidence' => 0.88,
        'recommendations' => [],
    ]);

    $aiFinding = DiagnosticFinding::create([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => $identity['fingerprint'],
        'issue_key' => $identity['issue_key'],
        'source' => DiagnosticFinding::SOURCE_AI,
        'category' => $identity['finding_data']['category'],
        'severity' => 'warning',
        'title' => $identity['finding_data']['title'],
        'summary' => $identity['finding_data']['summary'],
        'evidence' => ['system.error_display_disabled' => false],
        'confidence' => 0.88,
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);
    DiagnosticRecommendation::create([
        'diagnostic_finding_id' => $aiFinding->id,
        'tenant_id' => $workspace['tenant']->id,
        'recommendation' => 'Turn off display_errors.',
        'rationale' => 'Leak risk',
        'risk_level' => 'medium',
        'requires_manual_action' => true,
    ]);

    app(DeterministicFindingDetector::class)->detect($workspace['run']);

    $findings = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $workspace['run']->id)
        ->where('issue_key', 'php.display_errors_on')
        ->get();

    expect($findings)->toHaveCount(1)
        ->and($findings->first()->source)->toBe(DiagnosticFinding::SOURCE_RULE)
        ->and($findings->first()->isActive())->toBeTrue()
        ->and(DiagnosticFinding::query()->whereKey($aiFinding->id)->exists())->toBeFalse();
});

it('keeps the legacy fingerprint for unmapped AI findings', function () {
    $continuity = app(FindingContinuityService::class);
    $findingData = [
        'category' => 'billing',
        'title' => 'Unusual invoice backlog pattern',
        'summary' => 'Custom AI-only observation.',
        'evidence_paths' => ['whmcs.invoices.custom_signal'],
        'severity' => 'info',
        'confidence' => 0.7,
        'recommendations' => [],
    ];

    $identity = $continuity->resolveAiFindingIdentity($findingData);
    $legacy = $continuity->fingerprintFor($continuity->stabilizeFindingData($findingData));

    expect($identity['issue_key'])->toBeNull()
        ->and($identity['fingerprint'])->toBe($legacy);
});

it('fires PhpVersionEolRule for 8.1.34 and not for 8.3.x', function () {
    $rule = app(PhpVersionEolRule::class);
    $integration = WhmcsIntegration::create([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'EOL rule install',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $eol = $rule->detect([
        'system' => ['web_php_version' => '8.1.34'],
    ], $integration);
    $supported = $rule->detect([
        'system' => ['web_php_version' => '8.3.12'],
    ], $integration);

    expect($eol)->not->toBeNull()
        ->and($eol?->key)->toBe('php.version_eol')
        ->and($eol?->evidence)->toHaveKey('system.web_php_version')
        ->and($supported)->toBeNull();
});

it('skips creating a mapped AI finding when AnalyzeDiagnosticRun already has the rule row', function () {
    $owner = User::factory()->create();
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'name' => 'Rule-first AI skip',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $payload = [
        'system' => [
            'whmcs_version' => '8.13.7',
            'web_php_version' => '8.3.0',
            'error_display_disabled' => false,
            'configuration_file_writable' => false,
        ],
        'php' => [
            'extensions' => [
                'curl' => true,
                'json' => true,
                'mbstring' => true,
                'openssl' => true,
                'pdo' => true,
                'pdo_mysql' => true,
                'xml' => true,
                'gd' => true,
                'iconv' => true,
                'zip' => true,
                'zlib' => true,
                'fileinfo' => true,
                'ioncube_loader' => true,
            ],
        ],
    ];

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => $payload,
        'payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);

    $analyzer = Mockery::mock(WhmcsDiagnosticAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->andReturnUsing(function ($model, $beforeSubmit, $afterResponse) {
        $beforeSubmit();
        $afterResponse(['id' => 'resp_dedupe_1'], [
            'input_tokens' => 10,
            'cached_input_tokens' => 0,
            'output_tokens' => 5,
            'total_tokens' => 15,
        ]);

        return [
            'summary' => 'Error display is enabled.',
            'no_findings_reason' => null,
            'findings' => [
                [
                    'category' => 'security',
                    'severity' => 'warning',
                    'title' => 'PHP error display appears enabled',
                    'summary' => 'AI summary for display errors.',
                    'evidence_paths' => ['system.error_display_disabled'],
                    'confidence' => 0.9,
                    'recommendations' => [
                        [
                            'recommendation' => 'Disable display_errors.',
                            'rationale' => 'Leak risk',
                            'risk_level' => 'medium',
                        ],
                    ],
                ],
            ],
            'response_id' => 'resp_dedupe_1',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ];
    });

    (new AnalyzeDiagnosticRun($run->id, (int) $integration->owner_user_id))->handle($analyzer, app(AiBudgetService::class));

    $displayFindings = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $run->id)
        ->where('issue_key', 'php.display_errors_on')
        ->get();

    expect($displayFindings)->toHaveCount(1)
        ->and($displayFindings->first()->source)->toBe(DiagnosticFinding::SOURCE_RULE)
        ->and($displayFindings->first()->isActive())->toBeTrue();
});
