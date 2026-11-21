<?php

namespace App\Services\Diagnostics;

use App\Mail\FindingsWeeklyDigestMail;
use App\Models\DiagnosticFinding;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\Mail\CentralOutboundMailer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

class FindingsWeeklyDigestService
{
    public function __construct(private readonly CentralOutboundMailer $mailer) {}

    /**
     * @return array{tenants: int, recipients: int, skipped_clean: int}
     */
    public function send(?CarbonInterface $since = null): array
    {
        $since ??= now()->subDays(7);
        $tenants = 0;
        $recipients = 0;
        $skippedClean = 0;

        Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use ($since, &$tenants, &$recipients, &$skippedClean): void {
                foreach ($chunk as $tenant) {
                    $digest = $this->buildDigest($tenant, $since);
                    $tenants++;

                    if ($digest['open'] === 0 && $digest['in_progress'] === 0 && $digest['resolved_this_week'] === 0) {
                        $skippedClean++;
                        // Still send a clean digest so owners see "Last scan clean".
                    }

                    $members = TenantMembership::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('status', TenantMembership::STATUS_ACTIVE)
                        ->whereIn('role', [
                            TenantMembership::ROLE_OWNER,
                            TenantMembership::ROLE_ADMIN,
                            TenantMembership::ROLE_OPERATOR,
                        ])
                        ->with('user:id,name,email')
                        ->get();

                    $mailable = new FindingsWeeklyDigestMail($digest);

                    foreach ($members as $membership) {
                        $email = $membership->user?->email;
                        if (! is_string($email) || $email === '') {
                            continue;
                        }

                        $this->mailer->send(
                            $email,
                            $mailable->envelope()->subject,
                            $mailable->render(),
                            strip_tags(str_replace(['</p>', '</li>', '</h1>', '</h2>'], ["\n", "\n", "\n", "\n"], $mailable->render())),
                        );
                        $recipients++;
                    }
                }
            });

        return [
            'tenants' => $tenants,
            'recipients' => $recipients,
            'skipped_clean' => $skippedClean,
        ];
    }

    /**
     * @return array{
     *     tenant_name: string,
     *     open: int,
     *     in_progress: int,
     *     critical: int,
     *     resolved_this_week: int,
     *     accepted_risk: int,
     *     top_findings: list<array{title: string, severity: string, status: string, integration_name: string}>,
     *     findings_url: string
     * }
     */
    public function buildDigest(Tenant $tenant, CarbonInterface $since): array
    {
        $base = DiagnosticFinding::query()->where('tenant_id', $tenant->id);

        $topFindings = (clone $base)
            ->active()
            ->with('diagnosticRun.integration:id,name')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (DiagnosticFinding $finding): array => [
                'title' => $finding->title,
                'severity' => $finding->severity,
                'status' => $finding->status,
                'integration_name' => $finding->diagnosticRun->integration->name ?? 'Unknown',
            ])
            ->all();

        return [
            'tenant_name' => $tenant->name,
            'open' => (clone $base)->where('status', DiagnosticFinding::STATUS_OPEN)->count(),
            'in_progress' => (clone $base)->where('status', DiagnosticFinding::STATUS_IN_PROGRESS)->count(),
            'critical' => (clone $base)->active()->where('severity', 'critical')->count(),
            'resolved_this_week' => (clone $base)
                ->where('status', DiagnosticFinding::STATUS_RESOLVED)
                ->where('resolved_at', '>=', $since)
                ->count(),
            'accepted_risk' => (clone $base)->where('status', DiagnosticFinding::STATUS_ACCEPTED_RISK)->count(),
            'top_findings' => $topFindings,
            'findings_url' => URL::route('findings.index', ['status' => 'active']),
        ];
    }
}
