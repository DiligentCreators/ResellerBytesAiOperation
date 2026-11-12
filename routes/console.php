<?php

use App\Jobs\AnalyzeDiagnosticRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
