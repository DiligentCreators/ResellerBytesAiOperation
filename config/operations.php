<?php

return [
    // The sole legal-hold authority. Configure this to the verified operations account email.
    'owner_email' => env('OPERATIONS_OWNER_EMAIL'),

    'monitoring' => [
        'error_rate' => ['window_minutes' => (int) env('OPERATIONS_ALERT_ERROR_RATE_WINDOW_MINUTES', 5), 'threshold_percent' => (float) env('OPERATIONS_ALERT_ERROR_RATE_PERCENT', 2)],
        'api_p95_latency_ms' => (int) env('OPERATIONS_ALERT_API_P95_LATENCY_MS', 1500),
        'backup_max_age_hours' => (int) env('OPERATIONS_ALERT_BACKUP_MAX_AGE_HOURS', 26),
        'disk' => ['warning_percent' => (int) env('OPERATIONS_ALERT_DISK_WARNING_PERCENT', 80), 'critical_percent' => (int) env('OPERATIONS_ALERT_DISK_CRITICAL_PERCENT', 90)],
        'queue_job_max_age_minutes' => (int) env('OPERATIONS_ALERT_QUEUE_JOB_MAX_AGE_MINUTES', 15),
    ],

    'metrics' => [
        'disk_path' => env('OPERATIONS_METRICS_DISK_PATH', storage_path()),
        // The backup agent must atomically update this marker only after a verified successful backup.
        'backup_success_marker_path' => env('OPERATIONS_BACKUP_SUCCESS_MARKER_PATH'),
    ],

    'alerts' => [
        'enabled' => (bool) env('OPERATIONS_ALERTS_ENABLED', false),
        'repeat_interval_minutes' => (int) env('OPERATIONS_ALERT_REPEAT_INTERVAL_MINUTES', 15),
        'email' => env('OPERATIONS_ALERT_EMAIL'),
        'slack_webhook_url' => env('OPERATIONS_ALERT_SLACK_WEBHOOK_URL'),
        'discord_webhook_url' => env('OPERATIONS_ALERT_DISCORD_WEBHOOK_URL'),
        'critical_whatsapp_webhook_url' => env('OPERATIONS_ALERT_CRITICAL_WHATSAPP_WEBHOOK_URL'),
        'critical_sms_webhook_url' => env('OPERATIONS_ALERT_CRITICAL_SMS_WEBHOOK_URL'),
    ],

    'backups' => [
        'enabled' => (bool) env('OPERATIONS_BACKUPS_ENABLED', false),
        'schedule_time' => env('OPERATIONS_BACKUP_SCHEDULE_TIME', '00:00'),
        'object_lock_retention_days' => (int) env('BACKUP_OBJECT_LOCK_RETENTION_DAYS', 0),
        'dump_username' => env('BACKUP_DB_USERNAME', env('DB_READONLY_ROLE', 'rb_readonly')),
        'dump_password' => env('BACKUP_DB_PASSWORD'),
        'restore_username' => env('RESTORE_DB_USERNAME', env('DB_MIGRATION_ROLE', 'rb_migrator')),
        'restore_password' => env('RESTORE_DB_PASSWORD'),
        'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),
        'aws_cli_binary' => env('BACKUP_AWS_CLI_BINARY', 'aws'),
        'pg_dump_binary' => env('BACKUP_PG_DUMP_BINARY', 'pg_dump'),
        'pg_restore_binary' => env('BACKUP_PG_RESTORE_BINARY', 'pg_restore'),
    ],

    'recovery' => [
        'rpo_hours' => (int) env('RECOVERY_POINT_OBJECTIVE_HOURS', 24),
        'rto_hours' => (int) env('RECOVERY_TIME_OBJECTIVE_HOURS', 4),
        'production_restore_requires_owner_approval' => (bool) env('PRODUCTION_RESTORE_REQUIRES_OWNER_APPROVAL', true),
    ],

    'audit' => [
        'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 365),
        'object_lock_mode' => env('AUDIT_OBJECT_LOCK_MODE', 'COMPLIANCE'),
    ],

    'signing' => [
        'initial_operator' => env('AUDIT_SIGNING_OPERATOR', 'owner'),
        'allow_multiple_operators' => false,
        'supports_future_additional_operator' => true,
    ],

    'database_roles' => [
        'runtime' => env('DB_RUNTIME_ROLE', 'rb_runtime'),
        'migration' => env('DB_MIGRATION_ROLE', 'rb_migrator'),
        'read_only' => env('DB_READONLY_ROLE', 'rb_readonly'),
    ],
];
