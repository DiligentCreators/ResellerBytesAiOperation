<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\DeterministicFindingDetector;
use App\Services\Diagnostics\FindingPlaybookBuilder;
use App\Services\Diagnostics\Rules\IssueRuleRegistry;
use App\Services\Diagnostics\Rules\SecurityCve202667398Rule;
use App\Services\SafeActions\FindingSafeActionMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function loadSnapshotFixture(string $name): array
{
    $path = base_path('tests/Fixtures/snapshots/'.$name);
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    return $decoded;
}

function makeRuleIntegration(array $overrides = []): WhmcsIntegration
{
    return WhmcsIntegration::create(array_merge([
        'owner_user_id' => User::factory()->create()->id,
        'name' => 'Rule engine install',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.0.0',
    ], $overrides));
}

function makeRuleRun(WhmcsIntegration $integration, array $payload, string $schema = '1.1'): DiagnosticRun
{
    return DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $integration->tenant_id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => $schema,
        'addon_version' => $integration->addon_version ?? '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => $payload,
        'payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);
}

it('ensures every catalog entry has required fields and guided-only safety flags', function () {
    $catalog = config('whmcs_issue_catalog');
    expect($catalog)->toBeArray()->not->toBeEmpty();

    $required = [
        'title', 'category', 'severity', 'fix_mode', 'risk', 'never_auto', 'never_one_click',
        'action_keys', 'tier_entitlement', 'minutes_saved', 'why_it_matters', 'guided_steps',
        'whmcs_admin_path', 'priority',
    ];

    $guidedOnlyKeys = [
        'gateway.webhook_not_marking_paid',
        'upgrade.whmcs9_readiness',
        'security.patch_lag',
        'security.cve_2026_67398',
        'service.orphan',
        'whmcs.version_outdated',
    ];

    foreach ($catalog as $key => $entry) {
        expect($entry)->toBeArray();
        foreach ($required as $field) {
            expect($entry)->toHaveKey($field);
        }
        expect($entry['severity'])->toBeIn(['critical', 'warning', 'info']);
        expect($entry['fix_mode'])->toBeIn(['one_click', 'guided', 'info']);
        expect($entry['risk'])->toBeIn(['low', 'medium', 'high']);
        expect($entry['never_auto'])->toBeBool();
        expect($entry['never_one_click'])->toBeBool();
        expect($entry['action_keys'])->toBeArray();
        if ($entry['fix_mode'] === 'one_click') {
            expect($entry['action_keys'])->not->toBeEmpty();
        }
        expect($entry['guided_steps'])->toBeArray()->not->toBeEmpty();
        expect($entry['minutes_saved'])->toBeInt()->toBeGreaterThan(0);
        expect($entry['priority'])->toBeInt()->toBeGreaterThan(0);
        expect($entry['tier_entitlement'])->toBeString()->not->toBeEmpty();
    }

    expect($catalog['service.active_but_overdue']['fix_mode'])->toBe('guided')
        ->and($catalog['service.active_but_overdue']['action_keys'])->toBe([]);

    foreach ($guidedOnlyKeys as $key) {
        expect($catalog)->toHaveKey($key);
        expect($catalog[$key]['fix_mode'])->toBe('guided');
        expect($catalog[$key]['never_auto'])->toBeTrue();
        expect($catalog[$key]['never_one_click'])->toBeTrue();
    }

    $ruleKeys = collect(app(IssueRuleRegistry::class)->all())->map->key()->all();
    expect($ruleKeys)->toEqualCanonicalizing(array_keys($catalog));
});

