<?php

namespace App\Support;

use App\Services\PlatformSettings;

/**
 * Single place that decides whether public self-serve registration is allowed.
 *
 * Platform setting security.registration_open (default true) gates Fortify
 * register GET/POST and CreateNewUser. Invites, ops:user:create, login, and
 * password reset are unaffected.
 */
final class RegistrationPolicy
{
    public const CLOSED_MESSAGE = 'Public sign-up is currently closed. If you were invited, use the link in your invitation email.';

    public function __construct(private readonly PlatformSettings $settings) {}

    public function isOpen(): bool
    {
        return (bool) $this->settings->get('security.registration_open');
    }
}
