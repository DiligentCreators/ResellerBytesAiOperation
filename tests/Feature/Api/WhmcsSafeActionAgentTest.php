<?php

use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;

function makeSignedWhmcsRequest(
    WhmcsIntegration $integration,
    string $method,
    string $path,
    ?array $payload = null,
    ?string $nonce = null,
    ?int $timestamp = null,
): array {
    $body = $payload === null
        ? ($method === 'GET' ? '' : '{}')
        : json_encode($payload, JSON_THROW_ON_ERROR);

    $timestamp ??= now()->timestamp;
    $nonce ??= Str::random(64);
    $canonical = implode(chr(10), [
        strtoupper($method),
        $path,
        (string) $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);

    return [
        'body' => $body,
        'headers' => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RB_INTEGRATION' => $integration->id,
            'HTTP_X_RB_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_RB_NONCE' => $nonce,
            'HTTP_X_RB_SIGNATURE' => hash_hmac('sha256', $canonical, $integration->secret),
        ],
    ];
}

function createSafeActionAgentWorkspace(): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Agent Workspace',
        'slug' => 'agent-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'status' => 'active',
        'plan_code' => 'growth',
        'billing_mode' => 'manual',
        'currency' => 'USD',
        'unit_amount_minor' => 14900,
        'entitlements' => [
            'diagnostics.analysis' => true,
            'safe_actions.enabled' => true,
            'limits' => ['integrations' => 5, 'members' => 10, 'diagnostics_per_period' => 100, 'retention_days' => 30],
        ],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Agent WHMCS',
        'status' => 'active',
        'secret' => 'integration-secret-for-agent-tests',
        'secret_version' => 1,
        'host_connection' => [
            'protocol' => 'ftp',
            'host' => 'ftp.example.test',
            'port' => 21,
            'username' => 'ops',
            'password' => 'secret-pass',
            'remote_whmcs_root' => '/home/ops/public_html',
        ],
    ]);

    return compact('owner', 'tenant', 'integration');
}

it('lists awaiting agent safe actions for a signed integration', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionAgentWorkspace();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $integration->id,
            'action_key' => 'harden_configuration_php_mode',
        ]);

    $execution = SafeActionExecution::query()->firstOrFail();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Harden configuration.php for pilot',
        ])
        ->assertRedirect();

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_AWAITING_AGENT);

    $signed = makeSignedWhmcsRequest($integration, 'GET', '/api/v1/whmcs/safe-actions/pending');

    $this->call('GET', '/api/v1/whmcs/safe-actions/pending', [], [], [], $signed['headers'], $signed['body'])
        ->assertOk()
        ->assertJsonPath('data.host_credentials_configured', true)
        ->assertJsonPath('data.actions.0.id', $execution->id)
        ->assertJsonPath('data.actions.0.action_key', 'harden_configuration_php_mode')
        ->assertJsonPath('data.actions.0.host.host', 'ftp.example.test');
});

it('records addon success results and verifies the execution', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionAgentWorkspace();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $integration->id,
            'action_key' => 'disable_php_display_errors',
        ]);
    $execution = SafeActionExecution::query()->firstOrFail();
    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Turn off display_errors',
        ]);

    $path = '/api/v1/whmcs/safe-actions/'.$execution->id.'/result';
    $signed = makeSignedWhmcsRequest($integration, 'POST', $path, [
        'status' => 'success',
        'summary' => 'Wrote .user.ini display_errors=Off',
        'evidence' => [
            'remediation_applied' => true,
            'error_display_disabled' => true,
        ],
    ]);

    $this->call('POST', $path, [], [], [], $signed['headers'], $signed['body'])
        ->assertOk()
        ->assertJsonPath('data.status', SafeActionExecution::STATUS_VERIFIED);

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_VERIFIED);
});

it('rejects addon results for a different integration', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionAgentWorkspace();
    $other = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $integration->tenant_id,
        'name' => 'Other WHMCS',
        'status' => 'active',
        'secret' => 'other-integration-secret-for-tests',
        'secret_version' => 1,
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $integration->id,
            'action_key' => 'harden_configuration_php_mode',
        ]);
    $execution = SafeActionExecution::query()->firstOrFail();
    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Harden permissions',
        ]);

    $path = '/api/v1/whmcs/safe-actions/'.$execution->id.'/result';
    $signed = makeSignedWhmcsRequest($other, 'POST', $path, [
        'status' => 'success',
        'summary' => 'Should not apply',
        'evidence' => ['remediation_applied' => true],
    ]);

    $this->call('POST', $path, [], [], [], $signed['headers'], $signed['body'])
        ->assertNotFound();
});
