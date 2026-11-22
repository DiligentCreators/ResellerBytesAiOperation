<?php

namespace App\Console\Commands;

use App\Models\DiagnosticFinding;
use App\Models\WhmcsIntegration;
use App\Services\Whmcs\LocalSignedWhmcsRequestDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

#[Signature('whmcs:sample-snapshot {integration? : Integration id (UUID) or first active} {--fixture=schema_1_1_positive : Fixture name under tests/Fixtures/snapshots} {--heartbeat : Also send a signed heartbeat} {--stale : Backdate last_heartbeat_at to simulate a missed heartbeat}')]
#[Description('Dispatch a fixture snapshot through the real signed WHMCS API path (local smoke tests only)')]
class SampleWhmcsSnapshotCommand extends Command
{
    public function handle(LocalSignedWhmcsRequestDispatcher $dispatcher): int
    {
        if (app()->environment('production')) {
            $this->error('whmcs:sample-snapshot refuses to run in production.');

            return self::FAILURE;
        }

        $integration = $this->resolveIntegration();
        if ($integration === null) {
            return self::FAILURE;
        }

        $fixture = $this->loadFixture();
        if ($fixture === null) {
            return self::FAILURE;
        }

        $beforeFindings = $this->findingSnapshot($integration);

        try {
            $result = $dispatcher->submitDiagnosticSnapshot(
                $integration,
                $fixture['payload'],
                $fixture['schema_version'],
                $fixture['addon_version'],
            );
        } catch (Throwable $exception) {
            $this->error('Failed to dispatch sample snapshot: '.$exception->getMessage());

            return self::FAILURE;
        }

        $runId = data_get($result['json'], 'data.run_id');
        $runId = is_string($runId) ? $runId : null;

        $findingDelta = $this->describeFindingDelta($integration, $beforeFindings);

        $heartbeatStatus = null;
        if ($this->option('heartbeat')) {
            $heartbeatOverrides = $this->heartbeatOverridesFromPayload($fixture['payload']);

            try {
                $heartbeatResult = $dispatcher->submitHeartbeat($integration->fresh() ?? $integration, $heartbeatOverrides);
                $heartbeatStatus = $heartbeatResult['status'];
            } catch (Throwable $exception) {
                $this->error('Snapshot accepted but heartbeat failed: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        if ($this->option('stale')) {
            $staleMinutes = max(1, (int) config('monitoring.heartbeat_stale_minutes', 30));
            ($integration->fresh() ?? $integration)->forceFill([
                'last_heartbeat_at' => now('UTC')->subMinutes($staleMinutes + 5),
            ])->save();
        }

        $fresh = $integration->fresh() ?? $integration;

        $this->info('Sample snapshot dispatched.');
        $this->line('HTTP status: '.$result['status']);
        $this->line('Run ID: '.($runId ?? '(none)'));
        $this->line('Findings by source: '.$findingDelta);
        if ($heartbeatStatus !== null) {
            $this->line('Heartbeat HTTP status: '.$heartbeatStatus);
        }
        $this->line('Heartbeat state: '.(string) ($fresh->heartbeat_state ?? 'unknown'));
        if ($this->option('stale')) {
            $this->line('last_heartbeat_at backdated for missed-heartbeat simulation.');
        }

        if (! in_array($result['status'], [200, 202], true)) {
            $this->error('Snapshot request did not succeed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveIntegration(): ?WhmcsIntegration
    {
        $argument = $this->argument('integration');
        $integrationId = is_string($argument) ? trim($argument) : '';

        if ($integrationId !== '') {
            if (! Str::isUuid($integrationId)) {
                $this->error('The integration argument must be a valid integration UUID.');

                return null;
            }

            $integration = WhmcsIntegration::query()
                ->whereKey($integrationId)
                ->where('status', 'active')
                ->first();

            if ($integration === null) {
                $this->error('No active integration found for the given id.');

                return null;
            }

            return $integration;
        }

        $integration = WhmcsIntegration::query()
            ->where('status', 'active')
            ->orderBy('created_at')
            ->first();

        if ($integration === null) {
            $this->error('No active integration found. Run whmcs:sample-integration first.');

            return null;
        }

        return $integration;
    }

    /**
     * @return array{payload: array<string, mixed>, schema_version: string, addon_version: string}|null
     */
    private function loadFixture(): ?array
    {
        $fixtureOption = $this->option('fixture');
        $fixtureName = is_string($fixtureOption) ? trim($fixtureOption) : '';

        if ($fixtureName === '' || str_contains($fixtureName, '/') || str_contains($fixtureName, '\\') || str_contains($fixtureName, '..')) {
            $this->error('Provide a fixture name such as schema_1_1_positive.');

            return null;
        }

        if (str_ends_with($fixtureName, '.json')) {
            $fixtureName = substr($fixtureName, 0, -5);
        }

        $path = base_path('tests/Fixtures/snapshots/'.$fixtureName.'.json');
        if (! is_file($path)) {
            $this->error('Fixture not found: tests/Fixtures/snapshots/'.$fixtureName.'.json');

            return null;
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('Fixture is not valid JSON.');

            return null;
        }

        if (! is_array($payload)) {
            $this->error('Fixture must decode to a JSON object.');

            return null;
        }

        $schemaVersion = str_starts_with($fixtureName, 'schema_1_0') ? '1.0' : '1.1';
        $addonVersion = $schemaVersion === '1.0' ? '1.0.0' : LocalSignedWhmcsRequestDispatcher::ADDON_VERSION;

        return [
            'payload' => $payload,
            'schema_version' => $schemaVersion,
            'addon_version' => $addonVersion,
        ];
    }

    /**
     * @return Collection<string, array{source: string, updated_at: string|null}>
     */
    private function findingSnapshot(WhmcsIntegration $integration): Collection
    {
        $rows = [];

        $findings = DiagnosticFinding::query()
            ->whereHas('diagnosticRun', fn ($query) => $query->where('integration_id', $integration->id))
            ->get(['id', 'source', 'updated_at']);

        foreach ($findings as $finding) {
            $rows[(string) $finding->id] = [
                'source' => (string) $finding->source,
                'updated_at' => $finding->updated_at?->toIso8601String(),
            ];
        }

        return collect($rows);
    }

    /**
     * @param  Collection<string, array{source: string, updated_at: string|null}>  $before
     */
    private function describeFindingDelta(WhmcsIntegration $integration, Collection $before): string
    {
        $after = DiagnosticFinding::query()
            ->whereHas('diagnosticRun', fn ($query) => $query->where('integration_id', $integration->id))
            ->get(['id', 'source', 'updated_at']);

        $counts = [];

        foreach ($after as $finding) {
            $source = (string) $finding->source;
            $counts[$source] ??= ['created' => 0, 'updated' => 0];

            if (! $before->has($finding->id)) {
                $counts[$source]['created']++;

                continue;
            }

            $previous = $before->get($finding->id);
            $previousUpdated = is_array($previous) ? ($previous['updated_at'] ?? null) : null;
            $currentUpdated = $finding->updated_at?->toIso8601String();

            if ($previousUpdated !== $currentUpdated) {
                $counts[$source]['updated']++;
            }
        }

        if ($counts === []) {
            return '(none)';
        }

        ksort($counts);

        return collect($counts)
            ->map(fn (array $row, string $source): string => sprintf(
                '%s created=%d updated=%d',
                $source,
                $row['created'],
                $row['updated'],
            ))
            ->implode('; ');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function heartbeatOverridesFromPayload(array $payload): array
    {
        $overrides = [];

        $whmcsVersion = data_get($payload, 'system.whmcs_version');
        if (is_string($whmcsVersion) && $whmcsVersion !== '') {
            $overrides['whmcs_version'] = $whmcsVersion;
        }

        $phpVersion = data_get($payload, 'system.web_php_version');
        if (is_string($phpVersion) && $phpVersion !== '') {
            $overrides['cli_php_version'] = $phpVersion;
        }

        return $overrides;
    }
}
