<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audit\AuditEventWriter;
use App\Services\LegalHoldService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredDiagnosticRecords extends Command
{
    protected $signature = 'diagnostics:retention:prune {--limit=100} {--execute}';

    protected $description = 'Delete diagnostic records after the absolute 365-day retention period';

    public function handle(LegalHoldService $holds, AuditEventWriter $audit): int
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
        $cutoffTimestamp = $cutoff->copy()->utc()->format('Y-m-d H:i:s');
        $ids = DB::table('diagnostic_runs')
            ->where('received_at', '<=', $cutoffTimestamp)
            ->orderBy('received_at')
            ->limit($limit)
            ->pluck('id');

        $eligible = 0;
        $deleted = 0;

        foreach ($ids as $id) {
            if (! $execute) {
                $eligible++;

                continue;
            }

            $result = DB::transaction(function () use ($id, $cutoffTimestamp, $holds, $audit): string {
                $run = DB::table('diagnostic_runs')
                    ->where('id', $id)
                    ->where('received_at', '<=', $cutoffTimestamp)
                    ->lockForUpdate()
                    ->first();
                if ($run === null) {
                    return 'skipped';
                }

                // Legal holds do not extend diagnostic retention. Close active holds
                // with immutable audit evidence before expiring the diagnostic content.
                $holds->expireForDiagnosticRun((string) $id, (string) $run->received_at);

                $audit->append([
                    'actor_type' => 'system',
                    'event_type' => 'diagnostics.retention.deleted',
                    'subject_type' => 'diagnostic_run',
                    'subject_id' => (string) $id,
                    'metadata' => [
                        'retention_days' => 365,
                        'received_at' => $run->received_at,
                        'policy' => 'diagnostic_received_at_365_days_no_exception',
                    ],
                    'occurred_at' => now('UTC'),
                ]);

                DB::table('diagnostic_runs')->where('id', $id)->delete();

                return 'deleted';
            }, 3);

            if ($result === 'deleted') {
                $deleted++;
            }
        }

        $this->line('Retention policy: absolute 365 days from UTC received_at; legal holds do not extend diagnostic content retention.');
        $this->line('Batch limit: '.$limit);
        $this->line('Candidate records inspected: '.$ids->count());

        if (! $execute) {
            $this->warn('DRY RUN ONLY — no records were deleted. Use --execute only after deployment gates are approved.');
            $this->line('Eligible diagnostic runs: '.$eligible);

            return self::SUCCESS;
        }

        $this->info("Deleted {$deleted} expired diagnostic run(s); any active legal holds were separately expired and audited.");

        return self::SUCCESS;
    }
}
