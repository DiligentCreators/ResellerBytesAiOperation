<?php

namespace App\Mail;

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
     * @param  array{
     *     tenant_name: string,
     *     open: int,
     *     in_progress: int,
     *     critical: int,
     *     resolved_this_week: int,
     *     accepted_risk: int,
     *     awaiting_safe_actions: int,
     *     top_findings: list<array{title: string, severity: string, status: string, integration_name: string}>,
     *     findings_url: string,
     *     safe_actions_url: string
     * }  $digest
     */
    public function __construct(public readonly array $digest) {}

    public function envelope(): Envelope
    {
        $open = $this->digest['open'] + $this->digest['in_progress'];
        $awaiting = (int) ($this->digest['awaiting_safe_actions'] ?? 0);

        return new Envelope(
            subject: sprintf(
                '[%s] Weekly WHMCS ops digest — %d active finding%s%s',
                $this->digest['tenant_name'],
                $open,
                $open === 1 ? '' : 's',
                $awaiting > 0 ? sprintf(', %d Safe Action%s awaiting apply', $awaiting, $awaiting === 1 ? '' : 's') : '',
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
        $lines = [
            '<p>Weekly ops digest for <strong>'.e($this->digest['tenant_name']).'</strong>.</p>',
            '<ul>',
            '<li>Open: '.e((string) $this->digest['open']).'</li>',
            '<li>In progress: '.e((string) $this->digest['in_progress']).'</li>',
            '<li>Critical (active): '.e((string) $this->digest['critical']).'</li>',
            '<li>Resolved this week: '.e((string) $this->digest['resolved_this_week']).'</li>',
            '<li>Accepted risk: '.e((string) $this->digest['accepted_risk']).'</li>',
            '<li>Approved Safe Actions awaiting WHMCS apply: '.e((string) ($this->digest['awaiting_safe_actions'] ?? 0)).'</li>',
            '</ul>',
        ];

        if ($this->digest['top_findings'] === [] && (int) ($this->digest['awaiting_safe_actions'] ?? 0) === 0) {
            $lines[] = '<p><strong>Last scan clean</strong> — no active findings need attention this week.</p>';
        } else {
            if ($this->digest['top_findings'] !== []) {
                $lines[] = '<p>Top active findings:</p><ol>';
                foreach ($this->digest['top_findings'] as $finding) {
                    $lines[] = '<li>'.e($finding['title'])
                        .' ('.e($finding['severity']).' · '.e($finding['status']).' · '.e($finding['integration_name']).')</li>';
                }
                $lines[] = '</ol>';
            }

            if ((int) ($this->digest['awaiting_safe_actions'] ?? 0) > 0) {
                $lines[] = '<p>Approved Safe Actions are waiting in WHMCS. Open Addons → ResellerBytes AI Operations → <strong>Apply approved Safe Actions</strong>.</p>';
            }
        }

        $lines[] = '<p><a href="'.e($this->digest['findings_url']).'">Open Fix Center</a>'
            .' · <a href="'.e((string) ($this->digest['safe_actions_url'] ?? $this->digest['findings_url'])).'">Safe Actions</a></p>';
        $lines[] = '<p>Allowlisted remediations apply from the WHMCS addon after human approval. Hosting-panel-only issues (such as PHP version) stay guided playbooks.</p>';

        return implode("\n", $lines);
    }
}
