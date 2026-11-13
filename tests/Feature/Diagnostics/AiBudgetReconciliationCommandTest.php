<?php

use App\Models\AiBudgetPeriod;
use App\Models\AiUsageAttempt;
use App\Models\AuditEvent;
use App\Models\DiagnosticRun;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\AiBudgetService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

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
        'two_factor_secret' => Crypt::encrypt('totp-secret-'.$operator->getKey()),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $operator->fresh();
}

function reconciliationCommandArguments(AiUsageAttempt $attempt, ?User $operator, ?User $approver = null): array
{
    return [
        'attempt' => $attempt->id,
        '--operator' => $operator?->email ?? 'missing@example.test',
        '--approver' => $approver?->email ?? 'missing-approver@example.test',
        '--evidence' => 'provider-console-case-456',
        '--note' => 'Provider request history was independently reviewed and confirms no accepted request or charge.',
    ];
}

function mockSuccessfulReconciliationTotp(User $operator, User $approver): void
{
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')
        ->once()
        ->with('totp-secret-'.$operator->getKey(), '123456')
        ->andReturnTrue();
    $provider->shouldReceive('verify')
        ->once()
        ->with('totp-secret-'.$approver->getKey(), '654321')
        ->andReturnTrue();

    app()->instance(TwoFactorAuthenticationProvider::class, $provider);
}

function expectReconciliationTotpPrompts($artisan, User $operator, User $approver)
{
    return $artisan
        ->expectsQuestion('Enter the current 6-digit TOTP code for initiating operator ('.$operator->email.')', '123456')
        ->expectsQuestion('Enter the current 6-digit TOTP code for independent approver ('.$approver->email.')', '654321');
}

it('rejects reconciliation when operator identity is missing', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $approver = createConfirmedReconciliationOperator();

    $this->artisan('ai-budget:reconcile-no-charge', [
        'attempt' => $attempt->id,
        '--approver' => $approver->email,
        '--evidence' => 'provider-console-case-456',
        '--note' => 'Provider request history confirms no accepted request or charge.',
    ])->assertExitCode(1);

    expect($attempt->fresh()->status)->toBe('ambiguous');
});

it('rejects an operator without confirmed two-factor authentication', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = User::factory()->create();
    $approver = createConfirmedReconciliationOperator();

    $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt, $operator, $approver))
        ->assertExitCode(1);

    expect($attempt->fresh()->status)->toBe('ambiguous')
        ->and(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(0);
});

it('rejects an unverified approver even when TOTP is enrolled', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = createConfirmedReconciliationOperator();
    $approver = User::factory()->unverified()->create();
    $approver->forceFill([
        'two_factor_secret' => Crypt::encrypt('totp-secret-'.$approver->getKey()),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt, $operator, $approver))
        ->assertExitCode(1);

    expect($attempt->fresh()->status)->toBe('ambiguous');
});

it('rejects the same account acting as both operators', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = createConfirmedReconciliationOperator();

    $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt, $operator, $operator))
        ->assertExitCode(1);

    expect($attempt->fresh()->status)->toBe('ambiguous');
});

it('does not change a reservation when fresh TOTP verification fails', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = createConfirmedReconciliationOperator();
    $approver = createConfirmedReconciliationOperator();
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->once()->with('totp-secret-'.$operator->getKey(), '111111')->andReturnFalse();
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt, $operator, $approver))
        ->expectsQuestion('Enter the current 6-digit TOTP code for initiating operator ('.$operator->email.')', '111111')
        ->assertExitCode(1);

    expect($attempt->fresh()->status)->toBe('ambiguous')
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->reserved_micro_usd)->toBe($attempt->reserved_micro_usd)
        ->and(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(0);
});

it('does not change a reservation when either operator declines approval', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = createConfirmedReconciliationOperator();
    $approver = createConfirmedReconciliationOperator();
    mockSuccessfulReconciliationTotp($operator, $approver);

    expectReconciliationTotpPrompts(
        $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt, $operator, $approver)),
        $operator,
        $approver,
    )->expectsConfirmation('Both operators approve releasing this reservation based on the cited no-charge evidence?', 'no')
        ->assertExitCode(1);

    expect($attempt->fresh()->status)->toBe('ambiguous')
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->reserved_micro_usd)->toBe($attempt->reserved_micro_usd)
        ->and(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(0);
});

it('reconciles an ambiguous reservation only after two fresh TOTP challenges and independent approval', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = createConfirmedReconciliationOperator();
    $approver = createConfirmedReconciliationOperator();
    mockSuccessfulReconciliationTotp($operator, $approver);

    expectReconciliationTotpPrompts(
        $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt, $operator, $approver)),
        $operator,
        $approver,
    )->expectsConfirmation('Both operators approve releasing this reservation based on the cited no-charge evidence?', 'yes')
        ->assertExitCode(0);

    $attempt = $attempt->fresh();

    expect($attempt->status)->toBe('released')
        ->and($attempt->failure_code)->toBe('reconciled_not_charged')
        ->and($attempt->reconciled_by)->toBe((string) $operator->getKey())
        ->and($attempt->reconciliation_approved_by)->toBe((string) $approver->getKey())
        ->and($attempt->reconciliation_approved_at)->not->toBeNull()
        ->and(AiBudgetPeriod::query()->where('period_type', 'daily')->sole()->reserved_micro_usd)->toBe(0)
        ->and(AiBudgetPeriod::query()->where('period_type', 'monthly')->sole()->reserved_micro_usd)->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->sole()->actor_id)->toBe((string) $operator->getKey())
        ->and(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciliation_approved')->sole()->actor_id)->toBe((string) $approver->getKey());
});

it('rejects a non-ambiguous attempt before requesting either operator TOTP', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = createConfirmedReconciliationOperator();
    $approver = createConfirmedReconciliationOperator();
    app(AiBudgetService::class)->reconcileConfirmedNoCharge(
        $attempt,
        (string) $operator->getKey(),
        (string) $approver->getKey(),
        'provider-console-case-456',
        'A valid reconciliation note with sufficient context.',
    );

    $this->artisan('ai-budget:reconcile-no-charge', reconciliationCommandArguments($attempt->fresh(), $operator, $approver))
        ->assertExitCode(1);

    expect(AuditEvent::query()->where('event_type', 'ai_usage_attempt.reconciled_not_charged')->count())->toBe(1);
});

it('rejects direct service reconciliation when both actor ids are identical', function () {
    $attempt = createAmbiguousBudgetAttemptForCommandTest();
    $operator = createConfirmedReconciliationOperator();

    expect(fn () => app(AiBudgetService::class)->reconcileConfirmedNoCharge(
        $attempt,
        (string) $operator->getKey(),
        (string) $operator->getKey(),
        'provider-console-case-456',
        'A valid reconciliation note with sufficient context.',
    ))->toThrow(RuntimeException::class, 'budget_reconciliation_requires_two_distinct_operators');

    expect($attempt->fresh()->status)->toBe('ambiguous');
});
