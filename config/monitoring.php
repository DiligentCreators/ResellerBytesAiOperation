<?php

return [
    'heartbeat_stale_minutes' => (int) env('MONITORING_HEARTBEAT_STALE_MINUTES', 30),
    'auto_collect_interval_minutes' => (int) env('MONITORING_AUTO_COLLECT_INTERVAL_MINUTES', 60),
    'max_snapshots_per_install_per_day' => (int) env('MONITORING_MAX_SNAPSHOTS_PER_INSTALL_PER_DAY', 48),
    'addon_latest_version' => env('MONITORING_ADDON_LATEST_VERSION', env('WHMCS_ADDON_VERSION', '1.1.0')),
];
