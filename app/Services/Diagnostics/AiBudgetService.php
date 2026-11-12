<?php

namespace App\Services\Diagnostics;

use App\Models\AiBudgetPeriod;
use App\Models\AiUsageAttempt;
use App\Models\DiagnosticRun;
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
            $lockedRun = DiagnosticRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

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

            foreach ($lockedPeriods as $budgetPeriod) {
                $budgetPeriod->increment('reserved_micro_usd', $reservation);
            }

            return AiUsageAttempt::create([
                'id' => (string) Str::uuid(),
                'diagnostic_run_id' => $lockedRun->id,
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

            $input = max(0, (int) ($usage['input_tokens'] ?? 0));
            $cached = max(0, (int) ($usage['cached_input_tokens'] ?? 0));
            $output = max(0, (int) ($usage['output_tokens'] ?? 0));
            if ($cached > $input) {
                throw new RuntimeException('budget_usage_invalid');
            }

            $billableInput = $input - $cached;
            $costNumerator = ($billableInput * $locked->input_tenths_micro_usd_per_token)
                + ($cached * $locked->cached_input_tenths_micro_usd_per_token)
                + ($output * $locked->output_tenths_micro_usd_per_token);
            $cost = intdiv($costNumerator + 9, 10);

            $periods = $this->periodKeysForAttempt($locked);
            foreach ($periods as $period) {
                $budgetPeriod = AiBudgetPeriod::query()
                    ->where('period_type', $period['type'])
                    ->whereDate('period_start', $period['start'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $budgetPeriod->forceFill([
                    'reserved_micro_usd' => max(0, $budgetPeriod->reserved_micro_usd - $locked->reserved_micro_usd),
                    'committed_micro_usd' => $budgetPeriod->committed_micro_usd + $cost,
                ])->save();
            }

            $totalTokens = max(0, (int) ($usage['total_tokens'] ?? 0));
            if ($totalTokens === 0) {
                $totalTokens = $input + $output;
            }

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
        string $evidenceReference,
        string $note,
    ): void {
        DB::transaction(function () use ($attempt, $operatorId, $evidenceReference, $note): void {
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

            $locked->forceFill([
                'status' => 'released',
                'failure_code' => 'reconciled_not_charged',
                'reconciled_at' => now(),
                'reconciled_by' => $operatorId,
                'reconciliation_note' => $note.' [evidence: '.$evidenceReference.']',
            ])->save();

            \\App\\Models\\AuditEvent::create([
                'actor_type' => 'user',
                'actor_id' => $operatorId,
                'event_type' => 'ai_usage_attempt.reconciled_not_charged',
                'subject_type' => 'ai_usage_attempt',
                'subject_id' => $locked->id,
                'metadata' => [
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
                $budgetPeriod->forceFill([
                    'reserved_micro_usd' => max(0, $budgetPeriod->reserved_micro_usd - $locked->reserved_micro_usd),
                ])->save();
            }

            $locked->forceFill(['status' => 'released', 'failure_code' => $failureCode])->save();
        }, 3);
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
