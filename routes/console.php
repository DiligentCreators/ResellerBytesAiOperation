<?php

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\AiUsageAttempt;
use App\Models\User;
use App\Services\Diagnostics\AiBudgetService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Artisan::command('ai-budget:reconcile-no-charge {attempt} {--operator=} {--evidence=} {--note=}', function (AiBudgetService $budget) {
    $attemptId = (string) $this->argument('attempt');
    $operatorEmail = trim((string) $this->option('operator'));
    $evidenceReference = trim((string) $this->option('evidence'));
    $note = trim((string) $this->option('note'));

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

    $attempt = AiUsageAttempt::query()->find($attemptId);
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

Artisan::command('diagnostics:recover-queued {--stale-minutes=15} {--limit=100}', function () {
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

    foreach ($runIds as $runId) {
        // Claim queued or abandoned analyzing rows atomically before publishing a replacement job.
        $claimed = DB::table('diagnostic_runs')
            ->where('id', $runId)
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
            ->update([
                'status' => 'queued',
                'analysis_started_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
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

    $this->info("Dispatched {$dispatched} stale queued or abandoned analyzing diagnostic run(s).");

    return 0;
})->purpose('Re-dispatch stale queued and abandoned diagnostic analyses with bounded retries');

Schedule::command('whmcs:nonces:prune')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('diagnostics:recover-queued')
    ->everyFiveMinutes()
    ->withoutOverlapping();
