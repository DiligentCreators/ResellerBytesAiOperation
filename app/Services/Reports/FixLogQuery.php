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
            'result',
            'minutes_saved',
        ];
    }

    /**
     * @return list<string|int|null>
     */
    public function csvRow(SafeActionExecution $execution): array
    {
        $finding = $execution->finding;
        $issue = ($finding !== null ? $finding->title : null)
            ?? $execution->issue_key
            ?? $execution->action_key;

        $approver = $execution->approver;
        $requester = $execution->requester;
        $approvedBy = ($approver !== null ? $approver->name : null)
            ?? ($requester !== null ? $requester->name : null);

        $integration = $execution->integration;

        return [
            $execution->requested_at?->toIso8601String(),
            $integration !== null ? $integration->name : null,
            $this->csvSafe(is_string($issue) ? $issue : null),
            $execution->mode,
            $this->csvSafe($approvedBy),
            $execution->status,
            $execution->minutes_saved,
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
     *     approved_by: string|null,
     *     result: string,
     *     minutes_saved: int|null
     * }>
     */
    public function presentRows(iterable $executions): array
    {
        $rows = [];
        foreach ($executions as $execution) {
            $finding = $execution->finding;
            $issue = ($finding !== null ? $finding->title : null)
                ?? $execution->issue_key
                ?? $execution->action_key;

            $approver = $execution->approver;
            $requester = $execution->requester;
            $integration = $execution->integration;

            $rows[] = [
                'id' => $execution->id,
                'date' => $execution->requested_at?->toIso8601String(),
                'installation' => $integration !== null ? $integration->name : null,
                'issue' => is_string($issue) ? $issue : null,
                'mode' => $execution->mode,
                'approved_by' => ($approver !== null ? $approver->name : null)
                    ?? ($requester !== null ? $requester->name : null),
                'result' => $execution->status,
                'minutes_saved' => $execution->minutes_saved,
            ];
        }

        return $rows;
    }

    private function csvSafe(?string $value): ?string
    {
        if ($value !== null && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
