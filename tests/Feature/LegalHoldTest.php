<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRecommendation;
use App\Models\DiagnosticRun;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use App\Services\LegalHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function legalHoldOwner(string $email = 'owner@example.test'): User
{
    return User::forceCreate([
        'name' => 'Project Owner',
        'email' => $email,
        'password' => Hash::make('Strong-test-password-123!'),
        'email_verified_at' => now(),
        'two_factor_secret' => Crypt::encryptString('test-totp-secret'),
        'two_factor_confirmed_at' => now(),
    ]);
}

function legalHoldDiagnosticRun(): DiagnosticRun
{
    $integration = WhmcsIntegration::create([
        'name' => 'Legal hold fixture',
        'status' => 'active',
        'secret' => str_repeat('a', 64),
        'secret_version' => 1,
    ]);

    return DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'completed',
        'collected_at' => now()->subDays(400),
        'received_at' => now()->subDays(400),
        'payload' => [],
        'payload_sha256' => str_repeat('b', 64),
    ]);
}

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    config(['operations.owner_email' => 'owner@example.test']);
});

it('allows only the configured verified MFA owner to place a hold and appends an audit event', function () {
    $owner = legalHoldOwner();
    $run = legalHoldDiagnosticRun();

    $hold = app(LegalHoldService::class)->place(
        $owner,
        'diagnostic_run',
        $run->id,
        'Preserve records for a formal legal matter',
        'CASE-2026-0042',
    );

    expect($hold->status)->toBe('active')
        ->and(DB::table('audit_events')->where('event_type', 'legal_hold.placed')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('event_type', 'legal_hold.placed')->value('actor_id'))->toBe((string) $owner->id);
});

it('rejects a user whose email does not match the configured Owner identity', function () {
    $owner = legalHoldOwner('other@example.test');
    $run = legalHoldDiagnosticRun();

    expect(fn () => app(LegalHoldService::class)->place(
        $owner,
        'diagnostic_run',
        $run->id,
        'Preserve records for a formal legal matter',
        'CASE-2026-0042',
    ))->toThrow(RuntimeException::class, 'legal_hold_owner_not_authorized');

    expect(DB::table('legal_holds')->count())->toBe(0)
        ->and(DB::table('audit_events')->where('event_type', 'like', 'legal_hold.%')->count())->toBe(0);
});

it('fails closed when the Owner email configuration is missing', function () {
    config(['operations.owner_email' => null]);
    $owner = legalHoldOwner();
    $run = legalHoldDiagnosticRun();

    expect(fn () => app(LegalHoldService::class)->place(
        $owner,
        'diagnostic_run',
        $run->id,
        'Preserve records for a formal legal matter',
        'CASE-2026-0042',
    ))->toThrow(RuntimeException::class, 'legal_hold_owner_not_authorized');
});

it('propagates a run hold to findings and recommendations and clears eligibility only after audited release', function () {
    $owner = legalHoldOwner();
    $run = legalHoldDiagnosticRun();
    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'fingerprint' => str_repeat('c', 64),
        'category' => 'configuration',
        'severity' => 'low',
        'title' => 'Held finding',
        'summary' => 'Legal hold test fixture',
        'evidence' => [],
        'confidence' => 0.9,
        'status' => 'open',
    ]);

    $service = app(LegalHoldService::class);
    $hold = $service->place($owner, 'diagnostic_run', $run->id, 'Preserve records for a formal legal matter', 'CASE-2026-0042');

    expect($service->isHeld('diagnostic_run', $run->id))->toBeTrue()
        ->and($service->isHeld('diagnostic_finding', $finding->id))->toBeTrue();

    $released = $service->release($owner, $hold->id, 'Formal matter closed and release approved', 'CASE-2026-0042-CLOSE');

    expect($released->status)->toBe('released')
        ->and($service->isHeld('diagnostic_run', $run->id))->toBeFalse()
        ->and(DB::table('audit_events')->whereIn('event_type', ['legal_hold.placed', 'legal_hold.released'])->count())->toBe(2)
        ->and(DB::table('legal_holds')->whereNotNull('released_by_user_id')->value('released_by_user_id'))->toBe($owner->id);
});

