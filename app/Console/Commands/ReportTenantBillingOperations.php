<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantBillingOperationsConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReportTenantBillingOperations extends Command
{
    protected $signature = 'billing:operations:report {--json}';

    protected $description = 'Report redacted billing outbox and webhook backlog, retry, and dead-letter metrics';

    public function handle(): int
    {
        try {
            $report = app(TenantBillingOperationsConnection::class)->run(
                fn (): array => $this->collectMetrics(),
            );
        } catch (Throwable $exception) {
            Log::error('Billing operations report could not be collected.', [
                'reason_code' => 'billing_operations_connection_unavailable',
                'exception_class' => $exception::class,
            ]);
            $this->error('Billing operations reporting requires the configured, separately provisioned PostgreSQL operations connection.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Queue', 'Pending due', 'Stale processing', 'Dead-lettered', 'Retry exhausted', 'Oldest due age (s)'],
            [
                ['Webhook inbox', $report['webhooks']['pending_due'], $report['webhooks']['stale_processing'], $report['webhooks']['dead_lettered'], $report['webhooks']['retry_exhausted'], $report['webhooks']['oldest_due_age_seconds'] ?? '—'],
                ['Transactional outbox', $report['outbox']['pending_due'], $report['outbox']['stale_processing'], $report['outbox']['dead_lettered'], $report['outbox']['retry_exhausted'], $report['outbox']['oldest_due_age_seconds'] ?? '—'],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectMetrics(): array
    {
        $now = CarbonImmutable::now('UTC');
        $staleBefore = $now->subMinutes(5);
        $result = [];

        foreach ([
            'webhooks' => 'tenant_billing_webhook_events',
            'outbox' => 'tenant_billing_outbox',
        ] as $key => $table) {
            $pending = DB::table($table)
                ->where('status', 'pending')
                ->where('available_at', '<=', $now);
            $oldestDue = (clone $pending)->min('available_at');

            $result[$key] = [
                'pending_due' => (clone $pending)->count(),
                'stale_processing' => DB::table($table)
                    ->where('status', 'processing')
                    ->where('locked_at', '<=', $staleBefore)
                    ->count(),
                'dead_lettered' => DB::table($table)->where('status', 'dead_lettered')->count(),
                'retry_exhausted' => DB::table($table)->where('attempts', '>=', 12)->count(),
                'manual_replay_limit_reached' => DB::table($table)->where('manual_replay_count', '>=', 3)->count(),
                'oldest_due_age_seconds' => is_string($oldestDue)
                    ? max(0, $now->getTimestamp() - CarbonImmutable::parse($oldestDue, 'UTC')->getTimestamp())
                    : null,
            ];
        }

        return [
            'schema_version' => 1,
            'observed_at' => $now->toIso8601String(),
            'webhooks' => $result['webhooks'],
            'outbox' => $result['outbox'],
        ];
    }
}
