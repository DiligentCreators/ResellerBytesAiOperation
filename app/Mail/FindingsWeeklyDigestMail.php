<?php

namespace App\Mail;

use App\Services\Reports\FindingReportCtaResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FindingsWeeklyDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $digest
     */
    public function __construct(public readonly array $digest) {}

    public function envelope(): Envelope
    {
        $open = (int) ($this->digest['open'] ?? 0) + (int) ($this->digest['in_progress'] ?? 0);
        $awaiting = (int) ($this->digest['awaiting_safe_actions'] ?? 0);
        $score = $this->digest['health_score'] ?? null;
        $scoreLabel = is_numeric($score) ? sprintf(', health %d', (int) $score) : '';

        return new Envelope(
            subject: sprintf(
                '[%s] Weekly health report — %d active finding%s%s%s',
                (string) ($this->digest['tenant_name'] ?? 'Workspace'),
                $open,
                $open === 1 ? '' : 's',
                $awaiting > 0 ? sprintf(', %d Safe Action%s awaiting apply', $awaiting, $awaiting === 1 ? '' : 's') : '',
                $scoreLabel,
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
        $score = $this->digest['health_score'] ?? null;
        $change = $this->digest['health_score_change'] ?? null;
        $changeLabel = is_numeric($change)
            ? sprintf(' (%s%d)', (int) $change >= 0 ? '+' : '', (int) $change)
            : '';

        $lines = [
            '<p>Weekly health report for <strong>'.e((string) ($this->digest['tenant_name'] ?? 'Workspace')).'</strong>.</p>',
            '<ul>',
            '<li>Health score: '.e(is_numeric($score) ? (string) (int) $score.$changeLabel : 'n/a').'</li>',
            '<li>Issues fixed this week: '.e((string) (int) ($this->digest['issues_fixed'] ?? $this->digest['resolved_this_week'] ?? 0)).'</li>',
            '<li>Time saved: '.e((string) ($this->digest['time_saved_hours_display'] ?? '0.0')).' hours'
                .' (about $'.e((string) ($this->digest['time_saved_value_display'] ?? '0.00'))
                .' at $'.e((string) (int) ($this->digest['hourly_rate_usd'] ?? 0)).'/hr)</li>',
        ];

        if (($this->digest['recovered_display'] ?? null) !== null) {
            $lines[] = '<li>Paid in WHMCS (last 7 days): $'.e((string) $this->digest['recovered_display']).'</li>';
        }

        $lines[] = '<li>Outside-heartbeat incidents caught: '.e((string) (int) ($this->digest['outside_heartbeat_incidents'] ?? 0)).'</li>';
        $lines[] = '<li>Open: '.e((string) (int) ($this->digest['open'] ?? 0)).'</li>';
        $lines[] = '<li>In progress: '.e((string) (int) ($this->digest['in_progress'] ?? 0)).'</li>';
        $lines[] = '<li>Critical (active): '.e((string) (int) ($this->digest['critical'] ?? 0)).'</li>';
        $lines[] = '<li>Accepted risk: '.e((string) (int) ($this->digest['accepted_risk'] ?? 0)).'</li>';
        $lines[] = '<li>Approved Safe Actions awaiting WHMCS apply: '.e((string) (int) ($this->digest['awaiting_safe_actions'] ?? 0)).'</li>';
        $lines[] = '<li>Applied Safe Actions awaiting verify scan: '.e((string) (int) ($this->digest['needs_recheck'] ?? 0)).'</li>';
        $lines[] = '</ul>';

        $topOpen = is_array($this->digest['top_open_issues'] ?? null) ? $this->digest['top_open_issues'] : [];
        $topFindings = is_array($this->digest['top_findings'] ?? null) ? $this->digest['top_findings'] : [];

        if (
            $topOpen === []
            && $topFindings === []
            && (int) ($this->digest['awaiting_safe_actions'] ?? 0) === 0
            && (int) ($this->digest['needs_recheck'] ?? 0) === 0
        ) {
            $lines[] = '<p><strong>All clear.</strong> Your last check found no problems that need attention this week.</p>';
        } else {
            if ($topOpen !== []) {
                $lines[] = '<p>Top open issues:</p><ol>';
                $ctaResolver = app(FindingReportCtaResolver::class);
                foreach (array_slice($topOpen, 0, 3) as $finding) {
                    $title = e((string) ($finding['title'] ?? 'Issue'));
                    $meta = e((string) ($finding['severity'] ?? '')).' · '.e((string) ($finding['status'] ?? '')).' · '.e((string) ($finding['integration_name'] ?? ''));
                    $fixUrl = (string) ($finding['fix_url'] ?? '');
                    $ctaLabel = e($ctaResolver->labelFromIssue(is_array($finding) ? $finding : []));
                    if ($fixUrl !== '') {
                        $lines[] = '<li>'.$title.' ('.$meta.') — <a href="'.e($fixUrl).'">'.$ctaLabel.'</a></li>';
                    } else {
                        $lines[] = '<li>'.$title.' ('.$meta.')</li>';
                    }
                }
                $lines[] = '</ol>';
            } elseif ($topFindings !== []) {
                $lines[] = '<p>Top active findings:</p><ol>';
                foreach ($topFindings as $finding) {
                    $lines[] = '<li>'.e((string) ($finding['title'] ?? ''))
                        .' ('.e((string) ($finding['severity'] ?? '')).' · '.e((string) ($finding['status'] ?? '')).' · '.e((string) ($finding['integration_name'] ?? '')).')</li>';
                }
                $lines[] = '</ol>';
            }

            if ((int) ($this->digest['awaiting_safe_actions'] ?? 0) > 0) {
                $lines[] = '<p>Approved Safe Actions are waiting in WHMCS. Open Addons → ResellerBytes AI Operations → <strong>Apply approved Safe Actions</strong>.</p>';
            }

            if ((int) ($this->digest['needs_recheck'] ?? 0) > 0) {
                $lines[] = '<p>Some remediations were applied but still need a verify scan before the finding can clear.</p>';
            }
        }

        $installations = is_array($this->digest['installations'] ?? null) ? $this->digest['installations'] : [];
        if ($installations !== []) {
            $lines[] = '<p>Installation heartbeat status:</p><ul>';
            foreach ($installations as $installation) {
                $lines[] = '<li>'.e((string) ($installation['name'] ?? 'Install'))
                    .': '.e((string) ($installation['heartbeat_state'] ?? 'unknown')).'</li>';
            }
            $lines[] = '</ul>';
        }

        $lines[] = '<p><a href="'.e((string) ($this->digest['findings_url'] ?? '#')).'">Open Fix Center</a>'
            .' · <a href="'.e((string) ($this->digest['safe_actions_url'] ?? $this->digest['findings_url'] ?? '#')).'">Safe Actions</a>'
            .' · <a href="'.e((string) ($this->digest['reports_url'] ?? '#')).'">Reports</a></p>';
        $lines[] = '<p>Allowlisted remediations apply from the WHMCS addon after human approval. Hosting-panel-only issues (such as PHP version) stay guided playbooks.</p>';

        return implode("\n", $lines);
    }
}
