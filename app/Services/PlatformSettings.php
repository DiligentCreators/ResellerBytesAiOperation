<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use App\Support\PlatformSettings\PlatformSettingDefinition;
use App\Support\PlatformSettings\PlatformSettingsRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class PlatformSettings
{
    public const CACHE_KEY = 'platform_settings.all';

    private const MISSING = '__platform_settings_missing__';

    /**
     * Snapshot of config values before DB overlay, keyed by setting key.
     *
     * @var array<string, mixed>
     */
    private array $originalConfig = [];

    private ?bool $tableReady = null;

    public function __construct(
        private readonly PlatformSettingsRegistry $registry,
        private readonly AuditEventWriter $audit,
    ) {}

    /**
     * Resolve a setting: DB → config/env → registry default.
     */
    public function get(string $key): mixed
    {
        $definition = $this->registry->get($key);

        $fromDb = $this->databaseDecoded($key);
        if ($fromDb !== self::MISSING) {
            return $definition->cast($fromDb);
        }

        if ($this->configPathExists($key)) {
            return $definition->cast(config($key));
        }

        return $definition->cast($definition->resolveDefault());
    }

    /**
     * @return 'database'|'env'|'default'
     */
    public function source(string $key): string
    {
        $this->registry->get($key);

        if ($this->databaseDecoded($key) !== self::MISSING) {
            return 'database';
        }

        if ($this->configPathExists($key)) {
            return 'env';
        }

        return 'default';
    }

    /**
     * Persist a setting and invalidate cache. Never audit secret values.
     */
    public function set(string $key, mixed $value, ?User $user = null): void
    {
        $definition = $this->registry->get($key);
        $casted = $definition->cast($value);
        $isSecret = $definition->isSecret();

        $previous = $this->databaseDecoded($key);
        $changed = $previous === self::MISSING || $previous !== $casted;

        PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => PlatformSetting::encodeValue($casted, $isSecret),
                'is_secret' => $isSecret,
                'updated_by_user_id' => $user?->getKey(),
            ],
        );

        $this->invalidateCache();
        $this->applyOverlayForKey($key, $casted);

        if ($changed) {
            $this->audit->append([
                'actor_type' => $user !== null ? 'user' : 'system',
                'actor_id' => $user !== null ? (string) $user->getKey() : null,
                'event_type' => 'platform.settings.updated',
                'subject_type' => PlatformSetting::class,
                'subject_id' => $key,
                'metadata' => [
                    'key' => $key,
                    'changed' => true,
                    'group' => $definition->group,
                    'is_secret' => $isSecret,
                ],
                'ip_address' => request()->ip(),
                'occurred_at' => now(),
            ]);
        }
    }

    /**
     * Remove a DB override so config/env (or registry default) applies again.
     */
    public function forget(string $key, ?User $user = null): void
    {
        $definition = $this->registry->get($key);

        $existing = $this->databaseDecoded($key);
        if ($existing === self::MISSING) {
            return;
        }

        PlatformSetting::query()->where('key', $key)->delete();
        $this->invalidateCache();
        $this->restoreConfigForKey($key);

        $this->audit->append([
            'actor_type' => $user !== null ? 'user' : 'system',
            'actor_id' => $user !== null ? (string) $user->getKey() : null,
            'event_type' => 'platform.settings.reset',
            'subject_type' => PlatformSetting::class,
            'subject_id' => $key,
            'metadata' => [
                'key' => $key,
                'changed' => true,
                'group' => $definition->group,
                'is_secret' => $definition->isSecret(),
            ],
            'ip_address' => request()->ip(),
            'occurred_at' => now(),
        ]);
    }

    /**
     * Overlay DB values onto matching config keys. Safe when the table is missing.
     */
    public function overlayOntoConfig(): void
    {
        if (! $this->tableReady()) {
            return;
        }

        try {
            foreach ($this->cachedRows() as $key => $payload) {
                if (! $this->registry->has($key)) {
                    continue;
                }

                $definition = $this->registry->get($key);
                $this->applyOverlayForKey($key, $definition->cast($payload['value']));
            }
        } catch (Throwable) {
            // Artisan, migrations, and fresh installs must never break.
        }
    }

    /**
     * Presentation payload for the platform settings Inertia page.
     *
     * @return array{
     *     groups: list<array{id: string, label: string}>,
     *     settings: array<string, list<array<string, mixed>>>
     * }
     */
    public function presentation(): array
    {
        $groups = [];
        $settings = [];

        foreach ($this->registry->groups() as $group) {
            $groups[] = [
                'id' => $group,
                'label' => $this->registry->groupLabel($group),
            ];

            $settings[$group] = array_map(
                function (PlatformSettingDefinition $definition): array {
                    $source = $this->source($definition->key);
                    $isSecret = $definition->isSecret();
                    $effective = $this->get($definition->key);

                    $row = [
                        'key' => $definition->key,
                        'label' => $definition->label,
                        'help' => $definition->help,
                        'type' => $definition->type,
                        'source' => $source,
                        'min' => $definition->min,
                        'max' => $definition->max,
                    ];

                    if ($isSecret) {
                        $row['is_set'] = $this->secretIsSet($definition->key, $effective);
                        $row['value'] = null;
                    } else {
                        $row['value'] = $effective;
                        $row['is_set'] = $effective !== null && $effective !== '';
                    }

                    return $row;
                },
                $this->registry->forGroup($group),
            );
        }

        return [
            'groups' => $groups,
            'settings' => $settings,
        ];
    }

    public function invalidateCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->tableReady = null;
    }

    private function secretIsSet(string $key, mixed $effective): bool
    {
        if ($this->databaseDecoded($key) !== self::MISSING) {
            $fromDb = $this->databaseDecoded($key);

            return is_string($fromDb) ? trim($fromDb) !== '' : $fromDb !== null;
        }

        return is_string($effective) ? trim($effective) !== '' : filled($effective);
    }

    private function applyOverlayForKey(string $key, mixed $value): void
    {
        if (! array_key_exists($key, $this->originalConfig) && $this->configPathExists($key)) {
            $this->originalConfig[$key] = config($key);
        }

        config([$key => $value]);
    }

    private function restoreConfigForKey(string $key): void
    {
        if (array_key_exists($key, $this->originalConfig)) {
            config([$key => $this->originalConfig[$key]]);
            unset($this->originalConfig[$key]);

            return;
        }

        // No snapshot: leave config as-is (env/config file value already loaded).
    }

    private function databaseDecoded(string $key): mixed
    {
        if (! $this->tableReady()) {
            return self::MISSING;
        }

        try {
            $rows = $this->cachedRows();
        } catch (Throwable) {
            return self::MISSING;
        }

        if (! array_key_exists($key, $rows)) {
            return self::MISSING;
        }

        return $rows[$key]['value'];
    }

    /**
     * @return array<string, array{value: mixed, is_secret: bool}>
     */
    private function cachedRows(): array
    {
        /** @var array<string, array{value: mixed, is_secret: bool}> $rows */
        $rows = Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            $decoded = [];

            foreach (PlatformSetting::query()->get() as $row) {
                /** @var PlatformSetting $row */
                try {
                    $decoded[$row->key] = [
                        'value' => $row->decodedValue(),
                        'is_secret' => (bool) $row->is_secret,
                    ];
                } catch (Throwable) {
                    // Skip corrupt rows rather than breaking boot.
                }
            }

            return $decoded;
        });

        return $rows;
    }

    private function tableReady(): bool
    {
        if ($this->tableReady !== null) {
            return $this->tableReady;
        }

        try {
            $this->tableReady = Schema::hasTable('platform_settings');
        } catch (Throwable) {
            $this->tableReady = false;
        }

        return $this->tableReady;
    }

    private function configPathExists(string $key): bool
    {
        return Arr::has(config()->all(), $key);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertKnownKey(string $key): PlatformSettingDefinition
    {
        return $this->registry->get($key);
    }
}
