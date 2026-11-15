<?php

return [
    // Cleanup is opt-in per deployment and still requires an explicit --execute flag.
    'retention_cleanup_enabled' => (bool) env('DIAGNOSTICS_RETENTION_CLEANUP_ENABLED', false),
];