it('detects positive schema 1.0 issues and ignores clean snapshots', function () {
    Carbon::setTestNow('2026-11-22T12:00:00Z');

    $integration = makeRuleIntegration(['addon_version' => '1.1.0']);
    $positive = makeRuleRun($integration, loadSnapshotFixture('schema_1_0_positive.json'), '1.0');
    $clean = makeRuleRun($integration, loadSnapshotFixture('schema_1_0_clean.json'), '1.0');

    $detector = app(DeterministicFindingDetector::class);
    $detector->detect($positive);
    $detector->detect($clean);

    $positiveKeys = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $positive->id)
        ->where('source', 'rule')
        ->pluck('issue_key')
        ->all();

    expect($positiveKeys)->toContain('php.display_errors_on')
        ->and($positiveKeys)->toContain('security.config_writable')
        ->and($positiveKeys)->toContain('php.extension_missing')
        ->and($positiveKeys)->toContain('php.version_unsupported')
        ->and($positiveKeys)->toContain('security.patch_lag')
        ->and($positiveKeys)->toContain('whmcs.version_outdated');

    $cleanKeys = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $clean->id)
        ->where('source', 'rule')
        ->pluck('issue_key')
        ->all();

    expect($cleanKeys)->not->toContain('php.display_errors_on')
        ->and($cleanKeys)->not->toContain('security.config_writable')
        ->and($cleanKeys)->not->toContain('php.extension_missing')
        ->and($cleanKeys)->not->toContain('php.version_unsupported');
});

it('detects schema 1.1 positives and skips findings when aggregate blocks are missing', function () {
    Carbon::setTestNow('2026-11-22T12:00:00Z');

    $integration = makeRuleIntegration(['addon_version' => '1.0.0']);
    config(['monitoring.addon_latest_version' => '1.1.0']);

    $positive = makeRuleRun($integration, loadSnapshotFixture('schema_1_1_positive.json'));
    $missing = makeRuleRun($integration, loadSnapshotFixture('schema_1_1_missing_blocks.json'));
    $clean = makeRuleRun($integration, loadSnapshotFixture('schema_1_1_clean.json'));

    $detector = app(DeterministicFindingDetector::class);
    $detector->detect($positive);
    $detector->detect($missing);
    $detector->detect($clean);

    $positiveKeys = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $positive->id)
        ->where('source', 'rule')
        ->pluck('issue_key')
        ->all();

    foreach ([
        'cron.daily_missed',
        'cron.daily_not_completing',
        'cron.overlap_duplicates',
        'module.queue_failed',
        'gateway.webhook_not_marking_paid',
        'gateway.callback_failures',
        'domain.sync_errors',
        'domain.renewal_failed',
        'domain.bad_expiry',
        'domain.active_expired',
        'upgrade.whmcs9_readiness',
        'security.cve_2026_67398',
        'invoice.not_generated',
        'orders.pending_paid',
        'service.suspended_but_paid',
        'service.active_but_overdue',
        'service.orphan',
        'invoice.overdue_no_reminder',
        'email.piping_failed',
        'email.sending_failed',
        'tickets.sla_breach',
        'security.admin_dir_default',
        'storage.disk_low',
        'db.size_growth',
        'addon.outdated',
        'security.patch_lag',
    ] as $key) {
        expect($positiveKeys)->toContain($key);
    }

    $schema11Only = [
        'module.queue_failed',
        'gateway.webhook_not_marking_paid',
        'tickets.sla_breach',
        'storage.disk_low',
        'security.admin_dir_default',
        'security.cve_2026_67398',
    ];

    $missingKeys = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $missing->id)
        ->where('source', 'rule')
        ->pluck('issue_key')
        ->all();

    foreach ($schema11Only as $key) {
        expect($missingKeys)->not->toContain($key);
    }

    $cleanKeys = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $clean->id)
        ->where('source', 'rule')
        ->pluck('issue_key')
        ->all();

    foreach ($schema11Only as $key) {
        expect($cleanKeys)->not->toContain($key);
    }
});

