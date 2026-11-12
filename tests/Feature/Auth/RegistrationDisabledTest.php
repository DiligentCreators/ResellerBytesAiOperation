<?php

it('does not expose public account registration routes', function () {
    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Uninvited User',
        'email' => 'uninvited@example.test',
        'password' => 'A-Very-Strong-Password-123!',
        'password_confirmation' => 'A-Very-Strong-Password-123!',
    ])->assertNotFound();
});
