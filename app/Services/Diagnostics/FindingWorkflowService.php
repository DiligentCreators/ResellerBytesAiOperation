<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FindingWorkflowService
{
    public function __construct(private readonly AuditEventWriter $audit) {}

    /**
     * @param  array{status?: string, assignee_user_id?: int|null, status_note?: string|null}  $input
     */
    public function update(DiagnosticFinding $finding, User $actor, array $input): DiagnosticFinding
    {
        return DB::transaction(function () use ($finding, $actor, $input): DiagnosticFinding {
            /** @var DiagnosticFinding $locked */
            $locked = DiagnosticFinding::query()
                ->whereKey($finding->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = [
                'status' => $locked->status,
                'assignee_user_id' => $locked->assignee_user_id,
                'status_note' => $locked->status_note,
            ];

            if (array_key_exists('status', $input) && $input['status'] !== null) {
                $status = (string) $input['status'];
                if (! in_array($status, DiagnosticFinding::STATUSES, true)) {
                    throw new InvalidArgumentException('Invalid finding status.');
                }

                $locked->status = $status;
                $locked->status_updated_at = Carbon::now();
                $locked->resolved_at = in_array($status, DiagnosticFinding::CLOSED_STATUSES, true)
                    ? ($locked->resolved_at ?? Carbon::now())
                    : null;
            }

            if (array_key_exists('assignee_user_id', $input)) {
                $assigneeId = $input['assignee_user_id'];
                if ($assigneeId !== null) {
                    $this->assertAssignableMember($locked, (int) $assigneeId);
                }
                $locked->assignee_user_id = $assigneeId;
            }

            if (array_key_exists('status_note', $input)) {
                $locked->status_note = $input['status_note'];
            }

            $locked->save();

            $this->audit->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->id,
                'event_type' => 'diagnostic_finding.workflow_updated',
                'subject_type' => 'diagnostic_finding',
                'subject_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'metadata' => [
                    'before' => $before,
                    'after' => [
                        'status' => $locked->status,
                        'assignee_user_id' => $locked->assignee_user_id,
                        'status_note' => $locked->status_note,
                    ],
                ],
                'occurred_at' => now(),
            ]);

            return $locked->fresh(['assignee', 'recommendations', 'diagnosticRun.integration']) ?? $locked;
        });
    }

    private function assertAssignableMember(DiagnosticFinding $finding, int $userId): void
    {
        if ($finding->tenant_id === null) {
            $finding->loadMissing('diagnosticRun.integration');
            $ownerId = $finding->diagnosticRun?->integration?->owner_user_id;
            if ($ownerId === null || (int) $ownerId !== $userId) {
                throw new InvalidArgumentException('Assignee must be the integration owner for legacy findings.');
            }

            return;
        }

        $isActiveMember = TenantMembership::query()
            ->where('tenant_id', $finding->tenant_id)
            ->where('user_id', $userId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereIn('role', [
                TenantMembership::ROLE_OWNER,
                TenantMembership::ROLE_ADMIN,
                TenantMembership::ROLE_OPERATOR,
                TenantMembership::ROLE_VIEWER,
            ])
            ->exists();

        if (! $isActiveMember) {
            throw new InvalidArgumentException('Assignee must be an active diagnostics-capable tenant member.');
        }
    }
}
