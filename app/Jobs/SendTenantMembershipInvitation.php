<?php

namespace App\Jobs;

use App\Models\TenantMembership;
use App\Services\Mail\TenantInvitationMailer;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTenantMembershipInvitation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 20;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $membershipId,
        public readonly int $inviteeUserId,
    ) {
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(TenantDatabaseContext $databaseContext, TenantInvitationMailer $mailer): void
    {
        $databaseContext->run($this->tenantId, $this->inviteeUserId, function () use ($mailer): void {
            $membership = TenantMembership::query()
                ->with(['tenant', 'user'])
                ->where('tenant_id', $this->tenantId)
                ->where('user_id', $this->inviteeUserId)
                ->whereKey($this->membershipId)
                ->first();

            if (! $membership || $membership->status !== TenantMembership::STATUS_INVITED) {
                return;
            }

            $mailer->sendTenantInvitation($membership);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('tenant_invitation.delivery_failed', [
            'tenant_id' => $this->tenantId,
            'membership_id' => $this->membershipId,
            'exception' => $exception::class,
        ]);
    }
}
