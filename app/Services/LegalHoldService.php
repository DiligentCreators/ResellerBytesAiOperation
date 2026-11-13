<?php

namespace App\\Services;

use App\\Models\\LegalHold;
use App\\Models\\User;
use App\\Services\\Audit\\AuditEventWriter;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;
use Illuminate\\Validation\\ValidationException;
use RuntimeException;

final class LegalHoldService
{
    private const SUBJECT_TABLES = [
        'diagnostic_run' => 'diagnostic_runs',
        'diagnostic_finding' => 'diagnostic_findings',
        'diagnostic_recommendation' => 'diagnostic_recommendations',
    ];

    public function __construct(private readonly AuditEventWriter $audit) {}

    public function place(User $owner, string $subjectType, string $subjectId, string $reason, string $evidenceReference): LegalHold
    {
        $this->assertOwner($owner);
        $this->validateDetails($reason, $evidenceReference);

        return DB::transaction(function () use ($owner, $subjectType, $subjectId, $reason, $evidenceReference): LegalHold {
            $this->lockSubjectRoot($subjectType, $subjectId);
            $this->assertSubject($subjectType, $subjectId);
            $existing = LegalHold::query()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages(['subject' => 'An active legal hold already exists for this record.']);
            }

            $now = now('UTC');
            $hold = LegalHold::query()->create([
                'id' => (string) Str::uuid(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'status' => 'active',
                'placed_by_user_id' => $owner->getKey(),
                'reason' => trim($reason),
                'evidence_reference' => trim($evidenceReference),
                'placed_at' => $now,
            ]);

            $this->audit->append([
                'actor_type' => 'owner',
                'actor_id' => (string) $owner->getKey(),
                'event_type' => 'legal_hold.placed',
                'subject_type' => 'legal_hold',
                'subject_id' => (string) $hold->getKey(),
                'metadata' => [
                    'target_type' => $subjectType,
                    'target_id' => $subjectId,
                    'reason' => trim($reason),
                    'evidence_reference' => trim($evidenceReference),
                    'hold_status' => 'active',
                ],
                'occurred_at' => $now,
            ]);

            return $hold;
        }, 3);
    }

    public function release(User $owner, string $holdId, string $reason, string $evidenceReference): LegalHold
    {
        $this->assertOwner($owner);
        $this->validateDetails($reason, $evidenceReference);

        return DB::transaction(function () use ($owner, $holdId, $reason, $evidenceReference): LegalHold {
            $hold = LegalHold::query()->whereKey($holdId)->lockForUpdate()->first();

            if ($hold === null || $hold->status !== 'active') {
                throw ValidationException::withMessages(['hold' => 'The requested active legal hold was not found.']);
            }

            $now = now('UTC');
            $hold->forceFill([
                'status' => 'released',
                'released_by_user_id' => $owner->getKey(),
                'released_at' => $now,
            ])->save();

            $this->audit->append([
                'actor_type' => 'owner',
                'actor_id' => (string) $owner->getKey(),
                'event_type' => 'legal_hold.released',
                'subject_type' => 'legal_hold',
                'subject_id' => (string) $hold->getKey(),
                'metadata' => [
                    'target_type' => $hold->subject_type,
                    'target_id' => $hold->subject_id,
                    'reason' => trim($reason),
                    'evidence_reference' => trim($evidenceReference),
                    'hold_status' => 'released',
                ],
                'occurred_at' => $now,
            ]);

            return $hold->refresh();
        }, 3);
    }

    public function isHeld(string $subjectType, string $subjectId): bool
    {
        $this->assertSubjectType($subjectType);

        if ($this->activeHoldExists($subjectType, $subjectId)) {
            return true;
        }

        if ($subjectType === 'diagnostic_run') {
            $findingIds = DB::table('diagnostic_findings')->where('diagnostic_run_id', $subjectId)->pluck('id');
            if ($findingIds->isEmpty()) {
                return false;
            }

            if (DB::table('legal_holds')->where('status', 'active')->where('subject_type', 'diagnostic_finding')->whereIn('subject_id', $findingIds)->exists()) {
                return true;
            }

            $recommendationIds = DB::table('diagnostic_recommendations')->whereIn('diagnostic_finding_id', $findingIds)->pluck('id');
            return $recommendationIds->isNotEmpty()
                && DB::table('legal_holds')->where('status', 'active')->where('subject_type', 'diagnostic_recommendation')->whereIn('subject_id', $recommendationIds)->exists();
        }

        if ($subjectType === 'diagnostic_finding') {
            $recommendationIds = DB::table('diagnostic_recommendations')->where('diagnostic_finding_id', $subjectId)->pluck('id');
            return $recommendationIds->isNotEmpty()
                && DB::table('legal_holds')->where('status', 'active')->where('subject_type', 'diagnostic_recommendation')->whereIn('subject_id', $recommendationIds)->exists();
        }

        return false;
    }

    public function assertOwner(User $user): void
    {
        $configuredEmail = mb_strtolower(trim((string) config('operations.owner_email', '')));
        if ($configuredEmail === '' || ! hash_equals($configuredEmail, mb_strtolower(trim((string) $user->email)))) {
            throw new RuntimeException('legal_hold_owner_not_authorized');
        }

        if ($user->email_verified_at === null || $user->two_factor_confirmed_at === null || blank($user->two_factor_secret)) {
            throw new RuntimeException('legal_hold_owner_must_have_verified_email_and_enrolled_mfa');
        }
    }

    /** Lock the diagnostic run row so cleanup and hold placement serialize on the same root record. */
    private function lockSubjectRoot(string $subjectType, string $subjectId): void
    {
        $table = $this->assertSubjectType($subjectType);
        $subject = DB::table($table)->where('id', $subjectId)->first();
        if ($subject === null) {
            throw ValidationException::withMessages(['subject' => 'The target record does not exist.']);
        }

        $runId = match ($subjectType) {
            'diagnostic_run' => $subjectId,
            'diagnostic_finding' => (string) $subject->diagnostic_run_id,
            'diagnostic_recommendation' => (string) DB::table('diagnostic_findings')->where('id', $subject->diagnostic_finding_id)->value('diagnostic_run_id'),
        };

        if ($runId === '' || ! DB::table('diagnostic_runs')->where('id', $runId)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['subject' => 'The target record or its diagnostic run no longer exists.']);
        }
    }

    private function assertSubject(string $subjectType, string $subjectId): void
    {
        $table = $this->assertSubjectType($subjectType);
        if ($subjectId === '' || ! DB::table($table)->where('id', $subjectId)->exists()) {
            throw ValidationException::withMessages(['subject' => 'The target record does not exist.']);
        }
    }

    private function assertSubjectType(string $subjectType): string
    {
        if (! isset(self::SUBJECT_TABLES[$subjectType])) {
            throw ValidationException::withMessages(['subject_type' => 'Unsupported legal-hold target type.']);
        }

        return self::SUBJECT_TABLES[$subjectType];
    }

    private function activeHoldExists(string $subjectType, string $subjectId): bool
    {
        return DB::table('legal_holds')->where('subject_type', $subjectType)->where('subject_id', $subjectId)->where('status', 'active')->exists();
    }

    private function validateDetails(string $reason, string $evidenceReference): void
    {
        if (mb_strlen(trim($reason)) < 10 || mb_strlen(trim($reason)) > 500 || mb_strlen(trim($evidenceReference)) < 3 || mb_strlen(trim($evidenceReference)) > 255) {
            throw ValidationException::withMessages(['reason' => 'A 10-500 character reason and 3-255 character evidence reference are required.']);
        }
    }
}
