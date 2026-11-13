<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AuditEventWriter
{
    public function __construct(private readonly AuditEventCanonicalizer $canonicalizer) {}

    /**
     * @param array{actor_type?: ?string, actor_id?: ?string, event_type: string, subject_type?: ?string, subject_id?: ?string, metadata?: array|null, ip_address?: ?string, occurred_at: mixed} $attributes
     */
    public function append(array $attributes): AuditEvent
    {
        return DB::transaction(function () use ($attributes): AuditEvent {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::table('audit_chain_heads')->where('id', 1)->update(['updated_at' => now()]);
            }

            $head = DB::table('audit_chain_heads')->where('id', 1)->lockForUpdate()->first();

            if (! $head) {
                throw new RuntimeException('audit_chain_head_missing');
            }

            $sequence = (int) $head->chain_sequence + 1;
            $previousHash = (string) $head->head_hash;
            $event = [
                'chain_sequence' => $sequence,
                'previous_hash' => $previousHash,
                'actor_type' => $attributes['actor_type'] ?? null,
                'actor_id' => isset($attributes['actor_id']) ? (string) $attributes['actor_id'] : null,
                'event_type' => $attributes['event_type'],
                'subject_type' => $attributes['subject_type'] ?? null,
                'subject_id' => isset($attributes['subject_id']) ? (string) $attributes['subject_id'] : null,
                'metadata' => json_encode($attributes['metadata'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'ip_address' => $attributes['ip_address'] ?? null,
                'occurred_at' => \Carbon\CarbonImmutable::parse($attributes['occurred_at'])->utc()->format('Y-m-d H:i:s.u'),
            ];
            $event['hash_version'] = AuditEventCanonicalizer::VERSION;
            $event['event_hash'] = $this->canonicalizer->hash($event);

            DB::table('audit_events')->insert($event);

            $updated = DB::table('audit_chain_heads')
                ->where('id', 1)
                ->where('chain_sequence', (int) $head->chain_sequence)
                ->where('head_hash', $previousHash)
                ->update([
                    'chain_sequence' => $sequence,
                    'head_hash' => $event['event_hash'],
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new RuntimeException('audit_chain_head_race');
            }

            return AuditEvent::query()->where('chain_sequence', $sequence)->firstOrFail();
        }, 5);
    }
}
