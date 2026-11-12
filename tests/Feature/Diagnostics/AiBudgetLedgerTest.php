<?php

use App\Models\AiBudgetPeriod;
use App\Models\AiUsageAttempt;
use App\Models\AuditEvent;
use App\Models\DiagnosticRun;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\AiBudgetService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

function makeBudgetDiagnosticRun(int $attempts = 1): DiagnosticRun
{
    $integration = WhmcsIntegration::create([
        'name' => 'AI budget ledger test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    return DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'analyzing',
        'analysis_started_at' => now(),
        'analysis_attempts' => $attempts,
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('f', 64),
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-12T12:00:00Z'));
    config()->set('ai_budget.daily_limit_micro_usd', 5_000_000);
    config()->set('ai_budget.monthly_limit_micro_usd', 50_000_000);
    config()->set('ai_budget.max_input_bytes', 65_536);
    config()->set('ai_budget.max_output_tokens', 3_000);
    $priceCards = config('ai_budget.price_cards');
    $priceCards['gpt-5.6-sol']['valid_until'] = '2099-12-31T23:59:59Z';
    config()->set('ai_budget.price_cards', $priceCards);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reserves both UTC budgets and settles provider usage idempotently', function () {
    $run = makeBudgetDiagnosticRun();
    $service = app(AiBudgetService::class);
    $attempt = $service->reserve($run, 'gpt-5.6-sol', '2026-11-12.1');

    expect($attempt->status)->toBe('reserved')
        ->and($attempt->reserved_micro_usd)->toBe(322_144);

    $service->markSubmitted($attempt);
    $service->settle($attempt, [
        'input_tokens' => 1_000,
        'cached_input_tokens' => 0,
        'output_tokens' => 500,
        'total_tokens' => 1_500,
    ], 'resp_budget_test_1');

    $service->settle($attempt, [
        'input_tokens' => 1_000,
        'cached_input_tokens' => 0,
        'output_tokens' => 500,
        'total_tokens' => 1_500,
    ], 'resp_budget_test_1');

    expect($attempt->fresh()->status)->toBe('settled')
        ->and($attempt->fresh()->settled_micro_usd)->toBe(14_000)
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->reserved_micro_usd)->toBe(0)
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->committed_micro_usd)->toBe(14_000)
        ->and(AiBudgetPeriod::query()->where('period_type', 'monthly')->sole()->committed_micro_usd)->toBe(14_000);
});

it('blocks a reservation when either UTC budget has insufficient capacity', function () {
    config()->set('ai_budget.daily_limit_micro_usd', 100_000);
    $run = makeBudgetDiagnosticRun();

    expect(fn () => app(AiBudgetService::class)->reserve($run, 'gpt-5.6-sol', '2026-11-12.1'))
        ->toThrow(RuntimeException::class, 'budget_exhausted');

    expect(AiUsageAttempt::query()->count())->toBe(0)
        ->and(AiBudgetPeriod::query()->count())->toBe(0);
});

it('releases a reservation only when the provider request was not submitted', function () {
    $run = makeBudgetDiagnosticRun();
    $service = app(AiBudgetService::class);
    $attempt = $service->reserve($run, 'gpt-5.6-sol', '2026-11-12.1');

    $service->releaseBeforeSubmit($attempt, 'local_request_validation_failed');

    expect($attempt->fresh()->status)->toBe('released')
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->reserved_micro_usd)->toBe(0);
});

it('keeps an ambiguous submitted reservation and blocks a second billable attempt', function () {
    $run = makeBudgetDiagnosticRun();
    $service = app(AiBudgetService::class);
    $attempt = $service->reserve($run, 'gpt-5.6-sol', '2026-11-12.1');

    $service->markSubmitted($attempt);
    $service->markAmbiguous($attempt, 'provider_timeout_after_submit');
    $run->forceFill(['analysis_attempts' => 2])->save();

    expect($attempt->fresh()->status)->toBe('ambiguous')
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->reserved_micro_usd)->toBe(322_144)
        ->and(fn () => $service->reserve($run->fresh(), 'gpt-5.6-sol', '2026-11-12.1'))
        ->toThrow(RuntimeException::class, 'budget_attempt_unresolved');
});

