<?php

namespace App\Support\PlatformSettings;

use InvalidArgumentException;

final class PlatformSettingsRegistry
{
    /**
     * @var array<string, PlatformSettingDefinition>|null
     */
    private ?array $definitions = null;

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        return [
            'security',
            'monitoring',
            'fixes',
            'reports',
            'alerts',
            'ai',
            'retention',
            'billing',
            'branding',
        ];
    }

    public function groupLabel(string $group): string
    {
        return match ($group) {
            'security' => 'Security',
            'monitoring' => 'Monitoring',
            'fixes' => 'Fixes',
            'reports' => 'Reports',
            'alerts' => 'Alerts',
            'ai' => 'AI budget',
            'retention' => 'Retention',
            'billing' => 'Billing',
            'branding' => 'Branding',
            default => $group,
        };
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function hasGroup(string $group): bool
    {
        return in_array($group, $this->groups(), true);
    }

    public function get(string $key): PlatformSettingDefinition
    {
        $definition = $this->all()[$key] ?? null;

        if (! $definition instanceof PlatformSettingDefinition) {
            throw new InvalidArgumentException("Unknown platform setting [{$key}].");
        }

        return $definition;
    }

    /**
     * @return list<PlatformSettingDefinition>
     */
    public function forGroup(string $group): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (PlatformSettingDefinition $definition): bool => $definition->group === $group,
        ));
    }

    /**
     * @return array<string, PlatformSettingDefinition>
     */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];

        foreach ($this->buildDefinitions() as $definition) {
            $definitions[$definition->key] = $definition;
        }

        return $this->definitions = $definitions;
    }

    /**
     * @return list<PlatformSettingDefinition>
     */
    private function buildDefinitions(): array
    {
        return [
            // Security
            new PlatformSettingDefinition(
                key: 'security.two_factor_required',
                group: 'security',
                label: 'Require two-factor authentication',
                help: 'Off (default): nobody is redirected for missing 2FA; workspace Require 2FA preferences are stored but not enforced. On: platform operators and members of workspaces with Require 2FA must enroll before continuing. Users who already enabled 2FA are still challenged at login either way.',
                type: PlatformSettingDefinition::TYPE_BOOL,
                default: static fn (): bool => (bool) config('security.two_factor_required', false),
                rules: ['boolean'],
            ),
            new PlatformSettingDefinition(
                key: 'security.registration_open',
                group: 'security',
                label: 'Public registration open',
                help: 'When disabled, public account registration should refuse new sign-ups (enforcement wired later).',
                type: PlatformSettingDefinition::TYPE_BOOL,
                default: static fn (): bool => (bool) config('security.registration_open', true),
                rules: ['boolean'],
            ),

            // Monitoring
            new PlatformSettingDefinition(
                key: 'monitoring.heartbeat_stale_minutes',
                group: 'monitoring',
                label: 'Heartbeat stale minutes',
                help: 'Minutes without a heartbeat before an install is considered stale.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('monitoring.heartbeat_stale_minutes', 30),
                rules: ['integer', 'min:1', 'max:10080'],
                min: 1,
                max: 10080,
            ),
            new PlatformSettingDefinition(
                key: 'monitoring.auto_collect_interval_minutes',
                group: 'monitoring',
                label: 'Auto-collect interval (minutes)',
                help: 'How often the platform may request automatic diagnostic collects.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('monitoring.auto_collect_interval_minutes', 60),
                rules: ['integer', 'min:5', 'max:10080'],
                min: 5,
                max: 10080,
            ),
            new PlatformSettingDefinition(
                key: 'monitoring.max_snapshots_per_install_per_day',
                group: 'monitoring',
                label: 'Max snapshots per install per day',
                help: 'Hard cap on diagnostic snapshots accepted per WHMCS install each day.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('monitoring.max_snapshots_per_install_per_day', 48),
                rules: ['integer', 'min:1', 'max:1000'],
                min: 1,
                max: 1000,
            ),
            new PlatformSettingDefinition(
                key: 'monitoring.addon_latest_version',
                group: 'monitoring',
                label: 'WHMCS addon latest version',
                help: 'Published addon version string used for update checks. Defaults to the packaged addon version.',
                type: PlatformSettingDefinition::TYPE_STRING,
                default: static fn (): string => (string) (config('monitoring.addon_latest_version') ?: config('services.whmcs_addon.version', '1.1.0')),
                rules: ['string', 'max:40'],
            ),

            // Fixes
            new PlatformSettingDefinition(
                key: 'fixes.enabled',
                group: 'fixes',
                label: 'Fixes enabled',
                help: 'Master switch for allowlisted one-click and guided fixes.',
                type: PlatformSettingDefinition::TYPE_BOOL,
                default: static fn (): bool => (bool) config('fixes.enabled', true),
                rules: ['boolean'],
            ),
            new PlatformSettingDefinition(
                key: 'fixes.bulk_max_items',
                group: 'fixes',
                label: 'Bulk fix max items',
                help: 'Maximum findings that may be queued in a single bulk fix request.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('fixes.bulk_max_items', 50),
                rules: ['integer', 'min:1', 'max:500'],
                min: 1,
                max: 500,
            ),
            new PlatformSettingDefinition(
                key: 'fixes.auto_fix_daily_cap',
                group: 'fixes',
                label: 'Auto-fix daily cap',
                help: 'Maximum automatic fixes a workspace may run per day.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('fixes.auto_fix_daily_cap', 20),
                rules: ['integer', 'min:0', 'max:1000'],
                min: 0,
                max: 1000,
            ),

            // Reports
            new PlatformSettingDefinition(
                key: 'reports.weekly_day',
                group: 'reports',
                label: 'Weekly report day',
                help: 'ISO day of week for weekly digests (1 = Monday … 7 = Sunday).',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('reports.weekly_day', 1),
                rules: ['integer', 'min:1', 'max:7'],
                min: 1,
                max: 7,
            ),
            new PlatformSettingDefinition(
                key: 'reports.weekly_time',
                group: 'reports',
                label: 'Weekly report time',
                help: 'Local time (HH:MM, 24-hour) to send weekly reports.',
                type: PlatformSettingDefinition::TYPE_STRING,
                default: static fn (): string => (string) config('reports.weekly_time', '09:00'),
                rules: ['string', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            ),
            new PlatformSettingDefinition(
                key: 'reports.value_report_days_before_renewal',
                group: 'reports',
                label: 'Value report days before renewal',
                help: 'How many days before renewal to send the value report.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('reports.value_report_days_before_renewal', 3),
                rules: ['integer', 'min:0', 'max:60'],
                min: 0,
                max: 60,
            ),
            new PlatformSettingDefinition(
                key: 'reports.default_hourly_rate_usd',
                group: 'reports',
                label: 'Default hourly rate (USD)',
                help: 'Platform default used when estimating value in reports. Workspaces can override later.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('reports.default_hourly_rate_usd', 25),
                rules: ['integer', 'min:1', 'max:10000'],
                min: 1,
                max: 10000,
            ),

            // Alerts (operations)
            new PlatformSettingDefinition(
                key: 'operations.alerts.enabled',
                group: 'alerts',
                label: 'Operational alerts enabled',
                help: 'Master switch for platform operations alerting.',
                type: PlatformSettingDefinition::TYPE_BOOL,
                default: static fn (): bool => (bool) config('operations.alerts.enabled', false),
                rules: ['boolean'],
            ),
            new PlatformSettingDefinition(
                key: 'operations.alerts.email',
                group: 'alerts',
                label: 'Alert email',
                help: 'Primary email recipient for operational alerts.',
                type: PlatformSettingDefinition::TYPE_EMAIL,
                default: static fn (): ?string => config('operations.alerts.email'),
                rules: ['nullable', 'email:rfc', 'max:255'],
            ),
            new PlatformSettingDefinition(
                key: 'operations.alerts.repeat_interval_minutes',
                group: 'alerts',
                label: 'Alert repeat interval (minutes)',
                help: 'Minimum minutes between repeated alerts for the same condition.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('operations.alerts.repeat_interval_minutes', 15),
                rules: ['integer', 'min:1', 'max:1440'],
                min: 1,
                max: 1440,
            ),
            new PlatformSettingDefinition(
                key: 'operations.monitoring.error_rate.window_minutes',
                group: 'alerts',
                label: 'Error rate window (minutes)',
                help: 'Sliding window used to compute the HTTP error rate threshold.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('operations.monitoring.error_rate.window_minutes', 5),
                rules: ['integer', 'min:1', 'max:1440'],
                min: 1,
                max: 1440,
            ),
            new PlatformSettingDefinition(
                key: 'operations.monitoring.error_rate.threshold_percent',
                group: 'alerts',
                label: 'Error rate threshold (%)',
                help: 'Percentage of 5xx responses that triggers an alert within the window.',
                type: PlatformSettingDefinition::TYPE_FLOAT,
                default: static fn (): float => (float) config('operations.monitoring.error_rate.threshold_percent', 2),
                rules: ['numeric', 'min:0', 'max:100'],
                min: 0,
                max: 100,
            ),
            new PlatformSettingDefinition(
                key: 'operations.monitoring.api_p95_latency_ms',
                group: 'alerts',
                label: 'API p95 latency (ms)',
                help: 'Alert when API p95 latency exceeds this many milliseconds.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('operations.monitoring.api_p95_latency_ms', 1500),
                rules: ['integer', 'min:1', 'max:600000'],
                min: 1,
                max: 600000,
            ),
            new PlatformSettingDefinition(
                key: 'operations.monitoring.backup_max_age_hours',
                group: 'alerts',
                label: 'Backup max age (hours)',
                help: 'Alert when the latest verified backup is older than this many hours.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('operations.monitoring.backup_max_age_hours', 26),
                rules: ['integer', 'min:1', 'max:720'],
                min: 1,
                max: 720,
            ),
            new PlatformSettingDefinition(
                key: 'operations.monitoring.disk.warning_percent',
                group: 'alerts',
                label: 'Disk warning (%)',
                help: 'Disk utilization percent that raises a warning alert.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('operations.monitoring.disk.warning_percent', 80),
                rules: ['integer', 'min:1', 'max:100'],
                min: 1,
                max: 100,
            ),
            new PlatformSettingDefinition(
                key: 'operations.monitoring.disk.critical_percent',
                group: 'alerts',
                label: 'Disk critical (%)',
                help: 'Disk utilization percent that raises a critical alert.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('operations.monitoring.disk.critical_percent', 90),
                rules: ['integer', 'min:1', 'max:100'],
                min: 1,
                max: 100,
            ),
            new PlatformSettingDefinition(
                key: 'operations.monitoring.queue_job_max_age_minutes',
                group: 'alerts',
                label: 'Queue job max age (minutes)',
                help: 'Alert when queued jobs sit longer than this many minutes.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('operations.monitoring.queue_job_max_age_minutes', 15),
                rules: ['integer', 'min:1', 'max:10080'],
                min: 1,
                max: 10080,
            ),
            new PlatformSettingDefinition(
                key: 'operations.monitoring.billing_stale_processing_minutes',
                group: 'alerts',
                label: 'Billing stale processing (minutes)',
                help: 'Alert when billing webhook/outbox processing is stale beyond this many minutes.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('operations.monitoring.billing_stale_processing_minutes', 5),
                rules: ['integer', 'min:1', 'max:1440'],
                min: 1,
                max: 1440,
            ),
            new PlatformSettingDefinition(
                key: 'operations.alerts.slack_webhook_url',
                group: 'alerts',
                label: 'Slack webhook URL',
                help: 'Incoming webhook URL for Slack operational alerts. Stored encrypted; never shown again.',
                type: PlatformSettingDefinition::TYPE_SECRET,
                default: static fn (): ?string => config('operations.alerts.slack_webhook_url'),
                rules: ['nullable', 'string', 'max:2048'],
            ),
            new PlatformSettingDefinition(
                key: 'operations.alerts.discord_webhook_url',
                group: 'alerts',
                label: 'Discord webhook URL',
                help: 'Incoming webhook URL for Discord operational alerts. Stored encrypted; never shown again.',
                type: PlatformSettingDefinition::TYPE_SECRET,
                default: static fn (): ?string => config('operations.alerts.discord_webhook_url'),
                rules: ['nullable', 'string', 'max:2048'],
            ),
            new PlatformSettingDefinition(
                key: 'operations.alerts.critical_whatsapp_webhook_url',
                group: 'alerts',
                label: 'Critical WhatsApp webhook URL',
                help: 'Webhook for critical WhatsApp alerts. Stored encrypted; never shown again.',
                type: PlatformSettingDefinition::TYPE_SECRET,
                default: static fn (): ?string => config('operations.alerts.critical_whatsapp_webhook_url'),
                rules: ['nullable', 'string', 'max:2048'],
            ),
            new PlatformSettingDefinition(
                key: 'operations.alerts.critical_sms_webhook_url',
                group: 'alerts',
                label: 'Critical SMS webhook URL',
                help: 'Webhook for critical SMS alerts. Stored encrypted; never shown again.',
                type: PlatformSettingDefinition::TYPE_SECRET,
                default: static fn (): ?string => config('operations.alerts.critical_sms_webhook_url'),
                rules: ['nullable', 'string', 'max:2048'],
            ),

            // AI budget
            new PlatformSettingDefinition(
                key: 'ai_budget.daily_limit_micro_usd',
                group: 'ai',
                label: 'Daily platform AI budget (micro-USD)',
                help: 'Platform-wide daily AI spend cap in micro-USD (1 USD = 1,000,000).',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('ai_budget.daily_limit_micro_usd', 5_000_000),
                rules: ['integer', 'min:0'],
                min: 0,
            ),
            new PlatformSettingDefinition(
                key: 'ai_budget.monthly_limit_micro_usd',
                group: 'ai',
                label: 'Monthly platform AI budget (micro-USD)',
                help: 'Platform-wide monthly AI spend cap in micro-USD.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('ai_budget.monthly_limit_micro_usd', 50_000_000),
                rules: ['integer', 'min:0'],
                min: 0,
            ),
            new PlatformSettingDefinition(
                key: 'ai_budget.tenant_daily_limit_micro_usd',
                group: 'ai',
                label: 'Tenant daily AI budget (micro-USD)',
                help: 'Per-tenant daily AI spend cap in micro-USD.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('ai_budget.tenant_daily_limit_micro_usd', 5_000_000),
                rules: ['integer', 'min:0'],
                min: 0,
            ),
            new PlatformSettingDefinition(
                key: 'ai_budget.tenant_monthly_limit_micro_usd',
                group: 'ai',
                label: 'Tenant monthly AI budget (micro-USD)',
                help: 'Per-tenant monthly AI spend cap in micro-USD.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('ai_budget.tenant_monthly_limit_micro_usd', 50_000_000),
                rules: ['integer', 'min:0'],
                min: 0,
            ),
            new PlatformSettingDefinition(
                key: 'ai_budget.max_input_bytes',
                group: 'ai',
                label: 'Max AI input bytes',
                help: 'Maximum request payload size accepted by AI helpers.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('ai_budget.max_input_bytes', 65_536),
                rules: ['integer', 'min:1024', 'max:1048576'],
                min: 1024,
                max: 1_048_576,
            ),
            new PlatformSettingDefinition(
                key: 'ai_budget.max_output_tokens',
                group: 'ai',
                label: 'Max AI output tokens',
                help: 'Maximum completion tokens requested from the model.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('ai_budget.max_output_tokens', 3_000),
                rules: ['integer', 'min:1', 'max:128000'],
                min: 1,
                max: 128_000,
            ),

            // Retention
            new PlatformSettingDefinition(
                key: 'diagnostics.retention_cleanup_enabled',
                group: 'retention',
                label: 'Diagnostics retention cleanup enabled',
                help: 'When enabled, the prune command may delete expired diagnostic records (still requires --execute).',
                type: PlatformSettingDefinition::TYPE_BOOL,
                default: static fn (): bool => (bool) config('diagnostics.retention_cleanup_enabled', false),
                rules: ['boolean'],
            ),
            new PlatformSettingDefinition(
                key: 'operations.audit.retention_days',
                group: 'retention',
                label: 'Audit log retention (days)',
                help: 'How long operational audit log artifacts are retained.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('operations.audit.retention_days', 365),
                rules: ['integer', 'min:30', 'max:3650'],
                min: 30,
                max: 3650,
            ),

            // Billing (non-secret)
            new PlatformSettingDefinition(
                key: 'billing.payment_failure_grace_days',
                group: 'billing',
                label: 'Payment failure grace days',
                help: 'Days after a payment failure before subscription access is restricted.',
                type: PlatformSettingDefinition::TYPE_INT,
                default: static fn (): int => (int) config('billing.payment_failure_grace_days', 7),
                rules: ['integer', 'min:1', 'max:30'],
                min: 1,
                max: 30,
            ),
            new PlatformSettingDefinition(
                key: 'billing.invoice_number_prefix',
                group: 'billing',
                label: 'Invoice number prefix',
                help: 'Prefix prepended to generated invoice numbers.',
                type: PlatformSettingDefinition::TYPE_STRING,
                default: static fn (): string => (string) config('billing.invoice_number_prefix', 'RB'),
                rules: ['string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            ),
            new PlatformSettingDefinition(
                key: 'billing.sandbox.enabled',
                group: 'billing',
                label: 'Sandbox billing enabled',
                help: 'Allow the local sandbox payment gateway.',
                type: PlatformSettingDefinition::TYPE_BOOL,
                default: static fn (): bool => filter_var(config('billing.sandbox.enabled'), FILTER_VALIDATE_BOOLEAN),
                rules: ['boolean'],
            ),
            new PlatformSettingDefinition(
                key: 'billing.sandbox.allow_in_production',
                group: 'billing',
                label: 'Allow sandbox in production',
                help: 'Permit the sandbox gateway when the app runs in production. Prefer leaving this off.',
                type: PlatformSettingDefinition::TYPE_BOOL,
                default: static fn (): bool => filter_var(config('billing.sandbox.allow_in_production'), FILTER_VALIDATE_BOOLEAN),
                rules: ['boolean'],
            ),

            // Branding
            new PlatformSettingDefinition(
                key: 'branding.product_name',
                group: 'branding',
                label: 'Product name',
                help: 'Customer-facing product name. Defaults to the application name.',
                type: PlatformSettingDefinition::TYPE_STRING,
                default: static fn (): string => (string) (config('branding.product_name') ?: config('app.name')),
                rules: ['string', 'max:120'],
            ),
            new PlatformSettingDefinition(
                key: 'branding.support_email',
                group: 'branding',
                label: 'Support email',
                help: 'Public support contact email shown in product surfaces.',
                type: PlatformSettingDefinition::TYPE_EMAIL,
                default: static fn (): ?string => config('branding.support_email'),
                rules: ['nullable', 'email:rfc', 'max:255'],
            ),
            new PlatformSettingDefinition(
                key: 'branding.support_url',
                group: 'branding',
                label: 'Support URL',
                help: 'Link to the support or help center.',
                type: PlatformSettingDefinition::TYPE_URL,
                default: static fn (): string => (string) config('branding.support_url', ''),
                rules: ['nullable', 'string', 'max:2048'],
            ),
            new PlatformSettingDefinition(
                key: 'branding.status_page_url',
                group: 'branding',
                label: 'Status page URL',
                help: 'Link to the public status page.',
                type: PlatformSettingDefinition::TYPE_URL,
                default: static fn (): string => (string) config('branding.status_page_url', ''),
                rules: ['nullable', 'string', 'max:2048'],
            ),
        ];
    }
}
