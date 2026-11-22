<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Finding severity weights
    |--------------------------------------------------------------------------
    |
    | Open findings reduce the health score by weight * log2(1 + affected_count).
    | Adjust freely — stored snapshots keep the score computed at capture time.
    |
    */
    'weights' => [
        'critical' => 25,
        'warning' => 8,
        'info' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Heartbeat cap
    |--------------------------------------------------------------------------
    |
    | When an installation heartbeat is down, the score is capped at this value.
    |
    */
    'heartbeat_down_cap' => 40,

    /*
    |--------------------------------------------------------------------------
    | Trend history defaults (days)
    |--------------------------------------------------------------------------
    |
    | Used when a workspace has no subscription limit for history_days.
    | Plan catalog defaults: Starter 90, Growth/Agency/Enterprise 365.
    |
    */
    'history_days' => [
        'default' => 90,
        'by_plan' => [
            'starter' => 90,
            'growth' => 365,
            'business' => 365,
            'enterprise' => 365,
        ],
    ],

];
