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
    $cutoff = now()->subMinutes($staleMinutes);

    $runIds = DB::table('diagnostic_runs')
        ->where('status', 'queued')
        ->where('updated_at', '<=', $cutoff)
        ->orderBy('updated_at')
        ->limit($limit)
        ->pluck('id');

    $dispatched = 0;

    foreach ($runIds as $runId) {
        // Claim each stale row atomically so overlapping schedulers cannot enqueue it together.
        $claimed = DB::table('diagnostic_runs')
            ->where('id', $runId)
            ->where('status', 'queued')
            ->where('updated_at', '<=', $cutoff)
            ->update(['updated_at' => now()]);

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

    $this->info("Dispatched {$dispatched} stale queued diagnostic run(s).");

    return 0;
})->purpose('Re-dispatch stale queued diagnostic analyses with bounded retries');

Schedule::command('whmcs:nonces:prune')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('diagnostics:recover-queued')
    ->everyFiveMinutes()
    ->withoutOverlapping();
