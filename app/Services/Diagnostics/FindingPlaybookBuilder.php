<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRecommendation;

class FindingPlaybookBuilder
{
    /**
     * @return array{
     *     detected: array{title: string, category: string, severity: string, confidence: string|null, evidence: list<array{path: string, label: string, value: string}>},
     *     impact: array{summary: string, why_it_matters: string},
     *     safe_steps: list<array{step: int, action: string, rationale: string|null, risk_level: string|null}>,
     *     verify: array{signal: string, instructions: list<string>},
     *     rollback: array{summary: string, steps: list<string>},
     *     change_risk: string,
     *     deep_links: list<array{label: string, path: string, note: string}>,
     *     checklist_text: string
     * }
     */
    public function build(DiagnosticFinding $finding): array
    {
        $finding->loadMissing('recommendations');

        $evidence = $this->humanReadableEvidence($finding->evidence ?? []);
        $safeSteps = $finding->recommendations
            ->values()
            ->map(fn (DiagnosticRecommendation $recommendation, int $index): array => [
                'step' => $index + 1,
                'action' => $recommendation->recommendation,
                'rationale' => $recommendation->rationale,
                'risk_level' => $recommendation->risk_level,
            ])
            ->all();

        $changeRisk = $this->highestRisk(
            $finding->recommendations->pluck('risk_level')->filter()->values()->all()
        );

        $deepLinks = $this->deepLinksForCategory($finding->category);
        $rollback = $this->rollbackGuidance($changeRisk, $safeSteps);

        $playbook = [
            'detected' => [
                'title' => $finding->title,
                'category' => $finding->category,
                'severity' => $finding->severity,
                'confidence' => $finding->confidence !== null ? (string) $finding->confidence : null,
                'evidence' => $evidence,
            ],
            'impact' => [
                'summary' => $finding->summary,
                'why_it_matters' => $this->whyItMatters($finding),
            ],
            'safe_steps' => $safeSteps,
            'verify' => [
                'signal' => 'Re-collect a WHMCS diagnostic snapshot and confirm this finding fingerprint is absent or improved on the newest analyzed run.',
                'instructions' => [
                    'Trigger a fresh read-only diagnostic collect from the WHMCS addon.',
                    'Wait for analysis to complete on the new snapshot.',
                    'Use Re-check on this finding to compare against the latest analyzed run.',
                ],
            ],
            'rollback' => $rollback,
            'change_risk' => $changeRisk,
            'deep_links' => $deepLinks,
        ];

        $playbook['checklist_text'] = $this->checklistText($finding, $playbook);

        return $playbook;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<array{path: string, label: string, value: string}>
     */
    private function humanReadableEvidence(array $evidence): array
    {
        $rows = [];

        foreach ($evidence as $path => $value) {
            $rows[] = [
                'path' => (string) $path,
                'label' => $this->labelForPath((string) $path),
                'value' => $this->stringifyEvidenceValue($value),
            ];
        }

        return $rows;
    }

    private function labelForPath(string $path): string
    {
        $segments = array_values(array_filter(explode('.', $path), fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return $path;
        }

        return collect($segments)
            ->map(fn (string $segment): string => str_replace('_', ' ', $segment))
            ->map(fn (string $segment): string => ucwords($segment))
            ->implode(' → ');
    }

    private function stringifyEvidenceValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return $value === null ? 'null' : (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[unreadable evidence]' : $encoded;
    }

    private function whyItMatters(DiagnosticFinding $finding): string
    {
        return match ($finding->severity) {
            'critical' => 'Left unresolved, this can cause outages, security exposure, or billing/ops failures that customers notice first.',
            'warning' => 'This increases operational risk and can become customer-visible if ignored through the next change window.',
            default => 'This is lower urgency, but tracking it keeps WHMCS healthier and reduces surprise work later.',
        };
    }

    /**
     * @param  list<string>  $levels
     */
    private function highestRisk(array $levels): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $highest = 'low';

        foreach ($levels as $level) {
            $normalized = strtolower($level);
            if (($rank[$normalized] ?? 0) > ($rank[$highest] ?? 0)) {
                $highest = $normalized;
            }
        }

        return $highest;
    }

    /**
     * @return list<array{label: string, path: string, note: string}>
     */
    private function deepLinksForCategory(string $category): array
    {
        $common = [
            [
                'label' => 'WHMCS System Health',
                'path' => 'Utilities > System > System Health',
                'note' => 'Open in WHMCS admin. No secrets are included in this deep-link hint.',
            ],
        ];

        $byCategory = match ($category) {
            'cron' => [[
                'label' => 'System Cron Configuration',
                'path' => 'Configuration > System Settings > Cron',
                'note' => 'Confirm cron frequency and recent successful runs.',
            ]],
            'modules' => [[
                'label' => 'Addon Modules',
                'path' => 'Configuration > System Settings > Addon Modules',
                'note' => 'Review module status without enabling or disabling blindly.',
            ]],
            'php' => [[
                'label' => 'PHP Info',
                'path' => 'Utilities > System > PHP Info',
                'note' => 'Compare required extensions and limits against evidence.',
            ]],
            'security' => [[
                'label' => 'Security Settings',
                'path' => 'Configuration > System Settings > General Settings > Security',
                'note' => 'Validate security controls against the detected evidence only.',
            ]],
            'database' => [[
                'label' => 'Database Status',
                'path' => 'Utilities > System > Database Status',
                'note' => 'Inspect read-only health signals; do not run destructive SQL.',
            ]],
            'storage' => [[
                'label' => 'Storage / Attachments',
                'path' => 'Configuration > System Settings > Storage Settings',
                'note' => 'Confirm writable paths and free space using evidence values.',
            ]],
            'configuration' => [[
                'label' => 'General Settings',
                'path' => 'Configuration > System Settings > General Settings',
                'note' => 'Change only the settings named in safe steps.',
            ]],
            'server' => [[
                'label' => 'System Information',
                'path' => 'Utilities > System > System Information',
                'note' => 'Use host/server evidence before changing server config.',
            ]],
            default => [],
        };

        return array_values(array_merge($byCategory, $common));
    }

    /**
     * @param  list<array{step: int, action: string, rationale: string|null, risk_level: string|null}>  $safeSteps
     * @return array{summary: string, steps: list<string>}
     */
    private function rollbackGuidance(string $changeRisk, array $safeSteps): array
    {
        $summary = match ($changeRisk) {
            'critical', 'high' => 'Prefer reversible changes. Capture screenshots/config values before editing WHMCS, and restore the previous value if verification fails.',
            'medium' => 'Note the previous setting for each change so you can revert quickly if the re-check still fails.',
            default => 'These steps are low risk. Still record what you changed so another operator can undo it.',
        };

        $steps = [
            'Document the before value for every setting you change in WHMCS.',
            'If verification fails, restore the previous value and re-check with a fresh diagnostic snapshot.',
        ];

        if ($safeSteps !== []) {
            $steps[] = 'Undo only the numbered safe steps you completed — do not apply unrelated “fixes.”';
        }

        return [
            'summary' => $summary,
            'steps' => $steps,
        ];
    }

    /**
     * @param  array{
     *     detected: array{title: string, category: string, severity: string, confidence: string|null, evidence: list<array{path: string, label: string, value: string}>},
     *     impact: array{summary: string, why_it_matters: string},
     *     safe_steps: list<array{step: int, action: string, rationale: string|null, risk_level: string|null}>,
     *     verify: array{signal: string, instructions: list<string>},
     *     rollback: array{summary: string, steps: list<string>},
     *     change_risk: string,
     *     deep_links: list<array{label: string, path: string, note: string}>
     * }  $playbook
     */
    private function checklistText(DiagnosticFinding $finding, array $playbook): string
    {
        $lines = [
            'WHMCS Ops Copilot — Guided Fix Checklist',
            'Finding: '.$finding->title,
            'Severity: '.$finding->severity.' | Category: '.$finding->category.' | Change risk: '.$playbook['change_risk'],
            '',
            'Why it matters:',
            $playbook['impact']['why_it_matters'],
            '',
            'Detected evidence:',
        ];

        foreach ($playbook['detected']['evidence'] as $row) {
            $lines[] = '- '.$row['label'].': '.$row['value'].' ('.$row['path'].')';
        }

        $lines[] = '';
        $lines[] = 'Safe steps (manual in WHMCS — this platform does not write to WHMCS):';

        if ($playbook['safe_steps'] === []) {
            $lines[] = '- Review the evidence and confirm next steps with your team.';
        } else {
            foreach ($playbook['safe_steps'] as $step) {
                $lines[] = $step['step'].'. '.$step['action'];
            }
        }

        $lines[] = '';
        $lines[] = 'Verify:';
        foreach ($playbook['verify']['instructions'] as $instruction) {
            $lines[] = '- '.$instruction;
        }

        $lines[] = '';
        $lines[] = 'Rollback:';
        foreach ($playbook['rollback']['steps'] as $step) {
            $lines[] = '- '.$step;
        }

        if ($playbook['deep_links'] !== []) {
            $lines[] = '';
            $lines[] = 'WHMCS admin paths:';
            foreach ($playbook['deep_links'] as $link) {
                $lines[] = '- '.$link['label'].': '.$link['path'];
            }
        }

        return implode("\n", $lines);
    }
}