it('rolls back a hold if its immutable audit event cannot be appended', function () {
    $owner = legalHoldOwner();
    $run = legalHoldDiagnosticRun();

    $writer = Mockery::mock(AuditEventWriter::class);
    $writer->shouldReceive('append')->once()->andThrow(new RuntimeException('audit_write_failed'));
    app()->instance(AuditEventWriter::class, $writer);

    expect(fn () => app(LegalHoldService::class)->place(
        $owner,
        'diagnostic_run',
        $run->id,
        'Preserve records for a formal legal matter',
        'CASE-2026-0042',
    ))->toThrow(RuntimeException::class, 'audit_write_failed');

    expect(DB::table('legal_holds')->count())->toBe(0);
});

it('keeps the diagnostic cleanup command dry-run by default and refuses execution while disabled', function () {
    $run = legalHoldDiagnosticRun();

    $this->artisan('diagnostics:retention:prune')
        ->expectsOutputToContain('DRY RUN ONLY')
        ->expectsOutputToContain('Eligible diagnostic runs: 1')
        ->assertSuccessful();

    expect(DB::table('diagnostic_runs')->where('id', $run->id)->exists())->toBeTrue();

    $this->artisan('diagnostics:retention:prune', ['--execute' => true])
        ->expectsOutputToContain('Diagnostic cleanup is disabled')
        ->assertFailed();

    expect(DB::table('diagnostic_runs')->where('id', $run->id)->exists())->toBeTrue();
});

it('deletes only explicitly authorized expired diagnostic runs and preserves runs protected by descendant holds', function () {
    $owner = legalHoldOwner();
    $heldRun = legalHoldDiagnosticRun();
    $eligibleRun = legalHoldDiagnosticRun();
    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $heldRun->id,
        'fingerprint' => str_repeat('d', 64),
        'category' => 'configuration',
        'severity' => 'low',
        'title' => 'Held child finding',
        'summary' => 'Cleanup guard fixture',
        'evidence' => [],
        'confidence' => 0.9,
        'status' => 'open',
    ]);

    app(LegalHoldService::class)->place(
        $owner,
        'diagnostic_finding',
        $finding->id,
        'Preserve this finding for a formal legal matter',
        'CASE-2026-0043',
    );

    config(['diagnostics.retention_cleanup_enabled' => true]);

    $this->artisan('diagnostics:retention:prune', ['--execute' => true])
        ->expectsOutputToContain('Deleted 1 expired diagnostic run(s); protected 1 by active legal hold(s).')
        ->assertSuccessful();

    expect(DB::table('audit_events')->where('event_type', 'diagnostics.retention.deleted')->count())->toBe(1);

    expect(DB::table('diagnostic_runs')->where('id', $heldRun->id)->exists())->toBeTrue()
        ->and(DB::table('diagnostic_findings')->where('id', $finding->id)->exists())->toBeTrue()
        ->and(DB::table('diagnostic_runs')->where('id', $eligibleRun->id)->exists())->toBeFalse();
});

it('fails closed and leaves expired records untouched when hold-state evaluation fails', function () {
    $run = legalHoldDiagnosticRun();
    config(['diagnostics.retention_cleanup_enabled' => true]);

    $holds = Mockery::mock(LegalHoldService::class);
    $holds->shouldReceive('isHeld')->once()->andThrow(new RuntimeException('hold_state_unavailable'));
    app()->instance(LegalHoldService::class, $holds);

    expect(fn () => $this->artisan('diagnostics:retention:prune', ['--execute' => true]))
        ->toThrow(RuntimeException::class, 'hold_state_unavailable');

    expect(DB::table('diagnostic_runs')->where('id', $run->id)->exists())->toBeTrue();
});

