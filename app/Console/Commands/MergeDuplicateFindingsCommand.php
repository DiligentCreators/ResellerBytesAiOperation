<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Diagnostics\MergeDuplicateFindingsService;
use Illuminate\Console\Command;

class MergeDuplicateFindingsCommand extends Command
{
    protected $signature = 'findings:merge-duplicates
                            {--tenant= : Limit to a single tenant UUID}
                            {--integration= : Limit to a single WHMCS integration UUID}
                            {--apply : Actually resolve duplicate findings (default is dry-run)}';

    protected $description = 'Merge active duplicate diagnostic findings per installation by canonical fingerprint';

    public function handle(MergeDuplicateFindingsService $merger): int
    {
        $tenantId = $this->option('tenant');
        $tenantId = is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
        $integrationId = $this->option('integration');
        $integrationId = is_string($integrationId) && $integrationId !== '' ? $integrationId : null;
        $apply = (bool) $this->option('apply');

        if ($tenantId !== null) {
            $tenant = Tenant::query()->whereKey($tenantId)->first();
            if ($tenant === null) {
                $this->error('No tenant matched --tenant='.$tenantId);

                return self::FAILURE;
            }
        }

        if ($integrationId !== null && ! $merger->integrationExists($integrationId)) {
            $this->error('No integration matched --integration='.$integrationId);

            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('DRY RUN — no findings will be resolved. Pass --apply to merge duplicates.');
        }

        $result = $merger->merge($tenantId, $integrationId, $apply);

        if ($result['groups'] === []) {
            $this->info('No active duplicate finding groups found.');
        } else {
            $rows = [];
            foreach ($result['groups'] as $group) {
                $this->line(sprintf(
                    'Group integration=%s key=%s kept=%s resolve=%s (active=%d resolve=%d)',
                    $group['integration_id'],
                    $group['canonical_key'],
                    $group['kept_finding_id'],
                    implode(',', $group['resolve_finding_ids']),
                    $group['active_count'],
                    $group['resolve_count'],
                ));

                $rows[] = [
                    $group['integration_id'],
                    $group['canonical_key'],
                    $group['kept_finding_id'],
                    implode(', ', $group['resolve_finding_ids']),
                    (string) $group['active_count'],
                    (string) $group['resolve_count'],
                ];
            }

            $this->table(
                ['Integration', 'Key', 'Kept', 'Resolve', 'Active', 'Count'],
                $rows,
            );
        }

        $this->line('Duplicate groups: '.$result['group_count']);
        $this->line('Findings to resolve: '.$result['resolve_count']);
        $this->line('Resolved this run: '.$result['resolved_count']);

        if (! $apply) {
            $this->warn('Dry-run complete. Re-run with --apply to resolve the listed duplicates.');
        } else {
            $this->info(sprintf('Resolved %d duplicate finding(s). Kept rows were left unchanged.', $result['resolved_count']));
        }

        return self::SUCCESS;
    }
}