it('flags CVE-2026-67398 for 8.13.6 with 2CheckOut and not for patched baselines', function () {
    $rule = app(SecurityCve202667398Rule::class);
    $integration = makeRuleIntegration();

    $vulnerable = loadSnapshotFixture('schema_1_1_positive.json');
    expect($rule->detect($vulnerable, $integration))->not->toBeNull();

    $patched813 = $vulnerable;
    $patched813['system']['whmcs_version'] = '8.13.7';
    expect($rule->detect($patched813, $integration))->toBeNull();

    $patched908 = $vulnerable;
    $patched908['system']['whmcs_version'] = '9.0.8';
    expect($rule->detect($patched908, $integration))->toBeNull();

    $noGateway = $vulnerable;
    $noGateway['whmcs']['gateways']['active'] = ['paypal'];
    expect($rule->detect($noGateway, $integration))->toBeNull();

    $missingGateways = $vulnerable;
    unset($missingGateways['whmcs']['gateways']);
    expect($rule->detect($missingGateways, $integration))->toBeNull();
});

it('creates source=rule findings without calling OpenAI when AI is unconfigured', function () {
    config(['services.openai.key' => null]);
    Http::fake();

    Carbon::setTestNow('2026-11-22T12:00:00Z');
    $integration = makeRuleIntegration(['addon_version' => '1.0.0']);
    $run = makeRuleRun($integration, loadSnapshotFixture('schema_1_0_positive.json'), '1.0');

    app(DeterministicFindingDetector::class)->detect($run);

    $findings = DiagnosticFinding::query()->where('diagnostic_run_id', $run->id)->get();

    expect($findings)->not->toBeEmpty()
        ->and($findings->every(fn (DiagnosticFinding $finding): bool => $finding->source === 'rule'))->toBeTrue()
        ->and($findings->every(fn (DiagnosticFinding $finding): bool => filled($finding->issue_key)))->toBeTrue();

    Http::assertNothingSent();
});

it('keeps fingerprint continuity across two deterministic runs', function () {
    Carbon::setTestNow('2026-11-22T12:00:00Z');
    $integration = makeRuleIntegration();
    $payload = loadSnapshotFixture('schema_1_0_positive.json');

    $first = makeRuleRun($integration, $payload, '1.0');
    app(DeterministicFindingDetector::class)->detect($first, User::query()->find($integration->owner_user_id));

    $firstFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $first->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    $cleanPayload = loadSnapshotFixture('schema_1_0_clean.json');
    $second = makeRuleRun($integration, $cleanPayload, '1.0');
    app(DeterministicFindingDetector::class)->detect($second, User::query()->find($integration->owner_user_id));

    expect($firstFinding->fresh()->status)->toBe(DiagnosticFinding::STATUS_RESOLVED);

    $thirdPayload = $payload;
    $third = makeRuleRun($integration, $thirdPayload, '1.0');
    app(DeterministicFindingDetector::class)->detect($third, User::query()->find($integration->owner_user_id));

    $thirdFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $third->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    expect($thirdFinding->fingerprint)->toBe($firstFinding->fingerprint);
});

it('prefers catalog action keys and playbook copy when issue_key is set', function () {
    $integration = makeRuleIntegration();
    $run = makeRuleRun($integration, loadSnapshotFixture('schema_1_0_positive.json'), '1.0');
    app(DeterministicFindingDetector::class)->detect($run);

    $finding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $run->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    $actions = app(FindingSafeActionMapper::class)->suggestedActions($finding);
    expect(collect($actions)->pluck('key'))->toContain('disable_php_display_errors');

    $playbook = app(FindingPlaybookBuilder::class)->build($finding);
    $catalog = config('whmcs_issue_catalog')['php.display_errors_on'];
    expect($playbook['impact']['why_it_matters'])->toBe($catalog['why_it_matters'])
        ->and($playbook['safe_steps'][0]['action'])->toBe($catalog['guided_steps'][0])
        ->and($playbook['deep_links'][0]['path'])->toBe($catalog['whmcs_admin_path']);
});
