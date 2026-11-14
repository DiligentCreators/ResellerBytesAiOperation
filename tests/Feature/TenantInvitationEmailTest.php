<?php

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Jobs\SendTenantMembershipInvitation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Mail\PostmarkTransactionalMailer;
use App\Services\Tenancy\TenantMembershipLifecycle;
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

it('sends a Postmark transactional message with a signed, account-bound invitation link', function () {
    [$tenant, $admin, $invitee] = tenantInvitationEmailFixture();
    $membership = app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_OPERATOR,
    );

    config([
        'tenant_invitations.driver' => 'postmark',
        'services.postmark.server_token' => 'test-server-token',
        'services.postmark.message_stream' => 'outbound',
        'mail.from.address' => 'no-reply@example.test',
        'mail.from.name' => 'ResellerBytes',
    ]);
    Http::fake(['https://api.postmarkapp.com/email' => Http::response(['MessageID' => 'postmark-message-id'], 200)]);

    app(PostmarkTransactionalMailer::class)->sendTenantInvitation($membership->fresh());

    Http::assertSent(function ($request) use ($invitee, $tenant, $membership): bool {
        return $request->url() === 'https://api.postmarkapp.com/email'
            && $request->hasHeader('X-Postmark-Server-Token', 'test-server-token')
            && $request['To'] === $invitee->email
            && $request['MessageStream'] === 'outbound'
            && str_contains($request['HtmlBody'], 'Review invitation')
            && str_contains($request['HtmlBody'], 'signature=')
            && str_contains($request['HtmlBody'], $tenant->name)
            && $request['Metadata']['membership_id'] === $membership->id;
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

    config(['tenant_invitations.driver' => 'log']);
    Http::preventStrayRequests();
    Log::shouldReceive('info')
        ->once()
        ->with('tenant_invitation.mail_preview', Mockery::on(fn (array $context): bool => $context['tenant_id'] === $tenant->id
            && ! array_key_exists('recipient', $context)
            && ! array_key_exists('invitation_url', $context)));

    app(PostmarkTransactionalMailer::class)->sendTenantInvitation($membership->fresh());
});

it('rejects Postmark delivery when required secret or sender configuration is absent', function () {
    [$tenant, $admin, $invitee] = tenantInvitationEmailFixture();
    $membership = app(TenantMembershipLifecycle::class)->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_VIEWER,
    );

    config([
        'tenant_invitations.driver' => 'postmark',
        'services.postmark.server_token' => '',
        'mail.from.address' => 'no-reply@example.test',
    ]);
    Http::preventStrayRequests();

    expect(fn () => app(PostmarkTransactionalMailer::class)->sendTenantInvitation($membership->fresh()))
        ->toThrow(RuntimeException::class, 'tenant_invitation_postmark_configuration_incomplete');
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

    config(['tenant_invitations.driver' => 'postmark']);
    Http::preventStrayRequests();

    app(SendTenantMembershipInvitation::class, [
        'tenantId' => $tenant->id,
        'membershipId' => $membership->id,
        'inviteeUserId' => $invitee->id,
    ])->handle(app(\App\Services\Tenancy\TenantDatabaseContext::class), app(PostmarkTransactionalMailer::class));

    Http::assertNothingSent();
});
