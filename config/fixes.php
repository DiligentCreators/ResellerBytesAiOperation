<?php

return [
    'enabled' => (bool) env('FIXES_ENABLED', true),
    'bulk_max_items' => (int) env('FIXES_BULK_MAX_ITEMS', 50),
    'auto_fix_daily_cap' => (int) env('FIXES_AUTO_FIX_DAILY_CAP', 20),
    'overdue_reminder_messagename' => 'First Invoice Overdue Notice',
    /**
     * Optional per-issue overrides for minutes_saved (issue_key => minutes).
     * Empty by default; platform admins may set via Platform Settings.
     *
     * @var array<string, int>
     */
    'minutes_saved_overrides' => [],
];
