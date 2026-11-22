<?php

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Throwable;

class MonthlyValueReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(public readonly array $summary) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s] Your monthly value report — %d issues fixed',
                (string) ($this->summary['tenant_name'] ?? 'Workspace'),
                (int) ($this->summary['issues_fixed'] ?? 0),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->renderHtml(),
        );
    }

    private function renderHtml(): string
    {
        $from = $this->summary['health_score_previous'] ?? null;
        $to = $this->summary['health_score'] ?? null;
        $healthLine = (is_numeric($from) || is_numeric($to))
            ? sprintf(
                'Health went from %s to %s.',
                is_numeric($from) ? (string) (int) $from : 'n/a',
                is_numeric($to) ? (string) (int) $to : 'n/a',
            )
            : 'Health score was not available for this period.';

        $periodLabel = is_string($this->summary['period_label'] ?? null) && $this->summary['period_label'] !== ''
            ? (string) $this->summary['period_label']
            : $this->fallbackPeriodLabel();

        $lines = [
            '<p>Monthly value report for <strong>'.e((string) ($this->summary['tenant_name'] ?? 'Workspace')).'</strong>.</p>',
            '<p>'.e($periodLabel).'</p>',
            '<p>This period we fixed <strong>'.e((string) (int) ($this->summary['issues_fixed'] ?? 0)).'</strong> issues, '
                .'saved <strong>'.e((string) ($this->summary['time_saved_hours_display'] ?? '0.0')).'</strong> hours '
                .'(about $<strong>'.e((string) ($this->summary['time_saved_value_display'] ?? '0.00')).'</strong>), '
                .'and caught <strong>'.e((string) (int) ($this->summary['outside_heartbeat_incidents'] ?? 0)).'</strong> outside-heartbeat incidents.</p>',
            '<p>'.e($healthLine).'</p>',
            '<p><a href="'.e((string) ($this->summary['reports_url'] ?? '#')).'">View reports</a>'
                .' · <a href="'.e((string) ($this->summary['findings_url'] ?? '#')).'">Open Fix Center</a></p>',
        ];

        return implode("\n", $lines);
    }

    private function fallbackPeriodLabel(): string
    {
        $start = $this->summary['period_start'] ?? null;
        $end = $this->summary['period_end'] ?? null;

        if (! is_string($start) || $start === '' || ! is_string($end) || $end === '') {
            return 'Last 30 days';
        }

        try {
            $startAt = CarbonImmutable::parse($start)->utc();
            $endAt = CarbonImmutable::parse($end)->utc();
        } catch (Throwable) {
            return 'Last 30 days';
        }

        if ($startAt->year === $endAt->year) {
            return sprintf(
                'Last 30 days: %s - %s',
                $startAt->format('M j'),
                $endAt->format('M j, Y'),
            );
        }

        return sprintf(
            'Last 30 days: %s - %s',
            $startAt->format('M j, Y'),
            $endAt->format('M j, Y'),
        );
    }
}
