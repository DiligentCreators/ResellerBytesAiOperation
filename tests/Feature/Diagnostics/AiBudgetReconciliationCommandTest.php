<?php

use App\\Models\\AiBudgetPeriod;
use App\\Models\\AiUsageAttempt;
use App\\Models\\AuditEvent;
use App\\Models\\DiagnosticRun;
use App\\Models\\User;
use App\\Models\\WhmcsIntegration;
use App\\Services\\Diagnostics\\AiBudgetService;
use Illuminate\\Support\\Str;

function createAmbiguousBudgetAttemptForCommandTest(): AiUsageAttempt
{
    $integration = WhmcsIntegration::create([
        'name' => 'Reconciliation command test '.Str::uuid(),
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
        'analysis_started_at' => now(),
        'analysis_attempts' => 1,
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('e', 64),
    ]);

    $service = app(AiBudgetService::class);
    $attempt = $service->reserve($run, 'gpt-5.6-sol', '2026-11-12.1');
    $service->markSubmitted($attempt);
    $service->markAmbiguous($attempt, 'provider_timeout_after_submit');

    return $attempt->fresh();
}

function createConfirmedReconciliationOperator(): User
{
    $operator = User::factory()->create();

    $operator->forceFill([
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $operator->fresh();
}

function reconciliationCommandArguments(AiUsageAttempt $attempt, ?User $operator): array
{
    return [
        'attempt' => $attempt->id,
        '--operator' => $operator?->email ?? 'missing@example.test',
        '--evidence' => 'provider-console-case-456',
        '--note' => 'Provider request history was independently reviewed and confirms no accepted request or charge.',
    ];
}

it('rejects reconciliation when operator email is missing', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();

    $this->artisan('ai-budget:reconcile-no-charge', [
        'attempt' => $attempt->id,
        '--evidence' => 'provider-console-case-456',
        '--note' => 'Provider request history confirms no accepted request or charge.',
    ])->assertExitCode(1);

    expect($attempt->fresh()->status)->toBe('ambiguous');
});

it('rejects an operator without confirmed two-factor authentication', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = User::factory()->create();

    $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt, $operator))
        ->assertExitCode(1);

    expect($attempt->fresh()->status)->toBe('ambiguous')
        ->and(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(0);
});

it('rejects an unverified operator even when two-factor authentication is confirmed', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = User::factory()->unverified()->create();
    $operator->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt, $operator))
        ->assertExitCode(1);

    expect($attempt->fresh()->status)->toBe('ambiguous');
});

it('does not change a reservation when the operator declines confirmation', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = createConfirmedReconciliationOperator();

    $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt, $operator))
        ->expectsConfirmation('Have you independently verified the provider evidence and approve this no-charge reconciliation?', 'no')
        ->assertExitCode(1);

    expect($attempt->fresh()->status)->toBe('ambiguous')
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->reserved_micro_usd)->toBe($attempt->reserved_micro_usd)
        ->and(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(0);
});

it('reconciles an ambiguous reservation through the confirmed operator command', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = createConfirmedReconciliationOperator();

    $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt, $operator))
        ->expectsConfirmation('Have you independently verified the provider evidence and approve this no-charge reconciliation?', 'yes')
        ->assertExitCode(0);

    expect($attempt->fresh()->status)->toBe('released')
        ->and($attempt->fresh()->failure_code)->toBe('reconciled_not_charged')
        ->and($attempt->fresh()->reconciled_by)->toBe((string) $operator->getKey())
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->reserved_micro_usd)->toBe(0)
        ->and(AiBudgetPeriod::query()->where('period_type', 'monthly')->sole()->reserved_micro_usd)->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(1);
});

it('rejects a non-ambiguous attempt before requesting operator confirmation', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    app(AiBudgetService::class)->reconcileConfirmedNoCharge(
        $attempt,
        'operator-id-not-used',
        'provider-console-case-456',
        'A valid reconciliation note with sufficient context.',
    );
    $operator = createConfirmedReconciliationOperator();

    $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt->fresh(), $operator))
        ->assertExitCode(1);

    expect(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(1);
});
