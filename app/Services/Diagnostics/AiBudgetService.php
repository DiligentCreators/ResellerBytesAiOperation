<?php

namespace App\Services\Diagnostics;

use App\Models\AiBudgetPeriod;
use App\Models\AiTenantBudgetPeriod;
use App\Models\Tenant;
use App\Models\AiUsageAttempt;
use App\Models\DiagnosticRun;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AiBudgetService
{
    public function reserve(DiagnosticRun $run, string $model, string $promptVersion): AiUsageAttempt
    {
        $card = $this->priceCard($model);
        $inputBytes = strlen(json_encode($run->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $maxInputBytes = (int) config('ai_budget.max_input_bytes', 65_536);
        $maxOutputTokens = (int) config('ai_budget.max_output_tokens', 3_000);
        $dailyLimit = (int) config('ai_budget.daily_limit_micro_usd');
        $monthlyLimit = (int) config('ai_budget.monthly_limit_micro_usd');

        if ($maxInputBytes < 1 || $maxOutputTokens < 1 || $dailyLimit < 1 || $monthlyLimit < 1) {
            throw new RuntimeException('budget_configuration_invalid');
        }

        if ($inputBytes > $maxInputBytes) {
            throw new RuntimeException('budget_input_limit_exceeded');
        }

        // UTF-8 token count cannot exceed its byte count; this is a conservative hard upper bound.
        $maxInputTokens = $maxInputBytes;
        $reservationNumerator = ($maxInputTokens * $card['input_tenths_micro_usd_per_token'])
            + ($maxOutputTokens * $card['output_tenths_micro_usd_per_token']);
        $reservation = intdiv($reservationNumerator + 9, 10);

        return DB::transaction(function () use ($run, $model, $promptVersion, $card, $reservation): AiUsageAttempt {
            $lockedRun = DiagnosticRun::query()->whereKey($run->id)->with('integration')->lockForUpdate()->firstOrFail();
            $tenantId = $lockedRun->integration->tenant_id;
            if ($tenantId !== null && ! Tenant::query()->whereKey($tenantId)->where('status', Tenant::STATUS_ACTIVE)->exists()) {
                throw new RuntimeException('budget_tenant_inactive');
            }

            $unresolved = AiUsageAttempt::query()
                ->where('diagnostic_run_id', $lockedRun->id)
                ->whereIn('status', ['reserved', 'submitted', 'ambiguous'])
                ->exists();

            if ($unresolved) {
                throw new RuntimeException('budget_attempt_unresolved');
            }

            $now = Carbon::now('UTC');
            $periods = [
                ['type' => 'daily', 'start' => $now->toDateString(), 'limit' => (int) config('ai_budget.daily_limit_micro_usd')],
                ['type' => 'monthly', 'start' => $now->copy()->startOfMonth()->toDateString(), 'limit' => (int) config('ai_budget.monthly_limit_micro_usd')],
            ];

            foreach ($periods as $period) {
                DB::table('ai_budget_periods')->insertOrIgnore([
                    'period_type' => $period['type'],
                    'period_start' => $period['start'],
                    'limit_micro_usd' => $period['limit'],
                    'reserved_micro_usd' => 0,
                    'committed_micro_usd' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $lockedPeriods = [];
            foreach ($periods as $period) {
                $budgetPeriod = AiBudgetPeriod::query()
                    ->where('period_type', $period['type'])
                    ->whereDate('period_start', $period['start'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($budgetPeriod->limit_micro_usd !== $period['limit']) {
                    throw new RuntimeException('budget_limit_configuration_changed');
                }

                if (($budgetPeriod->committed_micro_usd + $budgetPeriod->reserved_micro_usd + $reservation) > $budgetPeriod->limit_micro_usd) {
                    throw new RuntimeException('budget_exhausted');
                }

                $lockedPeriods[] = $budgetPeriod;
            }

            $this->reserveTenantPeriods($tenantId, $periods, $reservation);

            foreach ($lockedPeriods as $budgetPeriod) {
                $budgetPeriod->increment('reserved_micro_usd', $reservation);
            }

            return AiUsageAttempt::create([
                'id' => (string) Str::uuid(),
                'diagnostic_run_id' => $lockedRun->id,
                'tenant_id' => $tenantId,
                'attempt_number' => $lockedRun->analysis_attempts,
                'model' => $model,
                'prompt_version' => $promptVersion,
                'price_card_version' => $card['version'],
                'input_tenths_micro_usd_per_token' => $card['input_tenths_micro_usd_per_token'],
                'cached_input_tenths_micro_usd_per_token' => $card['cached_input_tenths_micro_usd_per_token'],
                'output_tenths_micro_usd_per_token' => $card['output_tenths_micro_usd_per_token'],
                'daily_period_start' => $now->toDateString(),
                'monthly_period_start' => $now->copy()->startOfMonth()->toDateString(),
                'status' => 'reserved',
                'reserved_micro_usd' => $reservation,
            ]);
        }, 3);
    }

    public function markSubmitted(AiUsageAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            $locked = AiUsageAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'reserved') {
                throw new RuntimeException('budget_attempt_not_reservable');
            }

            $locked->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
        }, 3);
    }

    /**
     * @param  array{input_tokens?: int, cached_input_tokens?: int, output_tokens?: int, total_tokens?: int}  $usage
     */
    public function settle(AiUsageAttempt $attempt, array $usage, ?string $responseId): void
    {
        DB::transaction(function () use ($attempt, $usage, $responseId): void {
            $locked = AiUsageAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'settled') {
                return;
            }

            if (! in_array($locked->status, ['submitted', 'ambiguous'], true)) {
                throw new RuntimeException('budget_attempt_not_settleable');
            }

            $input = $this->usageTokenCount($usage, 'input_tokens');
            $cached = $this->usageTokenCount($usage, 'cached_input_tokens');
            $output = $this->usageTokenCount($usage, 'output_tokens');
            if ($cached > $input) {
                throw new RuntimeException('budget_usage_invalid');
            }

            $billableInput = $input - $cached;
            $costNumerator = $this->safeCostAdd(
                $this->safeCostMultiply($billableInput, (int) $locked->input_tenths_micro_usd_per_token),
                $this->safeCostMultiply($cached, (int) $locked->cached_input_tenths_micro_usd_per_token),
                $this->safeCostMultiply($output, (int) $locked->output_tenths_micro_usd_per_token),
            );
            if ($costNumerator > PHP_INT_MAX - 9) {
                throw new RuntimeException('budget_usage_invalid');
            }
            $cost = intdiv($costNumerator + 9, 10);

            $periods = $this->periodKeysForAttempt($locked);
            foreach ($periods as $period) {
                $budgetPeriod = AiBudgetPeriod::query()
                    ->where('period_type', $period['type'])
                    ->whereDate('period_start', $period['start'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($budgetPeriod->reserved_micro_usd < $locked->reserved_micro_usd) {
                    throw new RuntimeException('budget_reservation_invariant_failed');
                }

                $budgetPeriod->forceFill([
                    'reserved_micro_usd' => $budgetPeriod->reserved_micro_usd - $locked->reserved_micro_usd,
                    'committed_micro_usd' => $budgetPeriod->committed_micro_usd + $cost,
                ])->save();
            }

            if ($input > PHP_INT_MAX - $output) {
                throw new RuntimeException('budget_usage_invalid');
            }
            $minimumTotalTokens = $input + $output;
            $totalTokens = $this->usageTokenCount($usage, 'total_tokens');
            if ($totalTokens === 0) {
                $totalTokens = $minimumTotalTokens;
            } elseif ($totalTokens < $minimumTotalTokens) {
                throw new RuntimeException('budget_usage_invalid');
            }

            $this->settleTenantPeriods($locked, $cost);

            $locked->forceFill([
                'status' => 'settled',
                'settled_micro_usd' => $cost,
                'input_tokens' => $input,
                'cached_input_tokens' => $cached,
                'output_tokens' => $output,
                'total_tokens' => $totalTokens,
                'provider_response_id' => $responseId,
                'failure_code' => $cost > $locked->reserved_micro_usd ? 'reservation_overrun' : null,
                'settled_at' => now(),
            ])->save();
        }, 3);
    }

    public function markAmbiguous(AiUsageAttempt $attempt, string $failureCode): void
    {
        DB::transaction(function () use ($attempt, $failureCode): void {
            $locked = AiUsageAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'submitted') {
                $locked->forceFill(['status' => 'ambiguous', 'failure_code' => $failureCode])->save();
            }
        }, 3);
    }

    /**
     * Release an ambiguous reservation only after an operator verifies from the provider
     * dashboard or support evidence that no billable request was accepted.
     */
    public function reconcileConfirmedNoCharge(
        AiUsageAttempt $attempt,
        string $operatorId,
        string $approverId,
        string $evidenceReference,
        string $note,
    ): void {
        if ($operatorId === '' || $approverId === '' || $operatorId === $approverId) {
            throw new RuntimeException('budget_reconciliation_requires_two_distinct_operators');
        }

        DB::transaction(function () use ($attempt, $operatorId, $approverId, $evidenceReference, $note): void {
            $locked = AiUsageAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'ambiguous') {
                throw new RuntimeException('budget_attempt_not_reconcilable');
            }

            foreach ($this->periodKeysForAttempt($locked) as $period) {
                $budgetPeriod = AiBudgetPeriod::query()
                    ->where('period_type', $period['type'])
                    ->whereDate('period_start', $period['start'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($budgetPeriod->reserved_micro_usd < $locked->reserved_micro_usd) {
                    throw new RuntimeException('budget_reservation_invariant_failed');
                }

                $budgetPeriod->decrement('reserved_micro_usd', $locked->reserved_micro_usd);
            }

            $this->settleTenantPeriods($locked, 0);

            $locked->forceFill([
                'status' => 'released',
                'failure_code' => 'reconciled_not_charged',
                'reconciled_at' => now(),
                'reconciled_by' => $operatorId,
                'reconciliation_approved_by' => $approverId,
                'reconciliation_approved_at' => now(),
                'reconciliation_note' => $note.' [evidence: '.$evidenceReference.']',
            ])->save();

            app(AuditEventWriter::class)->append([
                'actor_type' => 'user',
                'actor_id' => $approverId,
                'tenant_id' => $locked->tenant_id,
                'event_type' => 'ai_usage_attempt.reconciliation_approved',
                'subject_type' => 'ai_usage_attempt',
                'subject_id' => $locked->id,
                'metadata' => [
                    'initiated_by' => $operatorId,
                    'evidence_reference' => $evidenceReference,
                ],
                'occurred_at' => now(),
            ]);

            app(AuditEventWriter::class)->append([
                'actor_type' => 'user',
                'actor_id' => $operatorId,
                'tenant_id' => $locked->tenant_id,
                'event_type' => 'ai_usage_attempt.reconciled_not_charged',
                'subject_type' => 'ai_usage_attempt',
                'subject_id' => $locked->id,
                'metadata' => [
                    'approved_by' => $approverId,
                    'evidence_reference' => $evidenceReference,
                    'note' => $note,
                    'reserved_micro_usd_released' => $locked->reserved_micro_usd,
                    'model' => $locked->model,
                ],
                'occurred_at' => now(),
            ]);
        }, 3);
    }

    public function releaseBeforeSubmit(AiUsageAttempt $attempt, string $failureCode): void
    {
        DB::transaction(function () use ($attempt, $failureCode): void {
            $locked = AiUsageAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'reserved') {
                return;
            }

            foreach ($this->periodKeysForAttempt($locked) as $period) {
                $budgetPeriod = AiBudgetPeriod::query()
                    ->where('period_type', $period['type'])
                    ->whereDate('period_start', $period['start'])
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($budgetPeriod->reserved_micro_usd < $locked->reserved_micro_usd) {
                    throw new RuntimeException('budget_reservation_invariant_failed');
                }

                $budgetPeriod->decrement('reserved_micro_usd', $locked->reserved_micro_usd);
            }

            $this->settleTenantPeriods($locked, 0);

            $locked->forceFill(['status' => 'released', 'failure_code' => $failureCode])->save();
        }, 3);
    }


    /**
     * Create and reserve the tenant's daily/monthly allowance in the same
     * transaction as the platform-wide cap. Platform limits remain global.
     *
     * @param array<int, array{type: string, start: string, limit: int}> $periods
     */
    private function reserveTenantPeriods(?string $tenantId, array $periods, int $reservation): void
    {
        if ($tenantId === null) {
            return;
        }

        $limits = [
            'daily' => (int) config('ai_budget.tenant_daily_limit_micro_usd', config('ai_budget.daily_limit_micro_usd')),
            'monthly' => (int) config('ai_budget.tenant_monthly_limit_micro_usd', config('ai_budget.monthly_limit_micro_usd')),
        ];

        foreach ($periods as $period) {
            $limit = $limits[$period['type']] ?? 0;
            if ($limit < 1) {
                throw new RuntimeException('budget_tenant_configuration_invalid');
            }

            DB::table('ai_tenant_budget_periods')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'period_type' => $period['type'],
                'period_start' => $period['start'],
                'limit_micro_usd' => $limit,
                'reserved_micro_usd' => 0,
                'committed_micro_usd' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $budgetPeriod = AiTenantBudgetPeriod::query()
                ->where('tenant_id', $tenantId)
                ->where('period_type', $period['type'])
                ->whereDate('period_start', $period['start'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($budgetPeriod->limit_micro_usd !== $limit) {
                throw new RuntimeException('budget_tenant_limit_configuration_changed');
            }

            if ($budgetPeriod->committed_micro_usd + $budgetPeriod->reserved_micro_usd + $reservation > $budgetPeriod->limit_micro_usd) {
                throw new RuntimeException('budget_tenant_exhausted');
            }

            $budgetPeriod->increment('reserved_micro_usd', $reservation);
        }
    }

    /**
     * Move the captured tenant reservation to committed spend, or release it,
     * using the immutable tenant UUID and UTC period keys stored on the attempt.
     */
    private function settleTenantPeriods(AiUsageAttempt $attempt, int $cost): void
    {
        if ($attempt->tenant_id === null) {
            return;
        }

        foreach ($this->periodKeysForAttempt($attempt) as $period) {
            $budgetPeriod = AiTenantBudgetPeriod::query()
                ->where('tenant_id', $attempt->tenant_id)
                ->where('period_type', $period['type'])
                ->whereDate('period_start', $period['start'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($budgetPeriod->reserved_micro_usd < $attempt->reserved_micro_usd
                || $budgetPeriod->committed_micro_usd > PHP_INT_MAX - $cost) {
                throw new RuntimeException('budget_tenant_reservation_invariant_failed');
            }

            $budgetPeriod->forceFill([
                'reserved_micro_usd' => $budgetPeriod->reserved_micro_usd - $attempt->reserved_micro_usd,
                'committed_micro_usd' => $budgetPeriod->committed_micro_usd + $cost,
            ])->save();
        }
    }

    /**
     * Provider usage is untrusted input. Accept only non-negative integer token counts;
     * malformed values must leave the submitted reservation held for reconciliation.
     *
     * @param  array<string, mixed>  $usage
     */
    private function usageTokenCount(array $usage, string $key): int
    {
        $value = $usage[$key] ?? 0;
        if (! is_int($value) || $value < 0) {
            throw new RuntimeException('budget_usage_invalid');
        }

        return $value;
    }

    private function safeCostMultiply(int $tokens, int $rate): int
    {
        if ($rate < 0 || ($rate !== 0 && $tokens > intdiv(PHP_INT_MAX, $rate))) {
            throw new RuntimeException('budget_usage_invalid');
        }

        return $tokens * $rate;
    }

    private function safeCostAdd(int ...$values): int
    {
        $total = 0;
        foreach ($values as $value) {
            if ($value > PHP_INT_MAX - $total) {
                throw new RuntimeException('budget_usage_invalid');
            }
            $total += $value;
        }

        return $total;
    }

    /**
     * @return array{version: string, input_tenths_micro_usd_per_token: int, output_tenths_micro_usd_per_token: int, cached_input_tenths_micro_usd_per_token: int, valid_until: string}
     */
    private function priceCard(string $model, ?string $version = null): array
    {
        $priceCards = config('ai_budget.price_cards', []);
        $card = is_array($priceCards) ? ($priceCards[$model] ?? null) : null;
        if (! is_array($card)
            || ! is_string($card['version'] ?? null)
            || ! is_numeric($card['input_tenths_micro_usd_per_token'] ?? null)
            || ! is_numeric($card['output_tenths_micro_usd_per_token'] ?? null)
            || ! is_string($card['valid_until'] ?? null)
            || ($version !== null && $card['version'] !== $version)) {
            throw new RuntimeException('budget_price_card_unavailable');
        }

        $inputRate = (int) $card['input_tenths_micro_usd_per_token'];
        $outputRate = (int) $card['output_tenths_micro_usd_per_token'];
        $cachedInputRate = is_numeric($card['cached_input_tenths_micro_usd_per_token'] ?? null)
            ? (int) $card['cached_input_tenths_micro_usd_per_token']
            : 4;

        if ($inputRate < 1 || $outputRate < 1 || $cachedInputRate < 0) {
            throw new RuntimeException('budget_price_card_unavailable');
        }

        if (Carbon::now('UTC')->greaterThan(Carbon::parse($card['valid_until'], 'UTC'))) {
            throw new RuntimeException('budget_price_card_expired');
        }

        return [
            'version' => $card['version'],
            'input_tenths_micro_usd_per_token' => $inputRate,
            'output_tenths_micro_usd_per_token' => $outputRate,
            'cached_input_tenths_micro_usd_per_token' => $cachedInputRate,
            'valid_until' => $card['valid_until'],
        ];
    }

    /**
     * @return array<int, array{type: string, start: string}>
     */
    private function periodKeysForAttempt(AiUsageAttempt $attempt): array
    {
        // Preserve the exact UTC periods charged at reservation time, even if settlement crosses a boundary.
        return [
            ['type' => 'daily', 'start' => Carbon::parse($attempt->daily_period_start)->toDateString()],
            ['type' => 'monthly', 'start' => Carbon::parse($attempt->monthly_period_start)->toDateString()],
        ];
    }
}