it('rolls back legal hold release when its immutable audit event cannot be appended', function () {
    $owner = legalHoldOwner();
    $run = legalHoldDiagnosticRun();
    $hold = app(LegalHoldService::class)->place(
        $owner,
        'diagnostic_run',
        $run->id,
        'Preserve records for a formal legal matter',
        'CASE-2026-0044',
    );

    $writer = Mockery::mock(AuditEventWriter::class);
    $writer->shouldReceive('append')->once()->andThrow(new RuntimeException('audit_write_failed'));
    app()->instance(AuditEventWriter::class, $writer);

    expect(fn () => app(LegalHoldService::class)->release(
        $owner,
        $hold->id,
        'Formal matter closed and release approved',
        'CASE-2026-0044-CLOSE',
    ))->toThrow(RuntimeException::class, 'audit_write_failed');

    expect(DB::table('legal_holds')->where('id', $hold->id)->value('status'))->toBe('active');
});

it('rolls back diagnostic deletion when its immutable audit event cannot be appended', function () {
    $run = legalHoldDiagnosticRun();
    config(['diagnostics.retention_cleanup_enabled' => true]);

    $writer = Mockery::mock(AuditEventWriter::class);
    $writer->shouldReceive('append')->once()->andThrow(new RuntimeException('audit_write_failed'));
    app()->instance(AuditEventWriter::class, $writer);

    expect(fn () => $this->artisan('diagnostics:retention:prune', ['--execute' => true]))
        ->toThrow(RuntimeException::class, 'audit_write_failed');

    expect(DB::table('diagnostic_runs')->where('id', $run->id)->exists())->toBeTrue();
});

it('uses an inclusive UTC cutoff for cleanup and excludes active diagnostic runs', function () {
    \Illuminate\Support\Carbon::setTestNow('2026-11-14 12:00:00');

    try {
        $boundaryRun = legalHoldDiagnosticRun();
        $activeRun = legalHoldDiagnosticRun();
        $recentRun = legalHoldDiagnosticRun();

        DB::table('diagnostic_runs')->where('id', $boundaryRun->id)->update([
            'received_at' => now('UTC')->subDays(365)->format('Y-m-d H:i:s'),
        ]);
        DB::table('diagnostic_runs')->where('id', $activeRun->id)->update([
            'status' => 'analyzing',
            'received_at' => now('UTC')->subDays(400)->format('Y-m-d H:i:s'),
        ]);
        DB::table('diagnostic_runs')->where('id', $recentRun->id)->update([
            'received_at' => now('UTC')->subDays(365)->addSecond()->format('Y-m-d H:i:s'),
        ]);

        config(['diagnostics.retention_cleanup_enabled' => true]);

        $this->artisan('diagnostics:retention:prune', ['--execute' => true])
            ->expectsOutputToContain('Deleted 1 expired diagnostic run(s); protected 0 by active legal hold(s).')
            ->assertSuccessful();

        expect(DB::table('diagnostic_runs')->where('id', $boundaryRun->id)->exists())->toBeFalse()
            ->and(DB::table('diagnostic_runs')->where('id', $activeRun->id)->exists())->toBeTrue()
            ->and(DB::table('diagnostic_runs')->where('id', $recentRun->id)->exists())->toBeTrue();
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }
});

it('propagates a recommendation hold to its finding and parent diagnostic run', function () {
    $owner = legalHoldOwner();
    $run = legalHoldDiagnosticRun();
    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'fingerprint' => str_repeat('e', 64),
        'category' => 'configuration',
        'severity' => 'low',
        'title' => 'Recommendation-held finding',
        'summary' => 'Legal hold descendant fixture',
        'evidence' => [],
        'confidence' => 0.9,
        'status' => 'open',
    ]);
    $recommendation = DiagnosticRecommendation::create([
        'diagnostic_finding_id' => $finding->id,
        'recommendation' => 'Preserve this recommendation',
        'rationale' => 'Legal hold descendant fixture',
        'risk_level' => 'low',
        'requires_manual_action' => true,
    ]);

    $service = app(LegalHoldService::class);
    $service->place(
        $owner,
        'diagnostic_recommendation',
        $recommendation->id,
        'Preserve this recommendation for a formal legal matter',
        'CASE-2026-0045',
    );

    expect($service->isHeld('diagnostic_recommendation', $recommendation->id))->toBeTrue()
        ->and($service->isHeld('diagnostic_finding', $finding->id))->toBeTrue()
        ->and($service->isHeld('diagnostic_run', $run->id))->toBeTrue();
});
