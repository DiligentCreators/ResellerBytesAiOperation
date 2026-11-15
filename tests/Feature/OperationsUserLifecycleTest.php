<?php

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates a verified operator from hidden strong-password prompts and audits the lifecycle event', function () {
    $password = 'Strong-Operator-Password-123!';

    $this->artisan('ops:user:create', ['name' => 'Operations Owner', 'email' => 'OWNER@example.test'])
        ->expectsQuestion('Password (minimum 14 characters)', $password)
        ->expectsQuestion('Confirm password', $password)
        ->expectsOutputToContain('Operations user created and email marked as verified.')
        ->assertSuccessful();

    $user = User::query()->where('email', 'owner@example.test')->firstOrFail();

    expect($user->name)->toBe('Operations Owner')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->password)->not->toBe($password)
        ->and(AuditEvent::query()->where('event_type', 'operations_user.created')->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'operations_user.created')->first()->metadata)->toBe([]);
});

it('rejects mismatched operator password confirmation without creating an account', function () {
    $this->artisan('ops:user:create', ['name' => 'Operations Owner', 'email' => 'owner@example.test'])
        ->expectsQuestion('Password (minimum 14 characters)', 'Strong-Operator-Password-123!')
        ->expectsQuestion('Confirm password', 'Different-Password-123!')
        ->expectsOutputToContain('The password confirmation did not match.')
        ->assertFailed();

    expect(User::query()->where('email', 'owner@example.test')->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', 'operations_user.created')->exists())->toBeFalse();
});

it('rejects weak operator passwords before creating an account', function () {
    $this->artisan('ops:user:create', ['name' => 'Operations Owner', 'email' => 'owner@example.test'])
        ->expectsQuestion('Password (minimum 14 characters)', 'weak-password')
        ->expectsQuestion('Confirm password', 'weak-password')
        ->expectsOutputToContain('The password does not meet the required strength policy.')
        ->assertFailed();

    expect(User::query()->where('email', 'owner@example.test')->exists())->toBeFalse();
});

it('rejects duplicate operator emails before prompting for credentials', function () {
    User::factory()->create(['email' => 'owner@example.test']);

    $this->artisan('ops:user:create', ['name' => 'Duplicate Owner', 'email' => 'OWNER@example.test'])
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();
});
