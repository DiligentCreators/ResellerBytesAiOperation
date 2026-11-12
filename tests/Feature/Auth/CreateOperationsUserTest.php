<?php

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a verified operations user through a hidden password prompt', function () {
    $password = 'Secure-Operations-Password-123!';

    $this->artisan('ops:user:create', [
        'name' => 'Operations Admin',
        'email' => 'operations@example.test',
    ])
        ->expectsQuestion('Password (minimum 14 characters)', $password)
        ->expectsQuestion('Confirm password', $password)
        ->assertSuccessful();

    $user = User::query()->where('email', 'operations@example.test')->sole();

    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and(AuditEvent::where('event_type', 'operations_user.created')->count())->toBe(1);
});
