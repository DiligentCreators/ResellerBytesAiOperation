<?php

namespace App\Services\Reports;

use App\Models\SafeActionExecution;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class FixLogQuery
{
    /**
     * @param  array{
     *     mode?: string|null,
     *     result?: string|null,
     *     integration_id?: string|null,
     *     from?: string|null,
     *     to?: string|null,
     *     q?: string|null
     * }  $filters
     * @return Builder<SafeActionExecution>
     */
    public function baseQuery(Tenant $tenant, array $filters = []): Builder
    {
        $query = SafeActionExecution::query()
            ->where('tenant_id', $tenant->id)
            ->with([
                'integration:id,name',
                'finding:id,title,issue_key',
                'approver:id,name',
                'rejector:id,name',
                'requester:id,name',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        if (($filters['mode'] ?? null) !== null && $filters['mode'] !== '') {
            $query->where('mode', $filters['mode']);
        }

        if (($filters['result'] ?? null) !== null && $filters['result'] !== '') {
            $query->where('status', $filters['result']);
        }

        if (($filters['integration_id'] ?? null) !== null && $filters['integration_id'] !== '') {
            $query->where('integration_id', $filters['integration_id']);
        }

        if (($filters['from'] ?? null) !== null && $filters['from'] !== '') {
            $from = CarbonImmutable::parse($filters['from'])->utc()->startOfDay();
            $query->where('requested_at', '>=', $from);
        }

        if (($filters['to'] ?? null) !== null && $filters['to'] !== '') {
            $to = CarbonImmutable::parse($filters['to'])->utc()->endOfDay();
            $query->where('requested_at', '<=', $to);
        }

        if (($filters['q'] ?? null) !== null && trim((string) $filters['q']) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('action_key', 'like', $term)
                    ->orWhere('issue_key', 'like', $term)
                    ->orWhereHas('finding', fn (Builder $finding) => $finding->where('title', 'like', $term))
                    ->orWhereHas('integration', fn (Builder $integration) => $integration->where('name', 'like', $term));
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, SafeActionExecution>
     */
    public function paginate(Tenant $tenant, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->baseQuery($tenant, $filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @return list<string>
     */
    public function csvHeaders(): array
    {
        return [
            'date',
            'installation',
            'issue',
            'mode',
            'approved_by',
            'rejected_by',
            'rejected_at',
            'rejection_reason',
            'result',
            'minutes_saved',
        ];
    }

    /**
     * @return list<string|int|null>
     */
    public function csvRow(SafeActionExecution $execution): array
    {
        $row = $this->presentRow($execution);

        return [
            $row['date'],
            $row['installation'],
            $this->csvSafe($row['issue']),
            $row['mode'],
            $this->csvSafe($row['approved_by']),
            $this->csvSafe($row['rejected_by']),
            $row['rejected_at'],
            $this->csvSafe($row['rejection_reason']),
            $row['result'],
            $row['minutes_saved'],
        ];
    }

    /**
     * @param  iterable<int, SafeActionExecution>  $executions
     * @return list<array{
     *     id: string,
     *     date: string|null,
     *     installation: string|null,
     *     issue: string|null,
     *     mode: string,
     *     decision_label: string,
     *     approved_by: string|null,
     *     decided_by: string|null,
     *     rejected_by: string|null,
     *     rejected_at: string|null,
     *     rejection_reason: string|null,
     *     result: string,
     *     minutes_saved: int|null
     * }>
     */
    public function presentRows(iterable $executions): array
    {
        $rows = [];
        foreach ($executions as $execution) {
            $rows[] = $this->presentRow($execution);
        }

        return $rows;
    }

    /**
     * @return array{
     *     id: string,
     *     date: string|null,
     *     installation: string|null,
     *     issue: string|null,
     *     mode: string,
     *     decision_label: string,
     *     approved_by: string|null,
     *     decided_by: string|null,
     *     rejected_by: string|null,
     *     rejected_at: string|null,
     *     rejection_reason: string|null,
     *     result: string,
     *     minutes_saved: int|null
     * }
     */
    public function presentRow(SafeActionExecution $execution): array
    {
        $finding = $execution->finding;
        $issue = ($finding !== null ? $finding->title : null)
            ?? $execution->issue_key
            ?? $execution->action_key;

        $integration = $execution->integration;
        $isRejection = $this->isRejectionStatus($execution->status);
        $rejectedBy = $isRejection ? $this->rejectorName($execution) : null;
        $approvedBy = $isRejection ? null : $this->approverName($execution);

        return [
            'id' => $execution->id,
            'date' => $isRejection
                ? ($execution->rejected_at?->toIso8601String() ?? $execution->requested_at?->toIso8601String())
                : $execution->requested_at?->toIso8601String(),
            'installation' => $integration !== null ? $integration->name : null,
            'issue' => is_string($issue) ? $issue : null,
            'mode' => $execution->mode,
            'decision_label' => $this->decisionLabel($execution->status),
            'approved_by' => $approvedBy,
            'decided_by' => $isRejection ? $rejectedBy : $approvedBy,
            'rejected_by' => $rejectedBy,
            'rejected_at' => $isRejection ? $execution->rejected_at?->toIso8601String() : null,
            'rejection_reason' => $isRejection ? $execution->rejection_reason : null,
            'result' => $execution->status,
            'minutes_saved' => $execution->minutes_saved,
        ];
    }

    private function isRejectionStatus(string $status): bool
    {
        return in_array($status, [
            SafeActionExecution::STATUS_REJECTED,
            SafeActionExecution::STATUS_CANCELLED,
        ], true);
    }

    private function decisionLabel(string $status): string
    {
        return match ($status) {
            SafeActionExecution::STATUS_REJECTED => 'Rejected by',
            SafeActionExecution::STATUS_CANCELLED => 'Cancelled by',
            default => 'Approved by',
        };
    }

    private function rejectorName(SafeActionExecution $execution): ?string
    {
        $rejector = $execution->rejector;

        return $rejector !== null ? $rejector->name : null;
    }

    private function approverName(SafeActionExecution $execution): ?string
    {
        $approver = $execution->approver;
        if ($approver !== null) {
            return $approver->name;
        }

        $requester = $execution->requester;

        return $requester !== null ? $requester->name : null;
    }

    private function csvSafe(?string $value): ?string
    {
        if ($value !== null && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
