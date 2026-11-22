<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Workspace alert repeat window
    |--------------------------------------------------------------------------
    |
    | Same dedupe_key is not re-sent to a channel within this many minutes.
    | Overridable via platform setting alerts.repeat_minutes.
    |
    */
    'repeat_minutes' => (int) env('ALERTS_REPEAT_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | HMAC signature header for generic webhook channels
    |--------------------------------------------------------------------------
    */
    'hmac_header' => 'X-ResellerBytes-Signature',

    /*
    |--------------------------------------------------------------------------
    | Known alert event keys channels may subscribe to
    |--------------------------------------------------------------------------
    */
    'event_keys' => [
        'all_critical',
        'installation.heartbeat.down',
        'installation.heartbeat.recovery',
        'finding.critical',
        'fix.failed',
        'fix.auto_queued',
        'alert.test',
    ],
];
