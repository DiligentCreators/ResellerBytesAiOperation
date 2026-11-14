<?php

return [
    // The sole legal-hold authority. Configure this to the verified operations account email.
    'owner_email' => env('OPERATIONS_OWNER_EMAIL'),

    'monitoring' => [
        'error_rate' => ['window_minutes' => 5, 'threshold_percent' => 2],
        'api_p95_latency_ms' => 1500,
        'backup_max_age_hours' => 26,
        'disk' => ['warning_percent' => 80, 'critical_percent' => 90],
        'queue_job_max_age_minutes' => 15,
    ],

    'alerts' => [
        'email' => env('OPERATIONS_ALERT_EMAIL'),
        'slack_webhook_url' => env('OPERATIONS_ALERT_SLACK_WEBHOOK_URL'),
        'discord_webhook_url' => env('OPERATIONS_ALERT_DISCORD_WEBHOOK_URL'),
        'critical_whatsapp_webhook_url' => env('OPERATIONS_ALERT_CRITICAL_WHATSAPP_WEBHOOK_URL'),
        'critical_sms_webhook_url' => env('OPERATIONS_ALERT_CRITICAL_SMS_WEBHOOK_URL'),
    ],

    'recovery' => [
        'rpo_hours' => 24,
        'rto_hours' => 4,
        'production_restore_requires_owner_approval' => true,
    ],

    'audit' => [
        'retention_days' => 365,
        'object_lock_mode' => 'COMPLIANCE',
    ],

    'signing' => [
        'initial_operator' => env('AUDIT_SIGNING_OPERATOR', 'owner'),
        'allow_multiple_operators' => true,
    ],

    'database_roles' => [
        'runtime' => env('DB_RUNTIME_ROLE', 'rb_runtime'),
        'migration' => env('DB_MIGRATION_ROLE', 'rb_migrator'),
        'read_only' => env('DB_READONLY_ROLE', 'rb_readonly'),
    ],
];
