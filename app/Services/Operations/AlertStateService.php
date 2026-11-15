<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AlertStateService
{
    public function __construct(private readonly AlertNotificationDispatcher $dispatcher) {}

    /**
     * @param  list<array{code: string, severity: string, observed: int|float|null, threshold: int|float, window_minutes?: int}>  $alerts
     * @param  list<string>|null  $evaluatedCodes
     * @return array{active_alerts: int, notifications_sent: int, notifications_failed: int}
     */
    public function process(array $alerts, ?CarbonImmutable $observedAt = null, ?array $evaluatedCodes = null): array
    {
        $observedAt = ($observedAt ?? CarbonImmutable::now())->utc();
        $repeatMinutes = max(1, (int) config('operations.alerts.repeat_interval_minutes', 15));
        $sent = 0;
        $failed = 0;
        $activeCodes = [];

        foreach ($alerts as $alert) {
            $code = $alert['code'];
            $activeCodes[] = $code;

            $claimed = DB::transaction(function () use ($alert, $observedAt, $repeatMinutes): bool {
                DB::table('operations_alert_states')->insertOrIgnore([
                    'code' => $alert['code'],
                    'severity' => $alert['severity'],
                    'is_active' => false,
                    'first_seen_at' => $observedAt,
                    'last_seen_at' => $observedAt,
                    'notification_count' => 0,
                    'created_at' => $observedAt,
                    'updated_at' => $observedAt,
                ]);

                $state = DB::table('operations_alert_states')
                    ->where('code', $alert['code'])
                    ->lockForUpdate()
                    ->first();

                if ($state === null) {
                    return false;
                }

                $wasActive = (bool) $state->is_active;
                $lastNotifiedAt = $state->last_notified_at !== null
                    ? CarbonImmutable::parse((string) $state->last_notified_at)->utc()
                    : null;
                $claimAt = $state->delivery_claimed_at !== null
                    ? CarbonImmutable::parse((string) $state->delivery_claimed_at)->utc()
                    : null;
                $due = ! $wasActive
                    || $lastNotifiedAt === null
                    || $lastNotifiedAt->lessThanOrEqualTo($observedAt->subMinutes($repeatMinutes));
                $claimExpired = $claimAt === null
                    || $claimAt->lessThanOrEqualTo($observedAt->subMinutes(10));

                $updates = [
                    'severity' => $alert['severity'],
                    'is_active' => true,
                    'last_seen_at' => $observedAt,
                    'last_payload' => json_encode($alert, JSON_THROW_ON_ERROR),
                    'updated_at' => $observedAt,
                ];

                if (! $wasActive) {
                    $updates['first_seen_at'] = $observedAt;
                    $updates['last_notified_at'] = null;
                }

                if (! $due || ! $claimExpired) {
                    DB::table('operations_alert_states')
                        ->where('code', $alert['code'])
                        ->update($updates);

                    return false;
                }

                $updates['delivery_claimed_at'] = $observedAt;

                DB::table('operations_alert_states')
                    ->where('code', $alert['code'])
                    ->update($updates);

                return true;
            }, 3);

            if (! $claimed) {
                continue;
            }

            try {
                $delivery = $this->dispatcher->dispatch($alert, $observedAt);
                if ($delivery['success'] === true) {
                    DB::table('operations_alert_states')
                        ->where('code', $code)
                        ->update([
                            'last_notified_at' => $observedAt,
                            'delivery_claimed_at' => null,
                            'notification_count' => DB::raw('notification_count + 1'),
                            'updated_at' => $observedAt,
                        ]);
                    $sent++;
                } else {
                    DB::table('operations_alert_states')
                        ->where('code', $code)
                        ->update([
                            'delivery_claimed_at' => null,
                            'updated_at' => $observedAt,
                        ]);
                    Log::warning('Operational alert delivery was incomplete.', [
                        'alert_code' => $code,
                        'deliveries' => $delivery['deliveries'],
                    ]);
                    $failed++;
                }
            } catch (Throwable $exception) {
                DB::table('operations_alert_states')
                    ->where('code', $code)
                    ->update([
                        'delivery_claimed_at' => null,
                        'updated_at' => $observedAt,
                    ]);
                Log::warning('Operational alert dispatch failed.', [
                    'alert_code' => $code,
                    'exception_class' => $exception::class,
                ]);
                $failed++;
            }
        }

        $activeQuery = DB::table('operations_alert_states')->where('is_active', true);
        if ($evaluatedCodes === null) {
            // Backward-compatible behavior for direct callers without metric provenance.
            if ($activeCodes === []) {
                $activeQuery->update([
                    'is_active' => false,
                    'delivery_claimed_at' => null,
                    'updated_at' => $observedAt,
                ]);
            } else {
                $activeQuery->whereNotIn('code', array_values(array_unique($activeCodes)))
                    ->update([
                        'is_active' => false,
                        'delivery_claimed_at' => null,
                        'updated_at' => $observedAt,
                    ]);
            }
        } else {
            $resolvedCodes = array_values(array_diff(
                array_unique($evaluatedCodes),
                array_unique($activeCodes),
            ));

            if ($resolvedCodes !== []) {
                $activeQuery->whereIn('code', $resolvedCodes)
                    ->update([
                        'is_active' => false,
                        'delivery_claimed_at' => null,
                        'updated_at' => $observedAt,
                    ]);
            }
        }

        return [
            'active_alerts' => DB::table('operations_alert_states')->where('is_active', true)->count(),
            'notifications_sent' => $sent,
            'notifications_failed' => $failed,
        ];
    }
}
