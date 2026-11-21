<?php

return [
    // ISO-8601 day of week: 1 (Monday) through 7 (Sunday).
    'weekly_day' => (int) env('REPORTS_WEEKLY_DAY', 1),
    'weekly_time' => env('REPORTS_WEEKLY_TIME', '09:00'),
    'value_report_days_before_renewal' => (int) env('REPORTS_VALUE_REPORT_DAYS_BEFORE_RENEWAL', 3),
    // Platform default for value reports; workspaces may override later.
    'default_hourly_rate_usd' => (int) env('REPORTS_DEFAULT_HOURLY_RATE_USD', 25),
];
