<?php

return [
    // Platform-wide 2FA requirement for all users. Enforcement is wired in a later change.
    'two_factor_required' => (bool) env('SECURITY_TWO_FACTOR_REQUIRED', false),

    // When false, public account registration routes should refuse new sign-ups (wired later).
    'registration_open' => (bool) env('SECURITY_REGISTRATION_OPEN', true),
];
