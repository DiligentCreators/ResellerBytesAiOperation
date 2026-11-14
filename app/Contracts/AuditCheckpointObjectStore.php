<?php

namespace App\Contracts;

use App\Data\AuditCheckpointObjectVersion;
use DateTimeImmutable;

interface AuditCheckpointObjectStore
{
    /**
     * Create an immutable object only if the key does not already exist.
     *
     * Implementations must use provider-side conditional-create semantics and
     * enforce COMPLIANCE retention no shorter than the supplied UTC timestamp.
     * They must never silently overwrite or retry an ambiguous create.
     */
    public function createImmutableIfAbsent(
        string $objectKey,
        string $contents,
        DateTimeImmutable $retainUntil
    ): AuditCheckpointObjectVersion;

    /**
     * Resolve a deterministic key after a duplicate or ambiguous create response.
     * The result must identify the provider's exact immutable version.
     */
    public function findImmutableObject(string $objectKey): ?AuditCheckpointObjectVersion;

    /**
     * Read back the exact immutable object version returned by create or lookup.
     */
    public function readImmutableVersion(string $objectKey, string $versionId): AuditCheckpointObjectVersion;
}
