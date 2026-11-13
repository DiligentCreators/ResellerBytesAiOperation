<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportDiagnosticRetentionCandidates extends Command
{
    protected $signature = 'diagnostics:retention:report {--days=90}';
    protected $description = 'Report diagnostic records older than the retention threshold without deleting data';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 36500) {
            $this->error('The --days option must be an integer between 1 and 36500');
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $candidateRuns = DB::table('diagnostic_runs')->where('received_at', '<=', $cutoff);
        $runCount = (clone $candidateRuns)->count();
        $oldest = (clone $candidateRuns)->min('received_at');
        $newest = (clone $candidateRuns)->max('received_at');
        $candidateRunIds = (clone $candidateRuns)->select('id');
        $findingCount = DB::table('diagnostic_findings')->whereIn('diagnostic_run_id', $candidateRunIds)->count();
        $candidateFindingIds = DB::table('diagnostic_findings')
            ->whereIn('diagnostic_run_id', (clone $candidateRuns)->select('id'))
            ->select('id');
        $recommendationCount = DB::table('diagnostic_recommendations')
            ->whereIn('diagnostic_finding_id', $candidateFindingIds)->count();

        $this->line('Mode: REPORT ONLY - no records will be deleted.');
        $this->line('Retention threshold: '.$days.' day(s)');
        $this->line('Cutoff (UTC): '.$cutoff->copy()->utc()->toIso8601String());
        $this->line('Candidate diagnostic runs: '.$runCount);
        $this->line('Affected findings: '.$findingCount);
        $this->line('Affected recommendations: '.$recommendationCount);
        $this->line('Oldest candidate received_at: '.($oldest ?? 'none'));
        $this->line('Newest candidate received_at: '.($newest ?? 'none'));
        $this->warn('This report does not evaluate legal holds; automated deletion remains disabled.');
        return self::SUCCESS;
    }
}