it('releases an ambiguous reservation only through audited no-charge reconciliation', function () {
    $run = makeBudgetDiagnosticRun();
    $service = app(AiBudgetService::class);
    $attempt = $service->reserve($run, 'gpt-5.6-sol', '2026-11-12.1');
    $service->markSubmitted($attempt);
    $service->markAmbiguous($attempt, 'provider_timeout_after_submit');

    $operator = User::factory()->create();
    $service->reconcileConfirmedNoCharge(
        $attempt,
        (string) $operator->getKey(),
        'provider-console-case-123',
        'Verified provider request history shows no accepted request and no charge.',
    );

    expect($attempt->fresh()->status)->toBe('released')
        ->and($attempt->fresh()->failure_code)->toBe('reconciled_not_charged')
        ->and($attempt->fresh()->reconciled_by)->toBe((string) $operator->getKey())
        ->and($attempt->fresh()->reconciled_at)->not->toBeNull()
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->reserved_micro_usd)->toBe(0)
        ->and(AiBudgetPeriod::query()->where('period_type', 'monthly')->sole()->reserved_micro_usd)->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(1);

    expect(fn () => $service->reconcileConfirmedNoCharge(
        $attempt->fresh(),
        (string) $operator->getKey(),
        'provider-console-case-123',
        'Repeated reconciliation must be rejected as the attempt is no longer ambiguous.',
    ))->toThrow(RuntimeException::class, 'budget_attempt_not_reconcilable');

    expect(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(1);
});

it('rolls back an ambiguous reservation release when the audit insert fails', function () {
    $run = makeBudgetDiagnosticRun();
    $service = app(AiBudgetService::class);
    $attempt = $service->reserve($run, 'gpt-5.6-sol', '2026-11-12.1');
    $service->markSubmitted($attempt);
    $service->markAmbiguous($attempt, 'provider_timeout_after_submit');
    $operator = User::factory()->create();

    DB::statement("CREATE TRIGGER reject_audit_insert BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'audit_insert_rejected'); END");

    expect(fn () => $service->reconcileConfirmedNoCharge(
        $attempt,
        (string) $operator->getKey(),
        'provider-console-case-rollback',
        'Provider request history confirms no accepted request or charge.',
    ))->toThrow(QueryException::class);

    expect($attempt->fresh()->status)->toBe('ambiguous')
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->reserved_micro_usd)->toBe($attempt->reserved_micro_usd)
        ->and(AiBudgetPeriod::query()->where('period_type', 'monthly')->sole()->reserved_micro_usd)->toBe($attempt->reserved_micro_usd)
        ->and(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(0);
});

it('records actual provider cost when usage exceeds the reserved ceiling', function () {
    $run = makeBudgetDiagnosticRun();
    $service = app(AiBudgetService::class);
    $attempt = $service->reserve($run, 'gpt-5.6-sol', '2026-11-12.1');

    $service->markSubmitted($attempt);
    $service->settle($attempt, [
        'input_tokens' => 65_536,
        'cached_input_tokens' => 0,
        'output_tokens' => 3_001,
        'total_tokens' => 68_537,
    ], 'resp_budget_overrun_1');

    expect($attempt->fresh()->settled_micro_usd)->toBe(322_164)
        ->and($attempt->fresh()->failure_code)->toBe('reservation_overrun')
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->committed_micro_usd)->toBe(322_164);
});

it('settles an old attempt using its stored price rates after the price card expires', function () {
    $run = makeBudgetDiagnosticRun();
    $service = app(AiBudgetService::class);
    $attempt = $service->reserve($run, 'gpt-5.6-sol', '2026-11-12.1');

    $service->markSubmitted($attempt);
    $priceCards = config('ai_budget.price_cards');
    $priceCards['gpt-5.6-sol']['valid_until'] = '2026-11-11T23:59:59Z';
    config()->set('ai_budget.price_cards', $priceCards);

    $service->settle($attempt, [
        'input_tokens' => 1_000,
        'cached_input_tokens' => 0,
        'output_tokens' => 500,
        'total_tokens' => 1_500,
    ], 'resp_budget_expired_card_1');

    expect($attempt->fresh()->status)->toBe('settled')
        ->and($attempt->fresh()->settled_micro_usd)->toBe(14_000);
});

it('refuses to reserve against an expired model price card', function () {
    $priceCards = config('ai_budget.price_cards');
    $priceCards['gpt-5.6-sol']['valid_until'] = '2026-11-11T23:59:59Z';
    config()->set('ai_budget.price_cards', $priceCards);
    $run = makeBudgetDiagnosticRun();

    expect(fn () => app(AiBudgetService::class)->reserve($run, 'gpt-5.6-sol', '2026-11-12.1'))
        ->toThrow(RuntimeException::class, 'budget_price_card_expired');
});
