<?php

return [
    'enabled' => (bool) env('FIXES_ENABLED', true),
    'bulk_max_items' => (int) env('FIXES_BULK_MAX_ITEMS', 50),
    'auto_fix_daily_cap' => (int) env('FIXES_AUTO_FIX_DAILY_CAP', 20),
];
