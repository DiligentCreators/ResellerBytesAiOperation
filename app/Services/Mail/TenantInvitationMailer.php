<?php

namespace App\Services\Mail;

use App\Models\TenantMembership;
use Illuminate\Support\Facades\URL;

class TenantInvitationMailer
{
    public function __construct(private readonly CentralOutboundMailer $mailer) {}

    public function sendTenantInvitation(TenantMembership $membership): void
    {
        $membership->loadMissing(['tenant', 'user']);
        $recipient = (string) $membership->user->email;
        $tenantName = (string) $membership->tenant->name;
        $role = (string) $membership->role;
        $invitationUrl = URL::temporarySignedRoute(
            'tenants.memberships.invitation.show',
            now()->addDays(7),
            ['tenantId' => $membership->tenant_id, 'membershipId' => $membership->id],
        );
        $subject = 'You have been invited to '.$tenantName;
        $html = view('emails.tenant-membership-invitation', [
            'tenantName' => $tenantName, 'role' => $role, 'invitationUrl' => $invitationUrl,
        ])->render();
        $text = "You have been invited to join {$tenantName} as {$role}.\n\nSign in with {$recipient} and accept your invitation here: {$invitationUrl}\n\nThis invitation link expires in 7 days.";

        $this->mailer->send($recipient, $subject, $html, $text);
    }
}
