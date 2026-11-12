<?php

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\AiUsageAttempt;
use App\Models\User;
use App\Services\Diagnostics\AiBudgetService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai-budget:reconcile-no-charge {attempt} {--operator=} {--evidence=} {--note=}', function (AiBudgetService $budget) {
    $attemptArgument = $this->argument('attempt');
    $attemptId = is_string($attemptArgument) ? trim($attemptArgument) : '';

    if ($attemptId === '') {
        $this->error('Provide a valid AI usage attempt UUID.');

        return 1;
    }
    $operatorOption = $this->option('operator');
    $evidenceOption = $this->option('evidence');
    $noteOption = $this->option('note');
    $operatorEmail = is_string($operatorOption) ? trim($operatorOption) : '';
    $evidenceReference = is_string($evidenceOption) ? trim($evidenceOption) : '';
    $note = is_string($noteOption) ? trim($noteOption) : '';

    if ($operatorEmail === '' || $evidenceReference === '' || mb_strlen($evidenceReference) > 255 || mb_strlen($note) < 20 || mb_strlen($note) > 1000) {
        $this->error('Provide --operator, --evidence, and a --note between 20 and 1000 characters.');

        return 1;
    }

    $operator = User::query()
        ->where('email', $operatorEmail)
        ->whereNotNull('email_verified_at')
        ->whereNotNull('two_factor_confirmed_at')
        ->first();

    if ($operator === null) {
        $this->error('The operator account was not found or has not confirmed TOTP MFA.');

        return 1;
    }

    $attempt = AiUsageAttempt::query()->whereKey($attemptId)->first();
    if ($attempt === null || $attempt->status !== 'ambiguous') {
        $this->error('Only an existing ambiguous AI usage attempt can be reconciled.');

        return 1;
    }

    $this->warn('This releases a held reservation. Verify provider-side evidence that the request was NOT accepted and no charge was incurred.');
    $this->line('Attempt: '.$attempt->id.' | Model: '.$attempt->model.' | Reserved micro-USD: '.$attempt->reserved_micro_usd);
    $this->line('Operator: '.$operator->email.' | Evidence reference: '.$evidenceReference);

    if ($this->confirm('Have you independently verified the provider evidence and approve this no-charge reconciliation?', false) === false) {
        $this->comment('No changes made.');

        return 1;
    }

    try {
        $budget->reconcileConfirmedNoCharge(
            $attempt,
            (string) $operator->getKey(),
            $evidenceReference,
            $note,
        );
    } catch (Throwable $exception) {
        $this->error('Reconciliation failed: '.$exception->getMessage());

        return 1;
    }

    $this->info('Ambiguous reservation reconciled as confirmed not charged. Audit event recorded.');

    return 0;
})->purpose('Release an ambiguous AI reservation only after evidence-based operator verification');

Artisan::command('diagnostics:recover-queued {--stale-minutes=15} {--limit=100}', function (AiBudgetService $budget) {
    $staleMinutes = max(5, min(1440, (int) $this->option('stale-minutes')));
    $limit = max(1, min(500, (int) $this->option('limit')));

    // Do not reclaim a running analysis before its queue visibility timeout and lease have elapsed.
    $retryAfter = (int) config('queue.connections.'.config('queue.default').'.retry_after', 120);
    $minimumStaleSeconds = max($retryAfter, 90, 100) + 30;
    $staleSeconds = max($staleMinutes * 60, $minimumStaleSeconds);
    $cutoff = now()->subSeconds($staleSeconds);

    $runIds = DB::table('diagnostic_runs')
        ->where(function ($query) use ($cutoff) {
            $query
                ->where(function ($query) use ($cutoff) {
                    $query->where('status', 'queued')
                        ->where('updated_at', '<=', $cutoff);
                })
                ->orWhere(function ($query) use ($cutoff) {
                    $query->where('status', 'analyzing')
                        ->where(function ($query) use ($cutoff) {
                            $query->where('analysis_started_at', '<=', $cutoff)
                                ->orWhere(function ($query) use ($cutoff) {
                                    $query->whereNull('analysis_started_at')
                                        ->where('updated_at', '<=', $cutoff);
                                });
                        });
                });
        })
        ->orderBy('updated_at')
        ->limit($limit)
        ->pluck('id');

    $dispatched = 0;
    $heldForOperatorReview = 0;

    foreach ($runIds as $runId) {
        $recoveryState = DB::transaction(function () use ($runId, $cutoff, $budget): string {
            $run = DB::table('diagnostic_runs')
                ->where('id', $runId)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                return 'skipped';
            }

            $isStaleQueued = $run->status === 'queued'
                && Carbon::parse($run->updated_at)->lessThanOrEqualTo($cutoff);
            $isStaleAnalyzing = $run->status === 'analyzing'
                && ($run->analysis_started_at !== null
                    ? Carbon::parse($run->analysis_started_at)->lessThanOrEqualTo($cutoff)
                    : Carbon::parse($run->updated_at)->lessThanOrEqualTo($cutoff));

            if ($isStaleQueued === false && $isStaleAnalyzing === false) {
                return 'skipped';
            }

            $attempt = AiUsageAttempt::query()
                ->where('diagnostic_run_id', $runId)
                ->orderByDesc('attempt_number')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($attempt !== null && in_array($attempt->status, ['submitted', 'ambiguous'], true)) {
                if ($attempt->status === 'submitted') {
                    $budget->markAmbiguous($attempt, 'worker_lost_after_submit');
                }

                DB::table('diagnostic_runs')
                    ->where('id', $runId)
                    ->update([
                        'status' => 'analysis_failed',
                        'analysis_started_at' => null,
                        'analysis_error_code' => 'provider_outcome_ambiguous',
                        'updated_at' => now(),
                    ]);

                return 'held';
            }

            if ($attempt !== null
                && $attempt->status === 'settled'
                && $run->status === 'analyzing'
                && (int) $attempt->attempt_number === (int) $run->analysis_attempts) {
                DB::table('diagnostic_runs')
                    ->where('id', $runId)
                    ->update([
                        'status' => 'analysis_failed',
                        'analysis_started_at' => null,
                        'analysis_error_code' => 'analysis_interrupted_after_settlement',
                        'updated_at' => now(),
                    ]);

                return 'held';
            }

            if ($attempt !== null && $attempt->status === 'reserved') {
                $budget->releaseBeforeSubmit($attempt, 'worker_lost_before_submit');
            }

            DB::table('diagnostic_runs')
                ->where('id', $runId)
                ->update([
                    'status' => 'queued',
                    'analysis_started_at' => null,
                    'analysis_error_code' => null,
                    'updated_at' => now(),
                ]);

            return 'claimed';
        }, 3);

        if ($recoveryState === 'held') {
            $heldForOperatorReview++;

            continue;
        }

        if ($recoveryState !== 'claimed') {
            continue;
        }

        try {
            AnalyzeDiagnosticRun::dispatch((string) $runId);
            $dispatched++;
        } catch (Throwable $exception) {
            Log::warning('Queued diagnostic analysis recovery dispatch failed.', [
                'diagnostic_run_id' => $runId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    $this->info("Dispatched {$dispatched} recoverable diagnostic run(s); held {$heldForOperatorReview} for operator review.");

    return 0;
})->purpose('Recover stale diagnostics without repeating ambiguous or already-settled provider charges');

Schedule::command('whmcs:nonces:prune')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('diagnostics:recover-queued')
    ->everyFiveMinutes()
    ->withoutOverlapping();
