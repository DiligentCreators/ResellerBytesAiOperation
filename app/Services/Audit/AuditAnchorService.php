<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use SodiumException;

final class AuditAnchorService
{
    private const VERSION = 1;

    private const DOMAIN = "resellerbytes.audit-anchor.v1\n";

    public function __construct(private readonly AuditEventCanonicalizer $canonicalizer) {}

    /**
     * @throws JsonException
     * @throws SodiumException
     */
    public function createCheckpoint(?string $previousCheckpointSha256 = null): string
    {
        $this->assertPreviousDigest($previousCheckpointSha256);
        $secretKey = $this->decodeKey(config('audit.anchor.private_key'), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'AUDIT_ANCHOR_PRIVATE_KEY');
        $chain = $this->verifiedChainSnapshot();

        $payload = [
            'anchor_version' => self::VERSION,
            'environment' => app()->environment(),
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'chain_sequence' => $chain['sequence'],
            'chain_head_hash' => $chain['head_hash'],
            'previous_checkpoint_sha256' => $previousCheckpointSha256,
        ];

        $message = self::DOMAIN.$this->encodePayload($payload);
        $signature = sodium_crypto_sign_detached($message, $secretKey);
        $envelope = [
            'payload' => $payload,
            'signature' => base64_encode($signature),
        ];

        return json_encode(
            $envelope,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )."\n";
    }

    /**
     * @return array{checkpoint_sha256: string, anchor_sequence: int, local_sequence: int, unanchored_tail: int, previous_checkpoint_sha256: ?string}
     *
     * @throws JsonException
     * @throws SodiumException
     */
    public function verifyCheckpoint(string $checkpointBytes, ?string $expectedPreviousCheckpointSha256 = null): array
    {
        $this->assertPreviousDigest($expectedPreviousCheckpointSha256);
        $envelope = json_decode($checkpointBytes, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($envelope) || ! is_array($envelope['payload'] ?? null) || ! is_string($envelope['signature'] ?? null)) {
            throw new RuntimeException('audit_anchor_invalid_envelope');
        }

        $payload = $envelope['payload'];
        $this->assertPayload($payload);
        $publicKey = $this->decodeKey(config('audit.anchor.public_key'), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'AUDIT_ANCHOR_PUBLIC_KEY');
        $signature = base64_decode($envelope['signature'], true);

        if (! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('audit_anchor_invalid_signature_encoding');
        }

        $message = self::DOMAIN.$this->encodePayload($payload);
        if (! sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new RuntimeException('audit_anchor_signature_invalid');
        }

        if ($expectedPreviousCheckpointSha256 !== null
            && ! hash_equals($expectedPreviousCheckpointSha256, (string) ($payload['previous_checkpoint_sha256'] ?? ''))) {
            throw new RuntimeException('audit_anchor_previous_checkpoint_mismatch');
        }

        $chain = $this->verifiedChainSnapshot((int) $payload['chain_sequence']);
        if (! hash_equals((string) $payload['chain_head_hash'], $chain['anchored_hash'])) {
            throw new RuntimeException('audit_anchor_database_prefix_mismatch');
        }

        return [
            'checkpoint_sha256' => hash('sha256', $checkpointBytes),
            'anchor_sequence' => (int) $payload['chain_sequence'],
            'local_sequence' => $chain['sequence'],
            'unanchored_tail' => $chain['sequence'] - (int) $payload['chain_sequence'],
            'previous_checkpoint_sha256' => $payload['previous_checkpoint_sha256'],
        ];
    }

    /**
     * @return array{sequence: int, head_hash: string, anchored_hash: string}
     */
    private function verifiedChainSnapshot(?int $anchorSequence = null): array
    {
        return DB::transaction(function () use ($anchorSequence): array {
            $head = DB::table('audit_chain_heads')->where('id', 1)->lockForUpdate()->first();
            if ($head === null) {
                throw new RuntimeException('audit_chain_head_missing');
            }

            $expectedSequence = 1;
            $previousHash = AuditEventCanonicalizer::GENESIS_HASH;
            $anchoredHash = $anchorSequence === 0 ? AuditEventCanonicalizer::GENESIS_HASH : null;

            foreach (DB::table('audit_events')->orderBy('chain_sequence')->cursor() as $row) {
                $sequence = (int) ($row->chain_sequence ?? 0);
                if ($sequence !== $expectedSequence) {
                    throw new RuntimeException('audit_chain_sequence_gap');
                }

                if ((int) ($row->hash_version ?? 0) !== AuditEventCanonicalizer::VERSION
                    || ! is_string($row->previous_hash)
                    || ! hash_equals($previousHash, $row->previous_hash)) {
                    throw new RuntimeException('audit_chain_previous_hash_mismatch');
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

                $computedHash = $this->canonicalizer->hash($event);
                if (! is_string($row->event_hash) || ! hash_equals($computedHash, $row->event_hash)) {
                    throw new RuntimeException('audit_chain_event_hash_mismatch');
                }

                $previousHash = $computedHash;
                if ($anchorSequence !== null && $sequence === $anchorSequence) {
                    $anchoredHash = $computedHash;
                }

                $expectedSequence++;
            }

            $verifiedSequence = $expectedSequence - 1;
            if ((int) $head->chain_sequence !== $verifiedSequence
                || ! is_string($head->head_hash)
                || ! hash_equals($previousHash, $head->head_hash)) {
                throw new RuntimeException('audit_chain_head_mismatch');
            }

            if ($anchorSequence !== null && ($anchorSequence > $verifiedSequence || $anchoredHash === null)) {
                throw new RuntimeException('audit_anchor_ahead_of_local_database');
            }

            return [
                'sequence' => $verifiedSequence,
                'head_hash' => $previousHash,
                'anchored_hash' => $anchoredHash ?? $previousHash,
            ];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function encodePayload(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * @throws JsonException
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayload(array $payload): void
    {
        if (($payload['anchor_version'] ?? null) !== self::VERSION
            || ($payload['environment'] ?? null) !== app()->environment()
            || ! is_string($payload['created_at'] ?? null)
            || ! is_int($payload['chain_sequence'] ?? null)
            || $payload['chain_sequence'] < 0
            || ! is_string($payload['chain_head_hash'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $payload['chain_head_hash']) !== 1) {
            throw new RuntimeException('audit_anchor_invalid_payload');
        }

        $previousDigest = $payload['previous_checkpoint_sha256'] ?? null;
        if ($previousDigest !== null && ! is_string($previousDigest)) {
            throw new RuntimeException('audit_anchor_invalid_previous_digest');
        }

        $this->assertPreviousDigest($previousDigest);
    }

    private function assertPreviousDigest(?string $digest): void
    {
        if ($digest !== null && preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
            throw new RuntimeException('audit_anchor_invalid_previous_digest');
        }
    }

    /**
     * @return non-empty-string
     */
    private function decodeKey(mixed $encoded, int $expectedLength, string $name): string
    {
        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException($name.'_not_configured');
        }

        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded) || $decoded === '' || strlen($decoded) !== $expectedLength) {
            throw new RuntimeException($name.'_invalid_length_or_encoding');
        }

        return $decoded;
    }
}
