<?php

namespace App\Services\Diagnostics\Rules;

use App\Services\Diagnostics\WhmcsIssueCatalog;
use App\Services\PlatformSettings;
use Illuminate\Support\Carbon;

abstract class AbstractIssueRule implements IssueRule
{
    public function __construct(protected readonly PlatformSettings $settings) {}

    protected function catalogSeverity(string $key): string
    {
        $severity = WhmcsIssueCatalog::getString($key, 'severity', 'warning');

        return is_string($severity) ? $severity : 'warning';
    }

    protected function intSetting(string $key, int $default): int
    {
        try {
            $value = $this->settings->get($key);
        } catch (\Throwable) {
            $value = config($key, $default);
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function floatSetting(string $key, float $default): float
    {
        try {
            $value = $this->settings->get($key);
        } catch (\Throwable) {
            $value = config($key, $default);
        }

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    protected function arraySetting(string $key): array
    {
        try {
            $value = $this->settings->get($key);
        } catch (\Throwable) {
            $value = config($key, []);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<int>
     */
    protected function idList(array $block, string $key): array
    {
        $ids = $block[$key] ?? null;
        if (! is_array($ids)) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $normalized[] = (int) $id;
            }
        }

        return array_values(array_slice(array_unique($normalized), 0, 50));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function whmcsBlock(array $payload, string $key): ?array
    {
        $whmcs = $payload['whmcs'] ?? null;
        if (! is_array($whmcs)) {
            return null;
        }

        $block = $whmcs[$key] ?? null;

        return is_array($block) ? $block : null;
    }

    protected function parseUtc(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Compare dotted versions: returns -1, 0, or 1.
     */
    protected function compareVersions(string $left, string $right): int
    {
        $normalize = static function (string $version): string {
            $version = strtolower(trim($version));
            $version = preg_replace('/[^0-9.].*$/', '', $version) ?? $version;

            return $version === '' ? '0' : $version;
        };

        return version_compare($normalize($left), $normalize($right));
    }

    protected function majorMinor(string $version): ?string
    {
        if (! preg_match('/\A(\d+)\.(\d+)/', trim($version), $matches)) {
            return null;
        }

        return $matches[1].'.'.$matches[2];
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @return array{count: int, ids: list<int>}|null
     */
    protected function countAggregate(array $aggregate, string $idsKey): ?array
    {
        if (! array_key_exists('count', $aggregate) || ! is_numeric($aggregate['count'])) {
            return null;
        }

        $count = (int) $aggregate['count'];
        if ($count < 1) {
            return null;
        }

        return [
            'count' => $count,
            'ids' => $this->idList($aggregate, $idsKey),
        ];
    }
}
