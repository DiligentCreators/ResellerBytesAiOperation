<?php

namespace App\Console\Commands;

use App\Events\TenantBillingOutboxPublished;
use App\Models\TenantBillingOutboxMessage;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Tenancy\TenantBillingOperationsConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchTenantBillingOutbox extends Command
{
    protected $signature = 'billing:outbox:dispatch {--limit=100}';

    protected $description = 'Publish tenant billing outbox events with at-least-once delivery and bounded retries';

    private const MAX_ATTEMPTS = 12;

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('The limit must be between 1 and 500.');

            return self::FAILURE;
        }

        try {
            return app(TenantBillingOperationsConnection::class)->run(
                fn (): int => $this->dispatchDue((int) $limit),
            );
        } catch (Throwable $exception) {
            Log::error('Billing outbox dispatch could not start.', [
                'reason_code' => 'billing_operations_connection_unavailable',
                'exception_class' => $exception::class,
            ]);
            $this->error('Billing outbox dispatch requires a valid, separately provisioned PostgreSQL operations connection to the same database.');

            return self::FAILURE;
        }
    }

    private function dispatchDue(int $limit): int
    {
        $now = CarbonImmutable::now('UTC');
        $ids = TenantBillingOutboxMessage::query()
            ->where(function ($query) use ($now): void {
                $query->where(function ($pending) use ($now): void {
                    $pending->where('status', 'pending')->where('available_at', '<=', $now);
                })->orWhere(function ($stale) use ($now): void {
                    $stale->where('status', 'processing')->where('locked_at', '<=', $now->subMinutes(5));
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $delivered = 0;
        foreach ($ids as $id) {
            if ($this->publish((int) $id)) {
                $delivered++;
            }
        }

        $this->info("Published {$delivered} billing outbox event(s).");

        return self::SUCCESS;
    }

    private function publish(int $outboxId): bool
    {
        $claimed = DB::transaction(function () use ($outboxId): ?TenantBillingOutboxMessage {
            $message = TenantBillingOutboxMessage::query()->whereKey($outboxId)->lockForUpdate()->first();
            if ($message === null
                || ($message->status !== 'pending' && ! ($message->status === 'processing' && $message->locked_at?->lessThan(now('UTC')->subMinutes(5))))
                || $message->available_at->isFuture()) {
                return null;
            }

            $message->forceFill([
                'status' => 'processing',
                'attempts' => $message->attempts + 1,
                'locked_at' => now('UTC'),
                'last_error_code' => null,
            ])->save();

            return $message->refresh();
        }, 3);

        if ($claimed === null) {
            return false;
        }

        try {
            app(TenantDatabaseContext::class)->run($claimed->tenant_id, null, function () use ($claimed): void {
                Event::dispatch(new TenantBillingOutboxPublished(
                    $claimed->tenant_id,
                    (int) $claimed->id,
                    $claimed->event_type,
                    $claimed->aggregate_type,
                    $claimed->aggregate_id,
                    $claimed->payload ?? [],
                ));
            });

            app(TenantDatabaseContext::class)->run($claimed->tenant_id, null, fn () => DB::transaction(function () use ($claimed): void {
                TenantBillingOutboxMessage::query()
                    ->whereKey($claimed->id)
                    ->where('tenant_id', $claimed->tenant_id)
                    ->where('status', 'processing')
                    ->update([
                        'status' => 'delivered',
                        'delivered_at' => now('UTC'),
                        'locked_at' => null,
                        'last_error_code' => null,
                        'updated_at' => now('UTC'),
                    ]);
            }, 3));

            return true;
        } catch (Throwable $exception) {
            $dead = $claimed->attempts >= self::MAX_ATTEMPTS;
            $delaySeconds = min(21600, 30 * (2 ** min(10, max(0, $claimed->attempts - 1))));

            app(TenantDatabaseContext::class)->run($claimed->tenant_id, null, fn () => DB::transaction(function () use ($claimed, $dead, $delaySeconds): void {
                TenantBillingOutboxMessage::query()
                    ->whereKey($claimed->id)
                    ->where('tenant_id', $claimed->tenant_id)
                    ->where('status', 'processing')
                    ->update([
                        'status' => $dead ? 'dead_lettered' : 'pending',
                        'available_at' => $dead ? now('UTC') : now('UTC')->addSeconds($delaySeconds),
                        'locked_at' => null,
                        'failed_at' => $dead ? now('UTC') : null,
                        'last_error_code' => 'billing_outbox_delivery_failed',
                        'updated_at' => now('UTC'),
                    ]);
            }, 3));

            Log::warning('Tenant billing outbox delivery failed.', [
                'outbox_id' => $claimed->id,
                'tenant_id' => $claimed->tenant_id,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }
}
