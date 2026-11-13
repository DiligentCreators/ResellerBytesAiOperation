<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRecommendation;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('reports old diagnostic runs and dependent records without deleting them', function () {
    Carbon::setTestNow('2026-11-14 12:00:00');

    try {
        $integration = WhmcsIntegration::create([
            'name' => 'Retention report fixture',
            'status' => 'active',
            'secret' => str_repeat('a', 64),
            'secret_version' => 1,
        ]);

        $oldRun = DiagnosticRun::create([
            'integration_id' => $integration->id,
            'snapshot_uuid' => (string) Str::uuid(),
            'schema_version' => '1.0',
            'addon_version' => '1.0.0',
            'status' => 'completed',
            'collected_at' => now()->subDays(400),
            'received_at' => now()->subDays(100),
            'payload' => [],
            'payload_sha256' => str_repeat('a', 64),
        ]);

        $recentRun = DiagnosticRun::create([
            'integration_id' => $integration->id,
            'snapshot_uuid' => (string) Str::uuid(),
            'schema_version' => '1.0',
            'addon_version' => '1.0.0',
            'status' => 'completed',
            'collected_at' => now()->subDays(10),
            'received_at' => now()->subDays(10),
            'payload' => [],
            'payload_sha256' => str_repeat('b', 64),
        ]);

        $finding = DiagnosticFinding::create([
            'diagnostic_run_id' => $oldRun->id,
            'fingerprint' => str_repeat('c', 64),
            'category' => 'configuration',
            'severity' => 'low',
            'title' => 'Old finding',
            'summary' => 'Retention report fixture',
            'evidence' => [],
            'confidence' => 0.9,
            'status' => 'open',
        ]);

        $recommendation = DiagnosticRecommendation::create([
            'diagnostic_finding_id' => $finding->id,
            'recommendation' => 'Review configuration',
            'rationale' => 'Retention report fixture',
            'risk_level' => 'low',
            'requires_manual_action' => true,
        ]);

        $this->artisan('diagnostics:retention:report')
            ->expectsOutputToContain('Candidate diagnostic runs: 1')
            ->expectsOutputToContain('Affected findings: 1')
            ->expectsOutputToContain('Affected recommendations: 1')
            ->expectsOutputToContain('REPORT ONLY')
            ->assertSuccessful();

        expect(DB::table('diagnostic_runs')->whereIn('id', [$oldRun->id, $recentRun->id])->count())->toBe(2)
            ->and(DB::table('diagnostic_findings')->where('id', $finding->id)->exists())->toBeTrue()
            ->and(DB::table('diagnostic_recommendations')->where('id', $recommendation->id)->exists())->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('uses an inclusive UTC receipt-time cutoff and leaves newer records out of the report', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00', 'UTC'));

    try {
        $integration = WhmcsIntegration::create([
            'name' => 'Retention boundary fixture',
            'status' => 'active',
            'secret' => str_repeat('d', 64),
            'secret_version' => 1,
        ]);

        $cutoffRun = DiagnosticRun::create([
            'integration_id' => $integration->id,
            'snapshot_uuid' => (string) Str::uuid(),
            'schema_version' => '1.0',
            'addon_version' => '1.0.0',
            'status' => 'completed',
            'collected_at' => now('UTC')->subDays(365),
            'received_at' => now('UTC')->subDays(90),
            'payload' => [],
            'payload_sha256' => str_repeat('e', 64),
        ]);

        $newerRun = DiagnosticRun::create([
            'integration_id' => $integration->id,
            'snapshot_uuid' => (string) Str::uuid(),
            'schema_version' => '1.0',
            'addon_version' => '1.0.0',
            'status' => 'completed',
            'collected_at' => now('UTC')->subDays(90)->addSecond(),
            'received_at' => now('UTC')->subDays(90)->addSecond(),
            'payload' => [],
            'payload_sha256' => str_repeat('f', 64),
        ]);

        $this->artisan('diagnostics:retention:report', ['--days' => '365'])
            ->expectsOutputToContain('Candidate diagnostic runs: 1')
            ->assertSuccessful();

        expect(DB::table('diagnostic_runs')->whereIn('id', [$cutoffRun->id, $newerRun->id])->count())->toBe(2);
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects an invalid retention report threshold', function () {
    $this->artisan('diagnostics:retention:report', ['--days' => '0'])
        ->expectsOutputToContain('between 1 and 36500')
        ->assertFailed();
});
