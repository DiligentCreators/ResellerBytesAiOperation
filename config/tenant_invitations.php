<?php

return [
    // Local development writes a redacted preview to the application log.
    // Production delivery must be explicitly enabled with the Postmark driver.
    'driver' => env('TENANT_INVITATION_MAILER', 'log'),
];
