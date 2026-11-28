<?php

use App\Support\WhmcsAddonVersion;

return [
    'heartbeat_stale_minutes' => (int) env('MONITORING_HEARTBEAT_STALE_MINUTES', 30),
    'auto_collect_interval_minutes' => (int) env('MONITORING_AUTO_COLLECT_INTERVAL_MINUTES', 60),
    'max_snapshots_per_install_per_day' => (int) env('MONITORING_MAX_SNAPSHOTS_PER_INSTALL_PER_DAY', 48),
    'addon_latest_version' => env('MONITORING_ADDON_LATEST_VERSION') ?: env('WHMCS_ADDON_VERSION') ?: WhmcsAddonVersion::packaged(),
    'cron_stale_minutes' => (int) env('MONITORING_CRON_STALE_MINUTES', 30),
    'daily_cron_missed_hours' => (int) env('MONITORING_DAILY_CRON_MISSED_HOURS', 36),
    'ticket_sla_hours' => (int) env('MONITORING_TICKET_SLA_HOURS', 24),
    'disk_free_warning_percent' => (float) env('MONITORING_DISK_FREE_WARNING_PERCENT', 15),
    'db_size_warning_mb' => (float) env('MONITORING_DB_SIZE_WARNING_MB', 5120),
    'module_queue_stuck_minutes' => (int) env('MONITORING_MODULE_QUEUE_STUCK_MINUTES', 60),
    'gateway_failure_threshold_7d' => (int) env('MONITORING_GATEWAY_FAILURE_THRESHOLD_7D', 3),
    'domain_sync_error_threshold_7d' => (int) env('MONITORING_DOMAIN_SYNC_ERROR_THRESHOLD_7D', 1),
    'mail_piping_failure_threshold_7d' => (int) env('MONITORING_MAIL_PIPING_FAILURE_THRESHOLD_7D', 1),
    'mail_sending_failure_threshold_24h' => (int) env('MONITORING_MAIL_SENDING_FAILURE_THRESHOLD_24H', 1),
    'cron_overlap_start_excess' => (int) env('MONITORING_CRON_OVERLAP_START_EXCESS', 2),

    /*
     * PHP support matrix keyed by WHMCS major.minor => [min_php, max_php] (inclusive majors.minor).
     * Easy to update when WHMCS publishes new runtime requirements.
     */
    'php_support_matrix' => [
        '8.13' => ['min' => '8.1.0', 'max' => '8.3.99'],
        '9.0' => ['min' => '8.2.0', 'max' => '8.4.99'],
        '9.1' => ['min' => '8.2.0', 'max' => '8.4.99'],
    ],

    /*
     * PHP major.minor branches considered end-of-life for php.version_eol.
     * Overridable via the platform settings registry.
     */
    'php_eol_branches' => [
        '7.4',
        '8.0',
        '8.1',
    ],

    /*
     * Latest security baseline patch per WHMCS major.minor line.
     */
    'whmcs_security_baselines' => [
        '8.13' => '8.13.7',
        '9.0' => '9.0.8',
    ],

    /*
     * Minimum ionCube Loader major version required for a WHMCS major.minor line.
     */
    'ioncube_minimum_versions' => [
        '8.13' => 13,
        '9.0' => 14,
        '9.1' => 15,
    ],

    /*
     * Required PHP extensions that must report true when present in the snapshot.
     */
    'required_php_extensions' => [
        'curl',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'pdo_mysql',
        'xml',
        'gd',
        'iconv',
        'zip',
        'zlib',
        'fileinfo',
        'ioncube_loader',
    ],
];
