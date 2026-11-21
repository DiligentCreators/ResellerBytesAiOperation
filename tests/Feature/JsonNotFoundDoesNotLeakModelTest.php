<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('does not leak model class names in JSON 404 responses when APP_DEBUG is false', function () {
    config()->set('app.debug', false);

    $user = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $foreign = Tenant::create([
        'name' => 'Foreign JSON 404',
        'slug' => 'foreign-json-404-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $foreign->memberships()->create([
        'user_id' => User::factory()->create()->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('tenants.memberships.index', $foreign));

    $response->assertNotFound();
    $payload = $response->json();
    $encoded = json_encode($payload);

    expect($encoded)->not->toContain('App\\Models\\Tenant')
        ->and($encoded)->not->toContain('TenantMembership')
        ->and($encoded)->not->toContain('No query results for model')
        ->and($payload['message'] ?? null)->toBe('Not Found.');
});

it('still raises ModelNotFoundException with model metadata for internal callers', function () {
    $user = User::factory()->create();

    expect(fn () => app(TenantAccess::class)->requireMembership($user, (string) str()->uuid()))
        ->toThrow(ModelNotFoundException::class);
});
