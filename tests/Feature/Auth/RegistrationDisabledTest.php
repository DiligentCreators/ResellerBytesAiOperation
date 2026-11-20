<?php

/**
 * Registration is enabled for public SaaS launch.
 * Kept as an intentional no-op so historical references remain discoverable.
 */
it('exposes public account registration routes', function () {
    $this->get('/register')->assertOk();
});
