<?php

namespace App\Console\Commands;

use App\Services\LegalHoldService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredDiagnosticRecords extends Command
{
    protected $signature = 'diagnostics:retention:prune {--limit=100} {--execute}';

    protected $description = 'Delete expired diagnostic records in bounded batches, excluding active legal holds';

    public function handle(LegalHoldService $holds): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('The --limit option must be an integer between 1 and 500');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        if ($execute && config('diagnostics.retention_cleanup_enabled') !== true) {
            $this->error('Diagnostic cleanup is disabled. Set DIAGNOSTICS_RETENTION_CLEANUP_ENABLED=true in the deployment environment after all release gates pass.');

            return self::FAILURE;
        }

        $cutoff = now('UTC')->subDays(365);
        $ids = DB::table('diagnostic_runs')
            ->where('received_at', '<=', $cutoff)
            ->whereNotIn('status', ['received', 'queued', 'analyzing'])
            ->orderBy('received_at')
            ->limit($limit)
            ->pluck('id');

        $eligible = 0;
        $held = 0;
        $deleted = 0;

        foreach ($ids as $id) {
            if (! $execute) {
                if ($holds->isHeld('diagnostic_run', (string) $id)) {
                    $held++;
                } else {
                    $eligible++;
                }

                continue;
            }

            $result = DB::transaction(function () use ($id, $cutoff, $holds): string {
                $run = DB::table('diagnostic_runs')->where('id', $id)->lockForUpdate()->first();
                if ($run === null
                    || in_array($run->status, ['received', 'queued', 'analyzing'], true)
                    || $run->received_at === null
                    || $run->received_at > $cutoff->format('Y-m-d H:i:s')) {
                    return 'skipped';
                }

                // Hold placement locks this same diagnostic-run row before inserting its hold.
                // Re-check inside the deletion transaction to close the candidate-selection race.
                if ($holds->isHeld('diagnostic_run', (string) $id)) {
                    return 'held';
                }

                DB::table('diagnostic_runs')->where('id', $id)->delete();

                return 'deleted';
            }, 3);

            if ($result === 'held') {
                $held++;
            } elseif ($result === 'deleted') {
                $deleted++;
            }
        }

        $this->line('Retention policy: 365 days from UTC received_at; active legal holds and active runs are excluded.');
        $this->line('Batch limit: '.$limit);
        $this->line('Candidate records inspected: '.$ids->count());

        if (! $execute) {
            $this->warn('DRY RUN ONLY — no records were deleted. Use --execute only after deployment gates are approved.');
            $this->line('Eligible diagnostic runs: '.$eligible);
            $this->line('Protected by legal hold: '.$held);

            return self::SUCCESS;
        }

        $this->info("Deleted {$deleted} expired diagnostic run(s); protected {$held} by active legal hold(s).");

        return self::SUCCESS;
    }
}
