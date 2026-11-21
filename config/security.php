<?php

return [
    // Platform-wide gate for forced 2FA enrollment (operators + tenant require_two_factor). Default off.
    'two_factor_required' => (bool) env('SECURITY_TWO_FACTOR_REQUIRED', false),

    // When false, public account registration routes should refuse new sign-ups (wired later).
    'registration_open' => (bool) env('SECURITY_REGISTRATION_OPEN', true),
];
