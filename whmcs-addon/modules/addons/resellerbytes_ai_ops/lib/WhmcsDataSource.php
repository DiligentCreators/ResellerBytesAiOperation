<?php

namespace ResellerBytesAiOps;

interface WhmcsDataSource
{
    public function configurationValue(string $setting): ?string;

    public function databaseVersion(): ?string;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public function localApi(string $command, array $params = []): ?array;

    /**
     * @return array{last_daily_run_at: ?string, daily_starts_24h: ?int, daily_completions_24h: ?int}|null
     */
    public function cronAggregates(): ?array;

    /**
     * Raw pending module-queue rows for table fallback.
     *
     * @return list<array<string, mixed>>|null
     */
    public function moduleQueueFromTable(): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function billingAggregates(): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function serviceAggregates(): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function domainAggregates(): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function ticketAggregates(int $thresholdHours): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function mailAggregates(): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function gatewayAggregates(): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function moduleInventory(): ?array;

    /**
     * @return array{suspend_days: ?int, invoice_generation_days: ?int}|null
     */
    public function automationSettings(): ?array;

    /**
     * @return array{admin_dir_default: ?bool, install_dir_present: ?bool}|null
     */
    public function securityFlags(): ?array;

    /**
     * @return array{disk_free_percent: ?float, disk_free_bytes: ?int}|null
     */
    public function hostDiskStats(string $root): ?array;

    /**
     * @return array{db_size_mb: ?float, db_top_tables: list<array{name: string, size_mb: float}>}|null
     */
    public function dbSizeStats(): ?array;
}
