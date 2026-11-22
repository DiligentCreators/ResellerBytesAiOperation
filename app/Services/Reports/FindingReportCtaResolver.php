<?php

namespace App\Services\Reports;

use App\Models\DiagnosticFinding;
use App\Models\Tenant;
use App\Services\Diagnostics\FixCenterIssueBuilder;
use App\Services\SafeActions\SafeActionRegistry;
use App\Services\Tenancy\TenantEntitlementService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Maps Fix Center primary actions to weekly report / digest CTA types and labels.
 */
class FindingReportCtaResolver
{
    public function __construct(
        private readonly FixCenterIssueBuilder $fixCenter,
        private readonly TenantEntitlementService $entitlements,
        private readonly SafeActionRegistry $registry,
    ) {}

    /**
     * @return array{cta: 'fix_now'|'start_guided_fix'|'view_in_fix_center', cta_label: string}
     */
    public function forFinding(DiagnosticFinding $finding, Tenant $tenant): array
    {
        $entitlements = $this->tenantEntitlements($tenant->id);
        $primary = $this->fixCenter->resolvePrimaryAction(
            $finding,
            $entitlements['one_click'],
            $entitlements['guided'],
            $this->registry->fixesEnabled(),
            true,
        );

        return $this->fromPrimaryAction($primary);
    }

    /**
     * @return array{cta: 'fix_now'|'start_guided_fix'|'view_in_fix_center', cta_label: string}
     */
    public function fromPrimaryAction(string $primaryAction): array
    {
        return match ($primaryAction) {
            'fix_now' => [
                'cta' => 'fix_now',
                'cta_label' => 'Fix now',
            ],
            'start_guide' => [
                'cta' => 'start_guided_fix',
                'cta_label' => 'Start guided fix',
            ],
            default => [
                'cta' => 'view_in_fix_center',
                'cta_label' => 'View in Fix Center',
            ],
        };
    }

    /**
     * Resolve a stored CTA for older report summaries that pre-date the cta field.
     *
     * @param  array<string, mixed>  $issue
     */
    public function labelFromIssue(array $issue): string
    {
        $label = $issue['cta_label'] ?? null;
        if (is_string($label) && $label !== '') {
            return $label;
        }

        $cta = $issue['cta'] ?? null;
        if (is_string($cta) && $cta !== '') {
            return match ($cta) {
                'fix_now' => 'Fix now',
                'start_guided_fix', 'view_guide' => 'Start guided fix',
                default => 'View in Fix Center',
            };
        }

        return 'View in Fix Center';
    }

    /**
     * @return array{guided: bool, one_click: bool}
     */
    private function tenantEntitlements(string $tenantId): array
    {
        $guided = false;
        $oneClick = false;

        try {
            $this->entitlements->requireFeature($tenantId, 'fixes.guided');
            $guided = true;
        } catch (AuthorizationException) {
            $guided = false;
        }

        try {
            $this->entitlements->requireFeature($tenantId, 'fixes.one_click');
            $oneClick = true;
        } catch (AuthorizationException) {
            $oneClick = false;
        }

        return [
            'guided' => $guided,
            'one_click' => $oneClick,
        ];
    }
}
