<?php

namespace App\Data;

use DateTimeImmutable;

final readonly class AuditCheckpointObjectVersion
{
    public function __construct(
        public string $contents,
        public ?string $versionId,
        public string $retentionMode,
        public DateTimeImmutable $retainUntil,
    ) {}
}
