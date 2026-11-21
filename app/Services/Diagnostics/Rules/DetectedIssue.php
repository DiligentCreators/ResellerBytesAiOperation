<?php

namespace App\Services\Diagnostics\Rules;

final readonly class DetectedIssue
{
    /** @var array<string, scalar|array<mixed>|null> */
    public array $evidence;

    /** @var list<int> */
    public array $affectedIds;

    /**
     * @param  array<string, scalar|array<mixed>|null>  $evidence
     * @param  list<int>  $affectedIds
     */
    public function __construct(
        public string $key,
        public string $severity,
        public ?int $affectedCount,
        array $evidence,
        array $affectedIds = [],
    ) {
        $this->affectedIds = array_values(array_slice(array_map(
            static fn (mixed $id): int => (int) $id,
            $affectedIds,
        ), 0, 50));
        $this->evidence = self::boundEvidence($evidence);
    }

    /**
     * @param  array<string, scalar|array<mixed>|null>  $evidence
     * @return array<string, scalar|array<mixed>|null>
     */
    private static function boundEvidence(array $evidence): array
    {
        $bounded = [];
        $count = 0;

        foreach ($evidence as $path => $value) {
            if ($count >= 20) {
                break;
            }

            if (! is_string($path) || $path === '') {
                continue;
            }

            $bounded[substr($path, 0, 200)] = self::boundValue($value);
            $count++;
        }

        return $bounded;
    }

    private static function boundValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return substr($value, 0, 200);
        }

        if (is_array($value)) {
            $out = [];
            foreach (array_slice($value, 0, 20, true) as $key => $item) {
                $out[is_string($key) ? substr($key, 0, 64) : $key] = is_scalar($item) || $item === null
                    ? (is_string($item) ? substr($item, 0, 200) : $item)
                    : '[omitted]';
            }

            return $out;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[omitted]';
    }
}
