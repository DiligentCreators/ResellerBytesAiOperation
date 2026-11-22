<?php

namespace App\Console\Commands;

use App\Models\TenantReportSend;
use App\Services\Reports\MonthlyValueReportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:render-value-report {report_id : Tenant report send UUID}')]
#[Description('Render a stored monthly value report mail locally without sending (prints period label and subject only)')]
class RenderMonthlyValueReportCommand extends Command
{
    public function handle(MonthlyValueReportService $reports): int
    {
        if (app()->isProduction()) {
            $this->error('reports:render-value-report refuses to run in production.');

            return self::FAILURE;
        }

        $reportId = (string) $this->argument('report_id');
        $report = TenantReportSend::query()
            ->whereKey($reportId)
            ->where('type', TenantReportSend::TYPE_MONTHLY_VALUE)
            ->first();

        if ($report === null) {
            $this->error('No monthly value report matched report_id='.$reportId);

            return self::FAILURE;
        }

        $mailable = $reports->mailableFor($report);
        // Same render path as resend; discard body so recipients/tokens/links stay out of output.
        $mailable->render();

        $this->line('period_label: '.$mailable->resolvedPeriodLabel());
        $this->line('subject: '.$mailable->envelope()->subject);

        return self::SUCCESS;
    }
}
