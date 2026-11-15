<?php

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Jobs\SendTenantMembershipInvitation;
use App\Models\PlatformMailSettings;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Mail\TenantInvitationMailer;
use App\Services\Tenancy\TenantMembershipLifecycle;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

function tenantInvitationEmailFixture(): array
{
    $tenant = Tenant::create([
        'name' => 'Invitation Mail Workspace',
        'slug' => 'invitation-mail-workspace',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $admin = User::factory()->create();
    $invitee = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $admin->id,
        'role' => TenantMembership::ROLE_ADMIN,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'invitation-test-plan',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => ['limits' => ['members' => 10]],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    return [$tenant, $admin, $invitee];
}

it('queues invitation delivery only after the membership transaction commits', function () {
    [$tenant, $admin, $invitee] = tenantInvitationEmailFixture();
    Bus::fake();

    $membership = app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_VIEWER,
    );

    Bus::assertDispatched(SendTenantMembershipInvitation::class, fn (SendTenantMembershipInvitation $job): bool => $job->tenantId === $tenant->id
        && $job->membershipId === $membership->id
        && $job->inviteeUserId === $invitee->id);
});

it('sends an invitation through the centrally configured Postmark transport with a signed account-bound link', function () {
    [$tenant, $admin, $invitee] = tenantInvitationEmailFixture();
    $membership = app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_OPERATOR,
    );

    PlatformMailSettings::query()->create([
        'id' => 1,
        'driver' => 'postmark',
        'configuration' => [
            'server_token' => 'test-server-token',
            'message_stream' => 'outbound',
            'from_address' => 'no-reply@example.test',
            'from_name' => 'ResellerBytes',
        ],
    ]);
    Http::swap(new Factory());
    Http::fake(['https://api.postmarkapp.com/email' => Http::response(['MessageID' => 'postmark-message-id'], 200)]);

    app(TenantInvitationMailer::class)->sendTenantInvitation($membership->fresh());

    // Separate assertions keep transport routing, auth-header presence, and payload failures diagnosable.
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.postmarkapp.com/email');
    Http::assertSent(fn ($request) => $request->hasHeader('X-Postmark-Server-Token'));
    Http::assertSent(function ($request) use ($invitee, $tenant): bool {
        return $request['To'] === $invitee->email
            && $request['MessageStream'] === 'outbound'
            && str_contains($request['HtmlBody'], 'Review invitation')
            && str_contains($request['HtmlBody'], 'signature=')
            && str_contains($request['HtmlBody'], $tenant->name);
    });
});

it('uses a local log preview without making an external request or logging private invitation data', function () {
    [$tenant, $admin, $invitee] = tenantInvitationEmailFixture();
    $membership = app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_VIEWER,
    );

    PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'log', 'configuration' => []]);
    Http::preventStrayRequests();
    Log::shouldReceive('info')
        ->once()
        ->with('outbound_mail.preview', ['driver' => 'log']);

    app(TenantInvitationMailer::class)->sendTenantInvitation($membership->fresh());
});

it('rejects Postmark delivery when required secret or sender configuration is absent', function () {
    [$tenant, $admin, $invitee] = tenantInvitationEmailFixture();
    $membership = app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_VIEWER,
    );

    PlatformMailSettings::query()->create([
        'id' => 1,
        'driver' => 'postmark',
        'configuration' => [
            'message_stream' => 'outbound',
            'from_address' => 'no-reply@example.test',
            'from_name' => 'ResellerBytes',
        ],
    ]);
    config(['platform_mail.postmark.server_token' => '']);
    Http::preventStrayRequests();

    expect(fn () => app(TenantInvitationMailer::class)->sendTenantInvitation($membership->fresh()))
        ->toThrow(RuntimeException::class, 'platform_mail_postmark_configuration_invalid');
});

it('only shows a pending invitation to its intended signed-in account', function () {
    $this->withoutMiddleware(EnsureTwoFactorEnabled::class);
    [$tenant, $admin, $invitee] = tenantInvitationEmailFixture();
    $membership = app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_VIEWER,
    );

    $url = URL::temporarySignedRoute(
        'tenants.memberships.invitation.show',
        now()->addDay(),
        ['tenantId' => $tenant->id, 'membershipId' => $membership->id],
    );

    $this->actingAs($invitee)->get($url)->assertOk()->assertSee($tenant->name)->assertSee($invitee->email);
    $this->actingAs($admin)->get($url)->assertNotFound();
});

it('rejects an expired invitation link and does not auto-accept on GET', function () {
    $this->withoutMiddleware(EnsureTwoFactorEnabled::class);
    [$tenant, $admin, $invitee] = tenantInvitationEmailFixture();
    $membership = app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_VIEWER,
    );

    $url = URL::temporarySignedRoute(
        'tenants.memberships.invitation.show',
        now()->subMinute(),
        ['tenantId' => $tenant->id, 'membershipId' => $membership->id],
    );

    $this->actingAs($invitee)->get($url)->assertForbidden();
    expect($membership->fresh()->status)->toBe(TenantMembership::STATUS_INVITED);
});

it('does not send an invitation after the membership has been revoked', function () {
    [$tenant, $admin, $invitee] = tenantInvitationEmailFixture();
    $membership = app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_VIEWER,
    );
    app(TenantMembershipLifecycle::class)->revoke($admin, $tenant->id, $membership->id);

    PlatformMailSettings::query()->create([
        'id' => 1,
        'driver' => 'postmark',
        'configuration' => [
            'server_token' => 'unused-token',
            'message_stream' => 'outbound',
            'from_address' => 'no-reply@example.test',
            'from_name' => 'ResellerBytes',
        ],
    ]);
    Http::preventStrayRequests();

    app(SendTenantMembershipInvitation::class, [
        'tenantId' => $tenant->id,
        'membershipId' => $membership->id,
        'inviteeUserId' => $invitee->id,
    ])->handle(app(\App\Services\Tenancy\TenantDatabaseContext::class), app(TenantInvitationMailer::class));

    Http::assertNothingSent();
});
