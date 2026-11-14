<?php

namespace App\Services\Mail;

use App\Models\TenantMembership;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use RuntimeException;

class PostmarkTransactionalMailer
{
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
            'tenantName' => $tenantName,
            'role' => $role,
            'invitationUrl' => $invitationUrl,
        ])->render();
        $text = "You have been invited to join {$tenantName} as {$role}.\n\nSign in with {$recipient} and accept your invitation here: {$invitationUrl}\n\nThis invitation link expires in 7 days.";

        if (config('tenant_invitations.driver', 'log') === 'log') {
            Log::info('tenant_invitation.mail_preview', [
                'membership_id' => (string) $membership->id,
                'tenant_id' => (string) $membership->tenant_id,
                'role' => $role,
                'subject' => $subject,
            ]);

            return;
        }

        if (config('tenant_invitations.driver') !== 'postmark') {
            throw new InvalidArgumentException('tenant_invitation_mailer_driver_invalid');
        }

        $token = (string) config('services.postmark.server_token');
        $fromAddress = (string) config('mail.from.address');
        $fromName = (string) config('mail.from.name');
        $messageStream = (string) config('services.postmark.message_stream', 'outbound');

        if ($token === '' || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false || $messageStream === '') {
            throw new RuntimeException('tenant_invitation_postmark_configuration_incomplete');
        }

        $safeFromName = str_replace(["\r", "\n"], '', $fromName);
        $response = Http::acceptJson()
            ->withHeaders(['X-Postmark-Server-Token' => $token])
            ->connectTimeout(3)
            ->timeout(10)
            ->withOptions(['allow_redirects' => false])
            ->post('https://api.postmarkapp.com/email', [
                'From' => "{$safeFromName} <{$fromAddress}>",
                'To' => $recipient,
                'Subject' => $subject,
                'HtmlBody' => $html,
                'TextBody' => $text,
                'MessageStream' => $messageStream,
                'Metadata' => [
                    'tenant_id' => (string) $membership->tenant_id,
                    'membership_id' => (string) $membership->id,
                ],
            ]);

        $response->throw();
    }
}
