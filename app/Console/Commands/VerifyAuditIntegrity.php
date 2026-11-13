<?php

namespace App\\Console\\Commands;

use App\\Services\\Audit\\AuditEventCanonicalizer;
use Illuminate\\Console\\Command;
use Illuminate\\Support\\Facades\\DB;

class VerifyAuditIntegrity extends Command
{
    protected $signature = 'audit:verify-integrity';

    protected $description = 'Verify the append-only audit event SHA-256 hash chain without modifying it';

    public function handle(AuditEventCanonicalizer $canonicalizer): int
    {
        return DB::transaction(function () use ($canonicalizer): int {
            // Serialize with AuditEventWriter so a concurrent append cannot make
            // the verifier compare a partial event stream with a newer chain head.
            $head = DB::table('audit_chain_heads')->where('id', 1)->lockForUpdate()->first();
            if ($head === null) {
                $this->error('Integrity failure: stored chain head is missing.');

                return self::FAILURE;
            }

            $expectedSequence = 1;
            $previousHash = AuditEventCanonicalizer::GENESIS_HASH;
            $verified = 0;

            foreach (DB::table('audit_events')->orderBy('chain_sequence')->cursor() as $row) {
                $sequence = (int) ($row->chain_sequence ?? 0);
                $eventId = (string) $row->id;

                if ($sequence !== $expectedSequence) {
                    $this->error("Integrity failure at event {$eventId}: expected sequence {$expectedSequence}, found {$sequence}.");

                    return self::FAILURE;
                }

                if ((int) ($row->hash_version ?? 0) !== AuditEventCanonicalizer::VERSION) {
                    $this->error("Integrity failure at event {$eventId}: unsupported hash version.");

                    return self::FAILURE;
                }

                if (! is_string($row->previous_hash) || ! hash_equals($previousHash, $row->previous_hash)) {
                    $this->error("Integrity failure at event {$eventId}: previous-hash link mismatch.");

                    return self::FAILURE;
                }

                $event = [
                    'chain_sequence' => $sequence,
                    'previous_hash' => $row->previous_hash,
                    'actor_type' => $row->actor_type,
                    'actor_id' => $row->actor_id,
                    'event_type' => $row->event_type,
                    'subject_type' => $row->subject_type,
                    'subject_id' => $row->subject_id,
                    'metadata' => $row->metadata,
                    'ip_address' => $row->ip_address,
                    'occurred_at' => $row->occurred_at,
                ];

                $computedHash = $canonicalizer->hash($event);
                if (! is_string($row->event_hash) || ! hash_equals($computedHash, $row->event_hash)) {
                    $this->error("Integrity failure at event {$eventId}: event digest mismatch.");

                    return self::FAILURE;
                }

                $previousHash = $computedHash;
                $expectedSequence++;
                $verified++;
            }

            if ((int) $head->chain_sequence !== $verified
                || ! is_string($head->head_hash)
                || ! hash_equals($previousHash, $head->head_hash)) {
                $this->error('Integrity failure: stored chain head does not match the verified event stream.');

                return self::FAILURE;
            }

            $this->info("Audit integrity verified: {$verified} event(s); head {$previousHash}.");

            return self::SUCCESS;
        }, 3);
    }
}
