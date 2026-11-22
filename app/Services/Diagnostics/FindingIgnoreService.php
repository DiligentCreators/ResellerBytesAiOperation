<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FindingIgnoreService
{
    public const SNOOZE_7_DAYS = '7_days';

    public const SNOOZE_30_DAYS = '30_days';

    public const SNOOZE_FOREVER = 'forever';

    public const SNOOZE_OPTIONS = [
        self::SNOOZE_7_DAYS,
        self::SNOOZE_30_DAYS,
        self::SNOOZE_FOREVER,
    ];

    public function __construct(private readonly AuditEventWriter $audit) {}

    public function ignore(DiagnosticFinding $finding, User $actor, string $snooze): DiagnosticFinding
    {
        if (! in_array($snooze, self::SNOOZE_OPTIONS, true)) {
            throw new InvalidArgumentException('Invalid snooze option.');
        }

        $until = match ($snooze) {
            self::SNOOZE_7_DAYS => now()->addDays(7),
            self::SNOOZE_30_DAYS => now()->addDays(30),
            self::SNOOZE_FOREVER => Carbon::parse('9999-12-31 23:59:59'),
        };

        return DB::transaction(function () use ($finding, $actor, $snooze, $until): DiagnosticFinding {
            /** @var DiagnosticFinding $locked */
            $locked = DiagnosticFinding::query()->whereKey($finding->id)->lockForUpdate()->firstOrFail();
            $before = $locked->ignored_until?->toIso8601String();

            $locked->forceFill([
                'ignored_until' => $until,
                'status_updated_at' => now(),
            ])->save();

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'tenant_id' => $locked->tenant_id,
                'event_type' => 'diagnostic_finding.ignored',
                'subject_type' => 'diagnostic_finding',
                'subject_id' => $locked->id,
                'metadata' => [
                    'snooze' => $snooze,
                    'ignored_until' => $until->toIso8601String(),
                    'before_ignored_until' => $before,
                ],
                'occurred_at' => now(),
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    public function unignore(DiagnosticFinding $finding, User $actor): DiagnosticFinding
    {
        return DB::transaction(function () use ($finding, $actor): DiagnosticFinding {
            /** @var DiagnosticFinding $locked */
            $locked = DiagnosticFinding::query()->whereKey($finding->id)->lockForUpdate()->firstOrFail();
            $before = $locked->ignored_until?->toIso8601String();

            $locked->forceFill([
                'ignored_until' => null,
                'status_updated_at' => now(),
            ])->save();

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'tenant_id' => $locked->tenant_id,
                'event_type' => 'diagnostic_finding.unignored',
                'subject_type' => 'diagnostic_finding',
                'subject_id' => $locked->id,
                'metadata' => [
                    'before_ignored_until' => $before,
                ],
                'occurred_at' => now(),
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    public function isIgnored(DiagnosticFinding $finding): bool
    {
        return $finding->ignored_until !== null && $finding->ignored_until->isFuture();
    }
}
